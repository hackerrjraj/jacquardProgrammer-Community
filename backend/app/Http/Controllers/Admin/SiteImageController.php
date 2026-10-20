<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SiteImage;
use App\Support\SiteImageProcessor;
use App\Support\SiteImages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Site Content Manager part 2 — editable site images (admin + super_admin by route middleware). Uploads are checked
 * (JPG / PNG / WebP, 2 MB), resized + compressed, stored on the public disk; the old files are deleted on replace /
 * remove. Every change is audit-logged (key only, never file contents); the public cache is cleared.
 */
class SiteImageController extends Controller
{
    /** Largest accepted upload, in KB (same 2 MB limit as the hero slides). */
    public const MAX_KB = 2048;

    /** GET /api/admin/site-images — every editable image: group, recommended size, current URL (null = built-in default). */
    public function index(): JsonResponse
    {
        $stored = SiteImages::stored();
        $items = [];
        foreach (SiteImages::registry() as $key => $def) {
            $row = $stored[$key] ?? null;
            $items[] = [
                'key' => $key,
                'group' => $def['group'],
                'recommended' => $def['rec'],
                'format' => $def['format'],
                'max_kb' => self::MAX_KB,
                'url' => $row ? SiteImages::url($row->path) : null,
                'updated_at' => $row?->updated_at?->toIso8601String(),
            ];
        }

        return response()->json(['data' => $items]);
    }

    /** POST /api/admin/site-images/{key} (multipart `image`) — set or replace. */
    public function store(Request $request, string $key): JsonResponse
    {
        $def = SiteImages::registry()[$key] ?? abort(404);
        $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:'.self::MAX_KB],
        ]);
        $file = $request->file('image');

        $size = SiteImageProcessor::dimensions($file);
        if ($size === null) {
            throw ValidationException::withMessages(['image' => __('messages.site_image_unreadable')]);
        }
        if ($size[0] * $size[1] > SiteImageProcessor::MAX_PIXELS) {
            throw ValidationException::withMessages(['image' => __('messages.site_image_too_big_pixels')]);
        }
        if (isset($def['min']) && min($size) < $def['min']) {
            throw ValidationException::withMessages(['image' => __('messages.site_image_too_small', ['min' => $def['min']])]);
        }

        try {
            $new = SiteImageProcessor::store($key, $def, $file, SiteImage::DISK);
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['image' => __('messages.site_image_unreadable')]);
        }

        $old = SiteImage::query()->where('key', $key)->first();
        $oldPaths = $old ? $old->allPaths() : [];
        try {
            DB::transaction(function () use ($request, $key, $new, $old) {
                SiteImage::query()->updateOrCreate(['key' => $key], [
                    'path' => $new['path'], 'files' => $new['files'], 'updated_by' => $request->user()->id,
                ]);
                AuditLog::record($request, 'site_image.updated', null, ['key' => $key, 'replaced' => $old !== null]);
            });
        } catch (Throwable $e) {
            Storage::disk(SiteImage::DISK)->delete(array_filter([$new['path'], ...array_values($new['files'] ?? [])]));
            throw $e;
        }
        Storage::disk(SiteImage::DISK)->delete($oldPaths);
        SiteImages::forget();

        return response()->json([
            'message' => __('messages.site_image_saved'),
            'data' => ['key' => $key, 'url' => SiteImages::url($new['path'])],
        ]);
    }

    /** DELETE /api/admin/site-images/{key} — back to the design default; the files are deleted. */
    public function destroy(Request $request, string $key): JsonResponse
    {
        abort_unless(isset(SiteImages::registry()[$key]), 404);
        $row = SiteImage::query()->where('key', $key)->first();

        if ($row) {
            DB::transaction(function () use ($request, $key, $row) {
                $row->delete();
                AuditLog::record($request, 'site_image.removed', null, ['key' => $key]);
            });
            $row->deleteFiles();
            SiteImages::forget();
        }

        return response()->json(['message' => $row ? __('messages.site_image_removed') : __('messages.settings_nothing_changed')]);
    }
}
