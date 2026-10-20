<?php

namespace App\Support;

use App\Models\CommitteeMember;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Site contact info + About text (Phase 11c, MASTER_SPEC §14, §15), edited by admin / super_admin on
 * Dashboard → Site Settings and shown in the footer, on Contact and on About. Stored as one JSON setting `site_info`.
 * Every field is optional; an empty field is null and the website hides that line.
 * Also the public committee list, built from the committee panel (CommitteeMember model).
 */
class SiteInfo
{
    public const CACHE_KEY = 'public.site_info';

    public const COMMITTEE_CACHE_KEY = 'public.committee';

    /** Every field, in the order the admin screen shows them. All are public. */
    public const FIELDS = [
        'phone_1', 'phone_2', 'email', 'whatsapp', 'facebook_url',
        'address_bn', 'address_en', 'office_hours_bn', 'office_hours_en',
        'about_bn', 'about_en',
    ];

    /** @return array<string, string|null> */
    public static function defaults(): array
    {
        return array_fill_keys(self::FIELDS, null);
    }

    /** @return array<string, string|null> the saved values, filled up with nulls */
    public static function stored(): array
    {
        $saved = json_decode((string) Setting::valueOf(Setting::SITE_INFO, ''), true);
        $out = self::defaults();
        if (! is_array($saved)) {
            return $out;
        }
        foreach (self::FIELDS as $field) {
            $value = $saved[$field] ?? null;
            $out[$field] = is_string($value) && $value !== '' ? $value : null;
        }

        return $out;
    }

    /** @return array<string, string|null> what the public website gets (cached until the next change) */
    public static function forPublic(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => array_intersect_key(self::stored(), array_flip(self::FIELDS)));
    }

    /**
     * The public committee list (About page): active committee-panel members whose account is still approved
     * (not demo), in the panel's display order. Name, photo and position only — never mobile, email or ids.
     * Cached with both position languages; the request language picks one.
     *
     * @return list<array{name: string, photo_url: string|null, position: string|null}>
     */
    public static function committeeForPublic(): array
    {
        $rows = Cache::remember(self::COMMITTEE_CACHE_KEY, now()->addMinutes(10), fn () => CommitteeMember::query()
            ->with(['user:id,name,photo_path', 'position:id,name_en,name_bn'])
            ->where('is_active', true)
            ->whereHas('user', fn ($q) => $q->where('status', User::STATUS_APPROVED)->notDemo())
            ->orderBy('display_order')->orderBy('id')
            ->get()
            ->map(fn (CommitteeMember $m) => [
                'user_id' => $m->user->id,
                'name' => $m->user->name,
                'photo_url' => $m->user->photo_path ? Storage::disk('public')->url($m->user->photo_path) : null,
                'position_en' => $m->position?->name_en,
                'position_bn' => $m->position?->name_bn,
            ])
            ->values()
            ->all());

        $bn = app()->getLocale() === 'bn';
        // profile_id: only for members who have a public programmer profile (same rule as the directory); checked fresh.
        $listed = User::query()->inDirectory()->notDemo()->whereIn('id', array_column($rows, 'user_id'))->pluck('id')->all();

        return array_map(fn (array $r) => [
            'name' => $r['name'],
            'photo_url' => $r['photo_url'],
            'position' => ($bn && $r['position_bn'] ? $r['position_bn'] : $r['position_en']),
            'profile_id' => in_array($r['user_id'], $listed, true) ? $r['user_id'] : null,
        ], $rows);
    }

    public static function forgetCommittee(): void
    {
        Cache::forget(self::COMMITTEE_CACHE_KEY);
    }
}
