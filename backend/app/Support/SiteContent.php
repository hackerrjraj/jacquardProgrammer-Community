<?php

namespace App\Support;

use App\Models\SiteContent as SiteContentRow;
use Illuminate\Support\Facades\Cache;

/**
 * Editable public-page text (Phase 14a). The fixed list of editable keys + their defaults (taken from the
 * translation files) is config/site_content.php. Only customised values are stored; the public API sends just those
 * and the website falls back to its translation file for everything else. Plain text only.
 */
class SiteContent
{
    public const CACHE_KEY = 'public.site_content';

    /** @return array<string, array{group: string, max: int, multiline: bool, bn: string, en: string}> */
    public static function registry(): array
    {
        return config('site_content');
    }

    /** @return array<string, array{bn: ?string, en: ?string}> stored custom values (null = default) */
    public static function stored(): array
    {
        $out = [];
        foreach (SiteContentRow::query()->get() as $row) {
            if (isset(self::registry()[$row->key])) {
                $out[$row->key] = ['bn' => $row->value_bn, 'en' => $row->value_en];
            }
        }

        return $out;
    }

    /** @return array<string, array{bn?: string, en?: string}> customised, non-empty values only (cached until a save) */
    public static function forPublic(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $out = [];
            foreach (self::stored() as $key => $value) {
                $entry = array_filter(['bn' => $value['bn'], 'en' => $value['en']], fn ($v) => is_string($v) && $v !== '');
                if ($entry) {
                    $out[$key] = $entry;
                }
            }

            return $out;
        });
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
