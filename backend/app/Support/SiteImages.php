<?php

namespace App\Support;

use App\Models\SiteImage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/** Editable site images (Site Content Manager part 2). Registry = config/site_images.php; only uploaded images are stored. */
class SiteImages
{
    public const CACHE_KEY = 'public.site_images';

    /** @return array<string, array{group: string, w: int, h: int, mode: string, format: string, rec: string, min?: int}> */
    public static function registry(): array
    {
        return config('site_images');
    }

    /** @return array<string, SiteImage> */
    public static function stored(): array
    {
        $out = [];
        foreach (SiteImage::query()->get() as $row) {
            if (isset(self::registry()[$row->key])) {
                $out[$row->key] = $row;
            }
        }

        return $out;
    }

    public static function url(string $path): string
    {
        return Storage::disk(SiteImage::DISK)->url($path);
    }

    /** @return array<string, string> key → URL of the uploaded images only; the favicon also gives favicon_<size> entries (cached until a change) */
    public static function forPublic(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $out = [];
            foreach (self::stored() as $key => $row) {
                $out[$key] = self::url($row->path);
                foreach ($row->files ?? [] as $name => $path) {
                    $out["{$key}_{$name}"] = self::url($path);
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
