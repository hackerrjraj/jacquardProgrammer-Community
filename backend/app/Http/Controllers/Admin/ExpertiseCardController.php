<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExpertiseCardResource;
use App\Models\AuditLog;
use App\Models\ExpertiseCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Home Expertise cards (MASTER_SPEC §13, §37 Content) — admin + super_admin (route middleware).
 * Every change is written to the audit log; replacing or deleting the image deletes the old file.
 */
class ExpertiseCardController extends Controller
{
    /** An icon name of the frontend's icon set, e.g. "chip" or "chevronRight". */
    private const ICON_RULE = 'regex:/^[a-zA-Z][a-zA-Z0-9-]*$/';

    /** GET /api/admin/expertise-cards — all cards (published + hidden) in display order. */
    public function index(): AnonymousResourceCollection
    {
        return ExpertiseCardResource::collection(ExpertiseCard::query()->ordered()->get());
    }

    /**
     * POST /api/admin/expertise-cards (JSON or multipart): title_bn, description_bn, title_en?, description_en?,
     * icon?, is_published? (default true), image? (JPG/PNG/WebP ≤ 2 MB). New cards go at the end.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            ...$this->textRules(false),
            'is_published' => ['sometimes', 'boolean'],
            'image' => $this->imageRule(),
        ]);

        if (ExpertiseCard::count() >= ExpertiseCard::MAX_CARDS) {
            return response()->json(['message' => __('messages.expertise_limit', ['max' => ExpertiseCard::MAX_CARDS])], 422);
        }

        $card = new ExpertiseCard([
            ...$this->cleanTexts($data),
            'sort_order' => (ExpertiseCard::max('sort_order') ?? -1) + 1,
            'is_published' => $data['is_published'] ?? true,
        ]);
        if ($request->hasFile('image')) {
            $card->image_path = $this->storeImage($request->file('image'));
        }

        DB::transaction(function () use ($request, $card) {
            $card->save();
            AuditLog::record($request, 'expertise_card.created', $card, [
                'title_bn' => $card->title_bn,
                'title_en' => $card->title_en,
                'icon' => $card->icon,
                'is_published' => $card->is_published,
                'has_image' => $card->image_path !== null,
            ]);
        });

        return response()->json([
            'message' => __('messages.expertise_created'),
            'data' => new ExpertiseCardResource($card->refresh()),
        ], 201);
    }

    /** PATCH /api/admin/expertise-cards/{id} (JSON) — send only what changes: icon, titles, descriptions. */
    public function update(Request $request, int $card): JsonResponse
    {
        $expertiseCard = ExpertiseCard::findOrFail($card);
        $expertiseCard->fill($this->cleanTexts($request->validate($this->textRules(true))));

        DB::transaction(function () use ($request, $expertiseCard) {
            $changes = $this->changes($expertiseCard);
            $expertiseCard->save();

            if ($changes !== []) {
                AuditLog::record($request, 'expertise_card.updated', $expertiseCard, ['changes' => $changes]);
            }
        });

        return response()->json([
            'message' => __('messages.expertise_updated'),
            'data' => new ExpertiseCardResource($expertiseCard),
        ]);
    }

    /** POST /api/admin/expertise-cards/{id}/publish */
    public function publish(Request $request, int $card): JsonResponse
    {
        return $this->setPublished($request, $card, true);
    }

    /** POST /api/admin/expertise-cards/{id}/unpublish — hidden from the Home page, kept for later. */
    public function unpublish(Request $request, int $card): JsonResponse
    {
        return $this->setPublished($request, $card, false);
    }

    /** POST /api/admin/expertise-cards/{id}/image (multipart `image`) — adds or replaces the image. */
    public function replaceImage(Request $request, int $card): JsonResponse
    {
        $expertiseCard = ExpertiseCard::findOrFail($card);
        $request->validate(['image' => ['required', ...array_slice($this->imageRule(), 1)]]);

        $old = clone $expertiseCard;
        $expertiseCard->image_path = $this->storeImage($request->file('image'));

        DB::transaction(function () use ($request, $expertiseCard, $old) {
            $expertiseCard->save();
            AuditLog::record($request, 'expertise_card.image_replaced', $expertiseCard, ['had_image' => $old->image_path !== null]);
        });
        $old->deleteImageFile();

        return response()->json([
            'message' => __('messages.expertise_image_saved'),
            'data' => new ExpertiseCardResource($expertiseCard),
        ]);
    }

