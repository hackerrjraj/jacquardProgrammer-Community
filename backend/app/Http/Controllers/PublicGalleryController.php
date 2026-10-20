<?php

namespace App\Http\Controllers;

use App\Http\Resources\GalleryCategoryResource;
use App\Http\Resources\GalleryImageResource;
use App\Models\GalleryCategory;
use App\Models\GalleryImage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Public (no login, read-only) gallery (MASTER_SPEC §23): published images only. Rate limited 60/min/IP.
 */
class PublicGalleryController extends Controller
{
    public const PER_PAGE = 24;

    /** GET /api/public/gallery?category=<key>&page= — published images in display order. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'category' => ['nullable', 'string', Rule::exists('gallery_categories', 'key')],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $images = GalleryImage::query()->published()->ordered()
            ->with('category')
            ->when($filters['category'] ?? null, fn (Builder $q, string $key) => $q->whereHas('category', fn (Builder $c) => $c->where('key', $key)))
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return GalleryImageResource::collection($images);
    }

    /** GET /api/public/gallery/categories — the tabs, each with its number of published images. */
    public function categories(): AnonymousResourceCollection
    {
        return GalleryCategoryResource::collection(
            GalleryCategory::query()->ordered()->withCount(['images' => fn (Builder $q) => $q->published()])->get()
        );
    }

    /** GET /api/public/gallery/{id} — one published image; unpublished or unknown → the same 404. */
    public function show(string $id): GalleryImageResource
    {
        $image = ctype_digit($id)
            ? GalleryImage::query()->published()->with('category')->find((int) $id)
            : null;

        if ($image === null) {
            throw new HttpResponseException(response()->json(['message' => __('messages.gallery_image_not_found')], 404));
        }

        return new GalleryImageResource($image);
    }
}
