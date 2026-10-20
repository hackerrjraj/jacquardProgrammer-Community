<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\HeroSlide;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Home hero slides (MASTER_SPEC §13, §37 Content) — admin + super_admin (route middleware).
 * Every change is written to the audit log (never file contents); replacing or deleting the image deletes the old file.
 */
class HeroSlideController extends Controller
{
    private const TEXT_FIELDS = ['title_bn', 'title_en', 'subtitle_bn', 'subtitle_en', 'button_text_bn', 'button_text_en', 'button_link'];

    /** GET /api/admin/hero-slides — all slides (active + hidden) in display order. */
    public function index(): JsonResponse
    {
        return response()->json(['data' => HeroSlide::query()->ordered()->get()->map(fn (HeroSlide $s) => $this->present($s))]);
    }

    /**
     * POST /api/admin/hero-slides (multipart): image (JPG/PNG/WebP ≤ 2 MB), optional bn/en title, subtitle and
     * button text, button_link (with a button text), is_active? (default true). New slides go at the end.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            ...$this->textRules(false),
            'is_active' => ['sometimes', 'boolean'],
            'image' => ['required', ...$this->imageRule()],
        ]);

        $clean = $this->cleanTexts($data);
        $this->checkButton($clean);

        $slide = new HeroSlide([
            ...$clean,
            'sort_order' => (HeroSlide::max('sort_order') ?? -1) + 1,
            'is_active' => $data['is_active'] ?? true,
        ]);
        $slide->image_path = $this->storeImage($request->file('image'));

        DB::transaction(function () use ($request, $slide) {
            $slide->save();
            AuditLog::record($request, 'hero_slide.created', $slide, [
                'title_bn' => $slide->title_bn,
                'title_en' => $slide->title_en,
                'button_link' => $slide->button_link,
                'is_active' => $slide->is_active,
            ]);
        });

        return response()->json([
            'message' => __('messages.hero_created'),
            'data' => $this->present($slide->refresh()),
        ], 201);
    }

    /**
     * PATCH /api/admin/hero-slides/{id} (JSON, or multipart with `_method=PATCH` to send a new `image`) —
     * send only what changes. A new image replaces the old one, whose file is deleted.
     */
    public function update(Request $request, int $slide): JsonResponse
    {
        $heroSlide = HeroSlide::findOrFail($slide);
        $data = $request->validate([
            ...$this->textRules(true),
            'image' => ['sometimes', ...$this->imageRule()],
        ]);

        $clean = $this->cleanTexts($data);
        $this->checkButton($clean, $heroSlide);
        $heroSlide->fill($clean);
        $old = null;
        if ($request->hasFile('image')) {
            $old = clone $heroSlide;
            $heroSlide->image_path = $this->storeImage($request->file('image'));
        }

        DB::transaction(function () use ($request, $heroSlide, $old) {
            $changes = $this->changes($heroSlide);
            $heroSlide->save();

            if ($changes !== [] || $old !== null) {
                $details = $changes !== [] ? ['changes' => $changes] : [];
                AuditLog::record($request, 'hero_slide.updated', $heroSlide, $old !== null ? [...$details, 'image_replaced' => true] : $details);
            }
        });
        $old?->deleteImageFile();

        return response()->json([
            'message' => __('messages.hero_updated'),
            'data' => $this->present($heroSlide),
        ]);
    }

    /** POST /api/admin/hero-slides/{id}/active — {"is_active": true|false}: show on / hide from the Home page. */
    public function setActive(Request $request, int $slide): JsonResponse
    {
        $heroSlide = HeroSlide::findOrFail($slide);
        $active = $request->validate(['is_active' => ['required', 'boolean']])['is_active'];
        $active = filter_var($active, FILTER_VALIDATE_BOOLEAN);

        // Pressing the same switch twice changes nothing and is not logged again.
        if ($heroSlide->is_active !== $active) {
            $heroSlide->is_active = $active;
            DB::transaction(function () use ($request, $heroSlide, $active) {
                $heroSlide->save();
                AuditLog::record($request, $active ? 'hero_slide.activated' : 'hero_slide.deactivated', $heroSlide);
            });
        }

        return response()->json([
            'message' => __($active ? 'messages.hero_activated' : 'messages.hero_deactivated'),
            'data' => $this->present($heroSlide),
        ]);
    }

    /** DELETE /api/admin/hero-slides/{id} — the image file is deleted too. */
    public function destroy(Request $request, int $slide): JsonResponse
    {
        $heroSlide = HeroSlide::findOrFail($slide);

        DB::transaction(function () use ($request, $heroSlide) {
            $heroSlide->delete();
            AuditLog::record($request, 'hero_slide.deleted', $heroSlide, [
                'slide_id' => $heroSlide->id,
                'title_bn' => $heroSlide->title_bn,
                'title_en' => $heroSlide->title_en,
            ]);
        });
        $heroSlide->deleteImageFile();

        return response()->json(['message' => __('messages.hero_deleted')]);
    }