    /** DELETE /api/admin/expertise-cards/{id}/image */
    public function removeImage(Request $request, int $card): JsonResponse
    {
        $expertiseCard = ExpertiseCard::findOrFail($card);

        if ($expertiseCard->image_path !== null) {
            $old = clone $expertiseCard;
            $expertiseCard->image_path = null;

            DB::transaction(function () use ($request, $expertiseCard) {
                $expertiseCard->save();
                AuditLog::record($request, 'expertise_card.image_removed', $expertiseCard);
            });
            $old->deleteImageFile();
        }

        return response()->json([
            'message' => __('messages.expertise_image_removed'),
            'data' => new ExpertiseCardResource($expertiseCard),
        ]);
    }

    /** DELETE /api/admin/expertise-cards/{id} — the image file is deleted too. */
    public function destroy(Request $request, int $card): JsonResponse
    {
        $expertiseCard = ExpertiseCard::findOrFail($card);

        DB::transaction(function () use ($request, $expertiseCard) {
            $expertiseCard->delete();
            AuditLog::record($request, 'expertise_card.deleted', $expertiseCard, [
                'card_id' => $expertiseCard->id,
                'title_bn' => $expertiseCard->title_bn,
                'title_en' => $expertiseCard->title_en,
            ]);
        });
        $expertiseCard->deleteImageFile();

        return response()->json(['message' => __('messages.expertise_deleted')]);
    }

    /** PUT /api/admin/expertise-cards/order — {"ids": [3, 1, 2]}: every card id exactly once, in the new order. */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
        ]);
        $ids = array_map('intval', $data['ids']);

        $allIds = ExpertiseCard::pluck('id')->all();
        if (count($ids) !== count($allIds) || array_diff($allIds, $ids) !== []) {
            abort(response()->json([
                'message' => __('messages.expertise_order_invalid'),
                'errors' => ['ids' => [__('messages.expertise_order_invalid')]],
            ], 422));
        }

        DB::transaction(function () use ($request, $ids) {
            foreach ($ids as $position => $id) {
                ExpertiseCard::whereKey($id)->where('sort_order', '!=', $position)->update(['sort_order' => $position]);
            }
            AuditLog::record($request, 'expertise_card.reordered', null, ['order' => $ids]);
        });

        return response()->json([
            'message' => __('messages.expertise_order_saved'),
            'data' => ExpertiseCardResource::collection(ExpertiseCard::query()->ordered()->get()),
        ]);
    }

    // ---- Helpers ------------------------------------------------------------

    private function setPublished(Request $request, int $card, bool $published): JsonResponse
    {
        $expertiseCard = ExpertiseCard::findOrFail($card);

        // Pressing the same button twice changes nothing and is not logged again.
        if ($expertiseCard->is_published !== $published) {
            $expertiseCard->is_published = $published;
            DB::transaction(function () use ($request, $expertiseCard, $published) {
                $expertiseCard->save();
                AuditLog::record($request, $published ? 'expertise_card.published' : 'expertise_card.unpublished', $expertiseCard);
            });
        }

        return response()->json([
            'message' => __($published ? 'messages.expertise_published' : 'messages.expertise_unpublished'),
            'data' => new ExpertiseCardResource($expertiseCard),
        ]);
    }

    /** @return array<string, list<string>> */
    private function textRules(bool $partial): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];
        $optional = $partial ? ['sometimes', 'nullable'] : ['nullable'];

        return [
            'icon' => [...$optional, 'string', 'max:50', self::ICON_RULE],
            'title_bn' => [...$required, 'string', 'max:100'],
            'title_en' => [...$optional, 'string', 'max:100'],
            'description_bn' => [...$required, 'string', 'max:500'],
            'description_en' => [...$optional, 'string', 'max:500'],
        ];
    }

    /** @return list<string> */
    private function imageRule(): array
    {
        return ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:'.ExpertiseCard::MAX_KB];
    }

    /** Trims the text fields; empty optional ones become null. */
    private function cleanTexts(array $data): array
    {
        $clean = [];
        foreach (['icon', 'title_bn', 'title_en', 'description_bn', 'description_en'] as $field) {
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

        return $file->storeAs('expertise', $name, ExpertiseCard::DISK);
    }

    /** @return array<string, array{from: mixed, to: mixed}> */
    private function changes(ExpertiseCard $card): array
    {
        $changes = [];
        foreach ($card->getDirty() as $field => $new) {
            $changes[$field] = ['from' => $card->getOriginal($field), 'to' => $new];
        }

        return $changes;
    }
}
