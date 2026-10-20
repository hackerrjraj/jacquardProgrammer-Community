<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminGalleryImageResource;
use App\Http\Resources\GalleryCategoryResource;
use App\Models\AuditLog;
use App\Models\GalleryCategory;
use App\Models\GalleryImage;
use App\Support\GalleryImageProcessor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Gallery management (MASTER_SPEC §23, §37 Content) — admin + super_admin (route middleware).
 * Every change is written to the audit log; deleting an image deletes its files.
 */
class GalleryController extends Controller
{
    public const PER_PAGE = 30;

    // ---- Images -------------------------------------------------------------

    /** GET /api/admin/gallery/images?category=<key>&status=published|unpublished&page= */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'category' => ['nullable', 'string', Rule::exists('gallery_categories', 'key')],
            'status' => ['nullable', Rule::in(['published', 'unpublished'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $images = GalleryImage::query()->ordered()->with('category')
            ->when($filters['category'] ?? null, fn (Builder $q, string $key) => $q->whereHas('category', fn (Builder $c) => $c->where('key', $key)))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('is_published', $status === 'published'))
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return AdminGalleryImageResource::collection($images);
    }

    /**
     * POST /api/admin/gallery/images (multipart): images[] (1–20 files, JPG/PNG/WebP ≤ 5 MB and ≤ 6000 px each),
     * category (key), title_bn?, title_en? (given to every image), is_published? (default true).
     * New images are put at the top, in the order they were sent.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:'.GalleryImage::MAX_PER_UPLOAD],
            'images.*' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:'.GalleryImage::MAX_KB, 'dimensions:max_width=6000,max_height=6000'],
            'category' => ['required', 'string', Rule::exists('gallery_categories', 'key')],
            'title_bn' => ['nullable', 'string', 'max:150'],
            'title_en' => ['nullable', 'string', 'max:150'],
            'is_published' => ['sometimes', 'boolean'],
        ]);

        $category = GalleryCategory::where('key', $data['category'])->firstOrFail();

        // Make the resized versions first; if one file is unreadable, remove what was already made.
        $stored = [];
        foreach ($request->file('images') as $index => $file) {
            try {
                $stored[] = GalleryImageProcessor::store($file, GalleryImage::DISK);
            } catch (RuntimeException) {
                foreach ($stored as $paths) {
                    (new GalleryImage)->forceFill($paths)->deleteFiles();
                }
                abort(response()->json([
                    'message' => __('messages.gallery_image_unreadable'),
                    'errors' => ["images.{$index}" => [__('messages.gallery_image_unreadable')]],
                ], 422));
            }
        }

        $images = DB::transaction(function () use ($request, $data, $category, $stored) {
            $first = (GalleryImage::min('sort_order') ?? 0) - count($stored);
            $images = [];
            foreach ($stored as $position => $paths) {
                $image = new GalleryImage([
                    'gallery_category_id' => $category->id,
                    'title_bn' => $this->clean($data['title_bn'] ?? null),
                    'title_en' => $this->clean($data['title_en'] ?? null),
                    'sort_order' => $first + $position,
                    'is_published' => $data['is_published'] ?? true,
                ]);
                $image->forceFill([...$paths, 'uploaded_by' => $request->user()->id])->save();
                $images[] = $image->setRelation('category', $category);
            }

            AuditLog::record($request, 'gallery.images_uploaded', $category, [
                'image_ids' => array_map(fn (GalleryImage $i) => $i->id, $images),
                'category' => $category->key,
                'count' => count($images),
                'is_published' => $images[0]->is_published,
            ]);

            return $images;
        });

        return response()->json([
            'message' => __('messages.gallery_uploaded', ['count' => count($images)]),
            'data' => AdminGalleryImageResource::collection($images),
        ], 201);
    }

    /** PATCH /api/admin/gallery/images/{id} — send only what changes: category, title_bn, title_en, is_published. */
    public function update(Request $request, int $image): JsonResponse
    {
        $galleryImage = GalleryImage::findOrFail($image);

        $data = $request->validate([
            'category' => ['sometimes', 'required', 'string', Rule::exists('gallery_categories', 'key')],
            'title_bn' => ['sometimes', 'nullable', 'string', 'max:150'],
            'title_en' => ['sometimes', 'nullable', 'string', 'max:150'],
            'is_published' => ['sometimes', 'required', 'boolean'],
        ]);

        if (array_key_exists('category', $data)) {
            $galleryImage->gallery_category_id = GalleryCategory::where('key', $data['category'])->value('id');
        }
        foreach (['title_bn', 'title_en'] as $field) {
            if (array_key_exists($field, $data)) {
                $galleryImage->{$field} = $this->clean($data[$field]);
            }
        }
        if (array_key_exists('is_published', $data)) {
            $galleryImage->is_published = (bool) $data['is_published'];
        }

        DB::transaction(function () use ($request, $galleryImage) {
            $changes = $this->changes($galleryImage);
            $galleryImage->save();

            if ($changes !== []) {
                AuditLog::record($request, 'gallery.image_updated', $galleryImage, ['changes' => $changes]);
            }
        });

        return response()->json([
            'message' => __('messages.gallery_image_updated'),
            'data' => new AdminGalleryImageResource($galleryImage->load('category')),
        ]);
    }

    /** DELETE /api/admin/gallery/images/{id} — the display and thumbnail files are deleted too. */
    public function destroy(Request $request, int $image): JsonResponse
    {
        $galleryImage = GalleryImage::with('category')->findOrFail($image);

        DB::transaction(function () use ($request, $galleryImage) {
            $galleryImage->delete();

            AuditLog::record($request, 'gallery.image_deleted', $galleryImage, [
                'image_id' => $galleryImage->id,
                'category' => $galleryImage->category->key,
                'title_bn' => $galleryImage->title_bn,
                'title_en' => $galleryImage->title_en,
            ]);
        });

        $galleryImage->deleteFiles();

        return response()->json(['message' => __('messages.gallery_image_deleted')]);
    }

    /**
     * PUT /api/admin/gallery/images/order — {"ids": [5, 2, 9]}: some or all image ids in their new order.
     * The listed images swap places among the positions they already hold; all other images stay where they are
     * (so a page or a single category can be reordered on its own).
     */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
        ]);
        $ids = array_map('intval', $data['ids']);

        $allIds = GalleryImage::query()->ordered()->pluck('id')->all();
        if (array_diff($ids, $allIds) !== []) {
            abort(response()->json([
                'message' => __('messages.gallery_order_invalid'),
                'errors' => ['ids' => [__('messages.gallery_order_invalid')]],
            ], 422));
        }

        // Put the new order into the slots the listed images occupy, then renumber everything 0, 1, 2, …
        $listed = array_flip($ids);
        $queue = $ids;
        $newOrder = [];
        foreach ($allIds as $id) {
            $newOrder[] = isset($listed[$id]) ? array_shift($queue) : $id;
        }

        DB::transaction(function () use ($request, $newOrder, $ids) {
            foreach ($newOrder as $position => $id) {
                GalleryImage::whereKey($id)->where('sort_order', '!=', $position)->update(['sort_order' => $position]);
            }

            AuditLog::record($request, 'gallery.images_reordered', null, ['order' => $ids]);
        });

        return response()->json(['message' => __('messages.gallery_order_saved')]);
    }

    // ---- Categories ---------------------------------------------------------

    /** GET /api/admin/gallery/categories — with the number of images (published + unpublished). */
    public function categories(): AnonymousResourceCollection
    {
        return GalleryCategoryResource::collection(GalleryCategory::query()->ordered()->withCount('images')->get());
    }

    /** POST /api/admin/gallery/categories — key (a–z, 0–9, "-"; cannot be changed later), name_bn, name_en, sort_order? */
    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('gallery_categories', 'key')],
            'name_bn' => ['required', 'string', 'max:100'],
            'name_en' => ['required', 'string', 'max:100'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ]);
        $data['sort_order'] ??= (GalleryCategory::max('sort_order') ?? 0) + 1;

        $category = DB::transaction(function () use ($request, $data) {
            $category = GalleryCategory::create($data);
            AuditLog::record($request, 'gallery.category_created', $category, $data);

            return $category;
        });

        return response()->json([
            'message' => __('messages.gallery_category_created'),
            'data' => new GalleryCategoryResource($category),
        ], 201);
    }

    /** PATCH /api/admin/gallery/categories/{id} — name_bn, name_en, sort_order (the key stays). */
    public function updateCategory(Request $request, int $category): JsonResponse
    {
        $galleryCategory = GalleryCategory::findOrFail($category);

        $galleryCategory->fill($request->validate([
            'name_bn' => ['sometimes', 'required', 'string', 'max:100'],
            'name_en' => ['sometimes', 'required', 'string', 'max:100'],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0', 'max:9999'],
        ]));

        DB::transaction(function () use ($request, $galleryCategory) {
            $changes = $this->changes($galleryCategory);
            $galleryCategory->save();

            if ($changes !== []) {
                AuditLog::record($request, 'gallery.category_updated', $galleryCategory, ['changes' => $changes]);
            }
        });

        return response()->json([
            'message' => __('messages.gallery_category_updated'),
            'data' => new GalleryCategoryResource($galleryCategory),
        ]);
    }

    /** DELETE /api/admin/gallery/categories/{id} — only when it has no images (move or delete them first). */
    public function destroyCategory(Request $request, int $category): JsonResponse
    {
        $galleryCategory = GalleryCategory::findOrFail($category);

        if ($galleryCategory->images()->exists()) {
            return response()->json(['message' => __('messages.gallery_category_not_empty')], 422);
        }

        DB::transaction(function () use ($request, $galleryCategory) {
            $galleryCategory->delete();
            AuditLog::record($request, 'gallery.category_deleted', $galleryCategory, [
                'key' => $galleryCategory->key,
                'name_bn' => $galleryCategory->name_bn,
                'name_en' => $galleryCategory->name_en,
            ]);
        });

        return response()->json(['message' => __('messages.gallery_category_deleted')]);
    }

    // ---- Helpers ------------------------------------------------------------

    /** Trimmed text, or null when empty. */
    private function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    /** @return array<string, array{from: mixed, to: mixed}> */
    private function changes(Model $model): array
    {
        $changes = [];
        foreach ($model->getDirty() as $field => $new) {
            $changes[$field] = ['from' => $model->getOriginal($field), 'to' => $new];
        }

        return $changes;
    }
}
