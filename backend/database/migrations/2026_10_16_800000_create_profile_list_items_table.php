<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phase 11d-1: admin-editable Machine and Skill lists.
 *
 * Until now users.machines / users.skills held free text (["STOLL", "Stoll CMS"]). This migration:
 *  1. creates `profile_list_items` (type machine|skill, stable key, name_bn, name_en, is_active, sort_order);
 *  2. turns every distinct text already saved into one list item (same name in another case / spacing = one item,
 *     the most used spelling becomes the name; bn name = the same text until an admin translates it);
 *  3. replaces each saved text with its item key — the columns stay JSON lists, only the values change;
 *  4. keeps every account's exact old values in `profile_list_migration_backups`, so `down()` puts them back.
 *
 * A value that cannot be mapped (empty or not text) is left in place untouched and reported in the log
 * (storage/logs) — nothing is dropped.
 */
return new class extends Migration
{
    private const COLUMNS = ['machine' => 'machines', 'skill' => 'skills'];

    public function up(): void
    {
        Schema::create('profile_list_items', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('key', 100);
            $table->string('name_bn', 100);
            $table->string('name_en', 100);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['type', 'key']);
            $table->index(['type', 'is_active', 'sort_order']);
        });

        Schema::create('profile_list_migration_backups', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->json('machines_before')->nullable();
            $table->json('skills_before')->nullable();
            $table->json('machines_after')->nullable();
            $table->json('skills_after')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $users = DB::table('users')
            ->where(fn ($q) => $q->whereNotNull('machines')->orWhereNotNull('skills'))
            ->orderBy('id')
            ->get(['id', 'machines', 'skills']);

        $unmapped = [];
        $maps = [];
        foreach (self::COLUMNS as $type => $column) {
            $maps[$type] = $this->createItems($type, $users->map(fn ($u) => $this->decode($u->{$column})));
        }

        $now = now();
        foreach ($users as $user) {
            $after = [];
            foreach (self::COLUMNS as $type => $column) {
                $before = $this->decode($user->{$column});
                if (! is_array($before)) {
                    // NULL stays NULL; anything else that is not a list is left exactly as it is.
                    if ($user->{$column} !== null) {
                        $unmapped[] = ['user_id' => $user->id, 'column' => $column, 'value' => $user->{$column}];
                    }
                    $after[$column] = $user->{$column};

                    continue;
                }

                $keys = [];
                foreach ($before as $value) {
                    $norm = is_string($value) ? $this->normalize($value) : '';
                    if ($norm !== '' && isset($maps[$type][$norm])) {
                        $value = $maps[$type][$norm];
                    } else {
                        $unmapped[] = ['user_id' => $user->id, 'column' => $column, 'value' => $value];
                    }
                    // "STOLL" + "Stoll" on one profile become the same key once.
                    if (! in_array($value, $keys, true)) {
                        $keys[] = $value;
                    }
                }
                $after[$column] = json_encode($keys, JSON_UNESCAPED_UNICODE);
            }

            DB::table('profile_list_migration_backups')->insert([
                'user_id' => $user->id,
                'machines_before' => $user->machines,
                'skills_before' => $user->skills,
                'machines_after' => $after['machines'],
                'skills_after' => $after['skills'],
                'created_at' => $now,
            ]);
            DB::table('users')->where('id', $user->id)->update($after);
        }

        if ($users->isNotEmpty()) {
            Log::info('Phase 11d-1 profile list migration', [
                'accounts_converted' => $users->count(),
                'machine_items' => count(array_unique($maps['machine'])),
                'skill_items' => count(array_unique($maps['skill'])),
                'unmapped_values' => $unmapped,
            ]);
        }
        if ($unmapped !== []) {
            Log::warning('Phase 11d-1: values that could not be mapped were kept unchanged', ['values' => $unmapped]);
        }
    }

    public function down(): void
    {
        $items = DB::table('profile_list_items')->get()->groupBy('type')
            ->map(fn ($group) => $group->pluck('name_en', 'key'));
        $backups = DB::table('profile_list_migration_backups')->get()->keyBy('user_id');

        DB::table('users')
            ->where(fn ($q) => $q->whereNotNull('machines')->orWhereNotNull('skills'))
            ->orderBy('id')
            ->get(['id', 'machines', 'skills'])
            ->each(function ($user) use ($items, $backups) {
                $backup = $backups->get($user->id);
                $values = [];
                foreach (self::COLUMNS as $type => $column) {
                    $current = $this->decode($user->{$column});
                    if ($backup && $current == $this->decode($backup->{$column.'_after'})) {
                        // Not edited since the migration: the exact old text comes back.
                        $values[$column] = $backup->{$column.'_before'};
                    } elseif (is_array($current)) {
                        // Changed later (or a new account): keys become the current English names.
                        $names = $items->get($type, collect());
                        $values[$column] = json_encode(
                            array_map(fn ($key) => is_string($key) ? ($names[$key] ?? $key) : $key, $current),
                            JSON_UNESCAPED_UNICODE,
                        );
                    } else {
                        $values[$column] = $user->{$column};
                    }
                }
                DB::table('users')->where('id', $user->id)->update($values);
            });

        Schema::dropIfExists('profile_list_migration_backups');
        Schema::dropIfExists('profile_list_items');
    }

    /**
     * One item per distinct name (case / spacing ignored), most used first, then by name.
     *
     * @return array<string, string> normalized name → key
     */
    private function createItems(string $type, $lists): array
    {
        $groups = [];
        foreach ($lists as $list) {
            if (! is_array($list)) {
                continue;
            }
            $seen = [];
            foreach (array_filter($list, 'is_string') as $value) {
                $norm = $this->normalize($value);
                // Each name counts once per profile ("STOLL" + "Stoll" on one profile = one use).
                if ($norm === '' || isset($seen[$norm])) {
                    continue;
                }
                $seen[$norm] = true;
                $label = trim((string) preg_replace('/\s+/u', ' ', $value));
                $groups[$norm]['count'] = ($groups[$norm]['count'] ?? 0) + 1;
                $groups[$norm]['spellings'][$label] = ($groups[$norm]['spellings'][$label] ?? 0) + 1;
            }
        }

        foreach ($groups as $norm => $group) {
            // Most used spelling; on a tie the first one seen (arsort keeps the order of equal values).
            arsort($group['spellings']);
            $groups[$norm]['label'] = (string) array_key_first($group['spellings']);
        }
        uasort($groups, fn ($a, $b) => [$b['count'], mb_strtolower($a['label'])] <=> [$a['count'], mb_strtolower($b['label'])]);

        $map = [];
        $used = [];
        $order = 0;
        $now = now();
        foreach ($groups as $norm => $group) {
            $label = mb_substr($group['label'], 0, 100);
            $base = Str::limit(Str::slug($label), 80, '') ?: $type;
            $key = $base;
            for ($n = 2; isset($used[$key]); $n++) {
                $key = $base.'-'.$n;
            }
            $used[$key] = true;

            DB::table('profile_list_items')->insert([
                'type' => $type,
                'key' => $key,
                'name_bn' => $label,
                'name_en' => $label,
                'is_active' => true,
                'sort_order' => $order++,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $map[$norm] = $key;
        }

        return $map;
    }

    private function decode(?string $json): mixed
    {
        return $json === null ? null : json_decode($json, true);
    }

    private function normalize(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }
};
