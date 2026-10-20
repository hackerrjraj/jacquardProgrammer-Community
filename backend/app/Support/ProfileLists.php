<?php

namespace App\Support;

use App\Models\ProfileListItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Machine + Skill lists (Phase 11d-1). Profiles store item keys; this turns them back into bn/en names.
 * Registered as a per-request ("scoped") service so the list is read from the database at most once per request.
 */
class ProfileLists
{
    /** @var Collection<string, Collection<string, ProfileListItem>>|null type → key → item */
    private ?Collection $items = null;

    public function flush(): void
    {
        $this->items = null;
    }

    /** @return Collection<string, ProfileListItem> key → item, every item of the type (active + hidden) */
    public function items(string $type): Collection
    {
        $this->items ??= ProfileListItem::query()->ordered()->get()
            ->groupBy('type')
            ->map(fn (Collection $group) => $group->keyBy('key'));

        return $this->items->get($type, collect());
    }

    /** @return list<string> keys offered for new picks */
    public function activeKeys(string $type): array
    {
        return $this->items($type)->filter->is_active->keys()->all();
    }

    /**
     * Saved keys → [{key, name_bn, name_en}] in the saved order. Hidden items still show. A value that is not a
     * known key (a text the migration could not map) is shown as it is, in both languages — nothing is dropped.
     * `$other` = the member's free "Other" text, shown last as a normal tag (key "other").
     *
     * @return list<array{key: string, name_bn: string, name_en: string}>
     */
    public function resolve(string $type, ?array $keys, ?string $other = null): array
    {
        $items = $this->items($type);
        $extra = $other !== null && trim($other) !== '' ? [['key' => 'other', 'name_bn' => trim($other), 'name_en' => trim($other)]] : [];

        return collect($keys ?? [])
            ->filter(fn ($key) => is_scalar($key) && trim((string) $key) !== '')
            ->map(function ($key) use ($items) {
                $key = (string) $key;
                $item = $items->get($key);

                return $item ? $item->toChoice() : ['key' => $key, 'name_bn' => $key, 'name_en' => $key];
            })
            ->values()
            ->concat($extra)
            ->all();
    }

    /**
     * How many accounts (any role / status) have each key saved — an item in use cannot be deleted.
     *
     * @return array<string, int>
     */
    public function usageCounts(string $type): array
    {
        $counts = [];
        User::query()->whereNotNull(ProfileListItem::COLUMNS[$type])->pluck(ProfileListItem::COLUMNS[$type])
            ->each(function ($keys) use (&$counts) {
                foreach (array_unique(array_filter((array) $keys, 'is_scalar')) as $key) {
                    $counts[(string) $key] = ($counts[(string) $key] ?? 0) + 1;
                }
            });

        return $counts;
    }

    /** Trimmed and single-spaced (case kept). */
    public static function normalizeSpaces(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** Lowercase, trimmed, single-spaced — "  Shima  seiki " and "SHIMA SEIKI" are the same name. */
    public static function normalize(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /**
     * English names → item keys, adding any missing name at the end of the list (seeders only).
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function keysForNames(string $type, array $names): array
    {
        return array_values(array_unique(array_map(function (string $name) use ($type) {
            $item = ProfileListItem::where('type', $type)->get()
                ->first(fn (ProfileListItem $i) => self::normalize($i->name_en) === self::normalize($name));

            return ($item ?? ProfileListItem::create([
                'type' => $type,
                'key' => self::makeKey($type, $name),
                'name_bn' => $name,
                'name_en' => $name,
                'is_active' => true,
                'sort_order' => (ProfileListItem::where('type', $type)->max('sort_order') ?? -1) + 1,
            ]))->key;
        }, $names)));
    }

    /** A new, never-changing key from the English name ("SHIMA SEIKI" → "shima-seiki"), unique within the type. */
    public static function makeKey(string $type, string $nameEn): string
    {
        $base = Str::limit(Str::slug($nameEn), 80, '') ?: $type;
        $key = $base;
        for ($n = 2; ProfileListItem::where('type', $type)->where('key', $key)->exists(); $n++) {
            $key = $base.'-'.$n;
        }

        return $key;
    }
}
