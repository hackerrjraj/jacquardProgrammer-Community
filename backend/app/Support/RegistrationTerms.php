<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Registration terms & conditions: shown in a popup on the register page, accepted before registering.
 * Stored as one JSON setting `registration_terms`; `version` goes up by one on every edit and is saved on the
 * member (users.terms_version) together with the acceptance time. The terms count as active only while the admin
 * switch is on AND a text exists, so an empty default never blocks registration.
 */
class RegistrationTerms
{
    public const CACHE_KEY = 'public.registration_terms';

    /** Default texts (used while the admin has not written their own). */
    public const DEFAULTS = [
        'heading_bn' => 'আমাদের নিয়মাবলী আগে পড়ুন',
        'heading_en' => 'Please read our rules first',
        'checkbox_bn' => 'উপরের সব নিয়ম পড়েছি এবং রাজি আছি — রাজি থাকলে টিক দিন',
        'checkbox_en' => 'I have read and agree to all the rules above — tick if you agree',
    ];

    /** Text fields, with their maximum length. */
    public const FIELDS = [
        'heading_bn' => 150, 'heading_en' => 150,
        'body_bn' => 10000, 'body_en' => 10000,
        'checkbox_bn' => 250, 'checkbox_en' => 250,
    ];

    /** @return array<string, mixed> */
    public static function stored(): array
    {
        $saved = json_decode((string) Setting::valueOf(Setting::REGISTRATION_TERMS, ''), true);
        $saved = is_array($saved) ? $saved : [];

        $out = ['enabled' => (bool) ($saved['enabled'] ?? true)];
        foreach (array_keys(self::FIELDS) as $field) {
            $value = $saved[$field] ?? null;
            $out[$field] = is_string($value) && $value !== '' ? $value : (self::DEFAULTS[$field] ?? '');
        }
        $out['version'] = (int) ($saved['version'] ?? 0);
        $out['updated_at'] = is_string($saved['updated_at'] ?? null) ? $saved['updated_at'] : null;

        return $out;
    }

    /** @return array<string, mixed> what the public register page gets (cached until the next edit) */
    public static function forPublic(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $s = self::stored();
            $s['active'] = $s['enabled'] && ($s['body_bn'] !== '' || $s['body_en'] !== '');

            return $s;
        });
    }

    public static function isActive(): bool
    {
        return (bool) self::forPublic()['active'];
    }

    public static function version(): int
    {
        return (int) self::forPublic()['version'];
    }
}
