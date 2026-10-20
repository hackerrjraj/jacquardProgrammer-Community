<?php

namespace App\Support;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Resizes / compresses an uploaded site image with PHP's GD (JPG / PNG / WebP in; no original kept) and stores it
 * under random names on the public disk. The favicon becomes every icon size the PWA + browsers need.
 */
class SiteImageProcessor
{
    public const MAX_PIXELS = 40_000_000;

    private const QUALITY = 85;

    /** Navy of the header (matches the PWA theme colour) behind maskable / Apple icons. */
    private const NAVY = [2, 29, 48];

    /** Favicon outputs: name => [size, kind]. */
    public const FAVICON_FILES = [
        'icon-192' => [192, 'plain'],
        'icon-512' => [512, 'plain'],
        'icon-maskable-512' => [512, 'maskable'],
        'apple-touch-icon' => [180, 'apple'],
        'favicon-32' => [32, 'plain'],
    ];

    /** @return array{0: int, 1: int}|null width, height — null when it is not a readable JPG / PNG / WebP */
    public static function dimensions(UploadedFile $file): ?array
    {
        $info = @getimagesize($file->getRealPath());
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return null;
        }

        return [(int) $info[0], (int) $info[1]];
    }

    /**
     * @param  array{w: int, h: int, mode: string, format: string}  $def
     * @return array{path: string, files: ?array<string, string>}
     *
     * @throws RuntimeException when the file cannot be read as an image
     */
    public static function store(string $key, array $def, UploadedFile $file, string $disk = 'public'): array
    {
        $source = self::load($file->getRealPath());
        $base = "site-images/{$key}/".Str::random(32);

        if ($key === 'favicon') {
            $square = self::cover($source, 512, 512);
            $files = [];
            foreach (self::FAVICON_FILES as $name => [$size, $kind]) {
                $path = "{$base}-{$name}.png";
                Storage::disk($disk)->put($path, self::encode(self::icon($square, $size, $kind), 'png'));
                $files[$name] = $path;
            }

            return ['path' => $files['icon-512'], 'files' => $files];
        }

        $image = $def['mode'] === 'cover' ? self::cover($source, $def['w'], $def['h']) : self::fit($source, $def['w'], $def['h']);
        $ext = $def['format'] === 'jpeg' ? 'jpg' : $def['format'];
        $path = "{$base}.{$ext}";
        Storage::disk($disk)->put($path, self::encode($image, $def['format']));

        return ['path' => $path, 'files' => null];
    }

    private static function load(string $path): GdImage
    {
        $type = @getimagesize($path)[2] ?? null;
        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => false,
        };
        if (! $image instanceof GdImage) {
            throw new RuntimeException('Unreadable image.');
        }
        if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $angle = match ((int) (@exif_read_data($path)['Orientation'] ?? 1)) {
                3 => 180,
                6 => -90,
                8 => 90,
                default => 0,
            };
            if ($angle !== 0 && ($rotated = imagerotate($image, $angle, 0)) instanceof GdImage) {
                $image = $rotated;
            }
        }
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        return $image;
    }

    private static function canvas(int $w, int $h): GdImage
    {
        $target = imagecreatetruecolor($w, $h);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));

        return $target;
    }

    /** Centre-crop to the w:h shape; scaled down to w x h when larger, never enlarged. */
    private static function cover(GdImage $src, int $w, int $h): GdImage
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        $ratio = $w / $h;
        if ($sw / $sh > $ratio) {
            $cw = (int) round($sh * $ratio);
            $ch = $sh;
        } else {
            $cw = $sw;
            $ch = (int) round($sw / $ratio);
        }
        $cx = intdiv($sw - $cw, 2);
        $cy = intdiv($sh - $ch, 2);
        $scale = min(1, $w / $cw);
        $tw = max(1, (int) round($cw * $scale));
        $th = max(1, (int) round($ch * $scale));
        $out = self::canvas($tw, $th);
        imagecopyresampled($out, $src, 0, 0, $cx, $cy, $tw, $th, $cw, $ch);

        return $out;
    }

    /** Scales down so the image fits inside w x h (never enlarged); keeps transparency. */
    private static function fit(GdImage $src, int $w, int $h): GdImage
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        $scale = min(1, $w / $sw, $h / $sh);
        $tw = max(1, (int) round($sw * $scale));
        $th = max(1, (int) round($sh * $scale));
        $out = self::canvas($tw, $th);
        imagecopyresampled($out, $src, 0, 0, 0, 0, $tw, $th, $sw, $sh);

        return $out;
    }

    /** A square icon: "plain" keeps transparency; "maskable" / "apple" sit on the navy colour (maskable: 70 % safe zone). */
    private static function icon(GdImage $square, int $size, string $kind): GdImage
    {
        $out = self::canvas($size, $size);
        if ($kind === 'plain') {
            imagecopyresampled($out, $square, 0, 0, 0, 0, $size, $size, imagesx($square), imagesy($square));

            return $out;
        }
        imagealphablending($out, true);
        imagefill($out, 0, 0, imagecolorallocate($out, ...self::NAVY));
        $inner = $kind === 'maskable' ? (int) round($size * 0.7) : $size;
        $offset = intdiv($size - $inner, 2);
        imagecopyresampled($out, $square, $offset, $offset, 0, 0, $inner, $inner, imagesx($square), imagesy($square));

        return $out;
    }

    private static function encode(GdImage $image, string $format): string
    {
        ob_start();
        match ($format) {
            'png' => imagepng($image, null, 9),
            'jpeg' => self::jpeg($image),
            default => imagewebp($image, null, self::QUALITY),
        };

        return (string) ob_get_clean();
    }

    /** JPEG has no transparency: flatten on white. */
    private static function jpeg(GdImage $image): void
    {
        $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
        imagejpeg($flat, null, self::QUALITY);
    }
}
