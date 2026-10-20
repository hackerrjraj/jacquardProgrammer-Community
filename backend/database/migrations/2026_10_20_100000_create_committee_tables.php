<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Committee Panel (part 1): an editable list of positions + the committee members themselves (an approved member,
 * a position, display order, term, active flag). Replaces the 11c fields users.committee_position / committee_order:
 * existing committee-role accounts are copied over first, so the About page keeps showing the same people.
 * Adding someone here never changes their login role.
 */
return new class extends Migration
{
    /** Default positions (name_en, name_bn), in the order they are offered. */
    private const DEFAULT_POSITIONS = [
        ['President', 'সভাপতি'],
        ['Vice President', 'সহ-সভাপতি'],
        ['General Secretary', 'সাধারণ সম্পাদক'],
        ['Secretary', 'সম্পাদক'],
        ['Treasurer', 'কোষাধ্যক্ষ'],
        ['Member', 'সদস্য'],
    ];

    public function up(): void
    {
        Schema::create('committee_positions', function (Blueprint $table) {
            $table->id();
            $table->string('name_en', 60)->unique();
            $table->string('name_bn', 60);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('committee_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('committee_positions')->restrictOnDelete();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->date('term_start')->nullable();
            $table->date('term_end')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        $positions = [];
        foreach (self::DEFAULT_POSITIONS as $i => [$en, $bn]) {
            $positions[$en] = DB::table('committee_positions')->insertGetId([
                'name_en' => $en, 'name_bn' => $bn, 'sort_order' => $i + 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Copy the old role-based list (same people, same order, same position text).
        $old = DB::table('users')
            ->where('role', 'committee')
            ->where(fn ($q) => $q->whereNull('email')->orWhere('email', 'not like', '%@demo.%'))
            ->orderByRaw('committee_order is null')->orderBy('committee_order')->orderBy('name')->orderBy('id')
            ->get(['id', 'committee_position']);
        foreach ($old as $i => $row) {
            $text = trim((string) $row->committee_position);
            if ($text !== '' && ! isset($positions[$text])) {
                $positions[$text] = DB::table('committee_positions')->insertGetId([
                    'name_en' => $text, 'name_bn' => $text, 'sort_order' => count($positions) + 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            DB::table('committee_members')->insert([
                'user_id' => $row->id,
                'position_id' => $text === '' ? null : $positions[$text],
                'display_order' => $i + 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['committee_position', 'committee_order']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('committee_position', 60)->nullable();
            $table->unsignedSmallInteger('committee_order')->nullable();
        });
        Schema::dropIfExists('committee_members');
        Schema::dropIfExists('committee_positions');
    }
};
