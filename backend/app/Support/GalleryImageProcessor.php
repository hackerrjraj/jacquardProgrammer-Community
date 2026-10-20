<?php

namespace App\Support;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Makes the two gallery versions of an uploaded JPG/PNG/WebP with PHP's GD (no extra package):
 * a display image (longest side max 1600 px) and a thumbnail (max 600 px), both WebP with
 * random file names on the public disk. Phone photos are turned upright (EXIF orientation).
 * The uploaded original is not kept.
 */
class GalleryImageProcessor
{
    public const DISPLAY_MAX = 1600;

    public const THUMB_MAX = 600;

    private const QUALITY = 82;

    /**
     * @return array{image_path: string, thumb_path: string, width: int, height: int}
     *
     * @throws RuntimeException when the file cannot be read as an image
     */
    public static function store(UploadedFile $file, string $disk = 'public'): array
    {
        $source = self::load($file->getRealPath());
        $display = self::fit($source, self::DISPLAY_MAX);
        $thumb = self::fit($source, self::THUMB_MAX);

        $name = Str::random(40).'.webp';
        $paths = ['image_path' => "gallery/{$name}", 'thumb_path' => "gallery/thumbs/{$name}"];
        Storage::disk($disk)->put($paths['image_path'], self::encode($display));
        Storage::disk($disk)->put($paths['thumb_path'], self::encode($thumb));

        return [...$paths, 'width' => imagesx($display), 'height' => imagesy($display)];
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
                6 => -90, // imagerotate turns counter-clockwise
                8 => 90,
                default => 0,
            };
            if ($angle !== 0) {
                $rotated = imagerotate($image, $angle, 0);
                if ($rotated instanceof GdImage) {
                    $image = $rotated;
                }
            }
        }

        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        return $image;
    }

    /** Scales down so the longest side is at most $max (never enlarges); keeps transparency. */
    private static function fit(GdImage $source, int $max): GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, $max / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $target;
    }

    private static function encode(GdImage $image): string
    {
        ob_start();
        imagewebp($image, null, self::QUALITY);

        return (string) ob_get_clean();
    }
}