    /** PUT /api/admin/hero-slides/order — {"ids": [3, 1, 2]}: every slide id exactly once, in the new order. */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
        ]);
        $ids = array_map('intval', $data['ids']);

        $allIds = HeroSlide::pluck('id')->all();
        if (count($ids) !== count($allIds) || array_diff($allIds, $ids) !== []) {
            abort(response()->json([
                'message' => __('messages.hero_order_invalid'),
                'errors' => ['ids' => [__('messages.hero_order_invalid')]],
            ], 422));
        }

        DB::transaction(function () use ($request, $ids) {
            foreach ($ids as $position => $id) {
                HeroSlide::whereKey($id)->where('sort_order', '!=', $position)->update(['sort_order' => $position]);
            }
            AuditLog::record($request, 'hero_slide.reordered', null, ['order' => $ids]);
        });

        return response()->json([
            'message' => __('messages.hero_order_saved'),
            'data' => HeroSlide::query()->ordered()->get()->map(fn (HeroSlide $s) => $this->present($s)),
        ]);
    }

    // ---- Helpers ------------------------------------------------------------

    /** @return array<string, mixed> */
    private function present(HeroSlide $slide): array
    {
        return [
            'id' => $slide->id,
            'image_url' => $slide->imageUrl(),
            'title_bn' => $slide->title_bn,
            'title_en' => $slide->title_en,
            'subtitle_bn' => $slide->subtitle_bn,
            'subtitle_en' => $slide->subtitle_en,
            'button_text_bn' => $slide->button_text_bn,
            'button_text_en' => $slide->button_text_en,
            'button_link' => $slide->button_link,
            'sort_order' => $slide->sort_order,
            'is_active' => $slide->is_active,
            'created_at' => $slide->created_at?->toIso8601String(),
            'updated_at' => $slide->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, list<mixed>> */
    private function textRules(bool $partial): array
    {
        $optional = $partial ? ['sometimes', 'nullable'] : ['nullable'];

        return [
            'title_bn' => [...$optional, 'string', 'max:150'],
            'title_en' => [...$optional, 'string', 'max:150'],
            'subtitle_bn' => [...$optional, 'string', 'max:300'],
            'subtitle_en' => [...$optional, 'string', 'max:300'],
            'button_text_bn' => [...$optional, 'string', 'max:40'],
            'button_text_en' => [...$optional, 'string', 'max:40'],
            'button_link' => [...$optional, 'string', 'max:255', $this->linkRule()],
        ];
    }

    /**
     * A button needs both a text (bn or en) and a link. When updating, the saved values count for the fields
     * not sent. Runs on the cleaned texts, before anything is saved.
     */
    private function checkButton(array $clean, ?HeroSlide $slide = null): void
    {
        $value = fn (string $field) => array_key_exists($field, $clean) ? $clean[$field] : $slide?->{$field};
        $hasText = filled($value('button_text_bn')) || filled($value('button_text_en'));
        $hasLink = filled($value('button_link'));

        if ($hasText && ! $hasLink) {
            throw ValidationException::withMessages(['button_link' => __('messages.hero_button_needs_link')]);
        }
        if ($hasLink && ! $hasText) {
            throw ValidationException::withMessages(['button_link' => __('messages.hero_link_needs_text')]);
        }
    }

    /** An internal path ("/register", not "//host") or a full https:// address — nothing else. */
    private function linkRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $value = trim((string) $value);
            if ($value === '') {
                return;
            }
            $internal = preg_match('#^/(?![/\\\\])[^\s]*$#', $value) === 1;
            $https = preg_match('#^https://[^\s/?\#]+\.[^\s/?\#]+([/?\#][^\s]*)?$#i', $value) === 1
                && filter_var($value, FILTER_VALIDATE_URL) !== false;
            if (! $internal && ! $https) {
                $fail(__('messages.hero_link_invalid'));
            }
        };
    }

    /** @return list<string> */
    private function imageRule(): array
    {
        return ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:'.HeroSlide::MAX_KB];
    }

    /** Trims the text fields; empty ones become null. */
    private function cleanTexts(array $data): array
    {
        $clean = [];
        foreach (self::TEXT_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field] === null ? null : trim($data[$field]);
                $clean[$field] = $value === '' ? null : $value;
            }
        }

        return $clean;
    }

    private function storeImage(UploadedFile $file): string
    {
        $name = Str::random(40).'.'.strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());

        return $file->storeAs('slides', $name, HeroSlide::DISK);
    }

    /** @return array<string, array{from: mixed, to: mixed}> */
    private function changes(HeroSlide $slide): array
    {
        $changes = [];
        foreach ($slide->getDirty() as $field => $new) {
            if ($field !== 'image_path') {
                $changes[$field] = ['from' => $slide->getOriginal($field), 'to' => $new];
            }
        }

        return $changes;
    }
}
