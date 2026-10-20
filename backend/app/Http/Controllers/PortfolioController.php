<?php

namespace App\Http\Controllers;

use App\Http\Requests\PortfolioItemRequest;
use App\Http\Resources\PortfolioItemResource;
use App\Models\AuditLog;
use App\Models\PortfolioItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A member's own portfolio (MASTER_SPEC §19): max 12 items, images on the public disk.
 * Only the owner can change items (others get 404). Every change is written to the audit log.
 */
class PortfolioController extends Controller
{
    /** GET /api/profile/portfolio */
    public function index(Request $request): AnonymousResourceCollection
    {
        return PortfolioItemResource::collection($request->user()->portfolioItems()->get());
    }

    /** POST /api/profile/portfolio (multipart: image, category, title, description?) */
    public function store(PortfolioItemRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->portfolioItems()->count() >= PortfolioItem::MAX_PER_MEMBER) {
            return response()->json(['message' => __('messages.portfolio_limit', ['max' => PortfolioItem::MAX_PER_MEMBER])], 422);
        }

        $path = $request->file('image')->store('portfolio', PortfolioItem::DISK); // random file name

        $item = DB::transaction(function () use ($request, $user, $path) {
            $item = new PortfolioItem($request->safe()->only(['category', 'title', 'description']));
            $item->image_path = $path;
            $item->sort_order = ($user->portfolioItems()->max('sort_order') ?? -1) + 1; // added at the end
            $user->portfolioItems()->save($item);

            AuditLog::record($request, 'portfolio.added', $user, [
                'item_id' => $item->id,
                'category' => $item->category,
                'title' => $item->title,
            ]);

            return $item;
        });

        return response()->json([
            'message' => __('messages.portfolio_added'),
            'data' => new PortfolioItemResource($item),
        ], 201);
    }

    /**
     * PUT /api/profile/portfolio/{item} — send only what changes. To replace the image
     * send multipart POST with `_method=PUT` (PHP does not read files on a real PUT).
     */
    public function update(PortfolioItemRequest $request, int $item): JsonResponse
    {
        $user = $request->user();
        $portfolioItem = $user->portfolioItems()->findOrFail($item);
        $oldImage = $portfolioItem->image_path;
        $newImage = $request->hasFile('image') ? $request->file('image')->store('portfolio', PortfolioItem::DISK) : null;

        DB::transaction(function () use ($request, $user, $portfolioItem, $newImage) {
            $portfolioItem->fill($request->safe()->only(['category', 'title', 'description']));
            $changes = [];
            foreach ($portfolioItem->getDirty() as $field => $new) {
                $changes[$field] = ['from' => $portfolioItem->getOriginal($field), 'to' => $new];
            }
            if ($newImage) {
                $portfolioItem->image_path = $newImage;
                $changes['image'] = 'replaced';
            }
            $portfolioItem->save();

            AuditLog::record($request, 'portfolio.updated', $user, ['item_id' => $portfolioItem->id, 'changes' => $changes]);
        });

        if ($newImage) {
            Storage::disk(PortfolioItem::DISK)->delete($oldImage);
        }

        return response()->json([
            'message' => __('messages.portfolio_updated'),
            'data' => new PortfolioItemResource($portfolioItem),
        ]);
    }

    /** DELETE /api/profile/portfolio/{item} — the image file is deleted too. */
    public function destroy(Request $request, int $item): JsonResponse
    {
        $user = $request->user();
        $portfolioItem = $user->portfolioItems()->findOrFail($item);

        DB::transaction(function () use ($request, $user, $portfolioItem) {
            $portfolioItem->delete();

            AuditLog::record($request, 'portfolio.deleted', $user, [
                'item_id' => $portfolioItem->id,
                'category' => $portfolioItem->category,
                'title' => $portfolioItem->title,
            ]);
        });

        Storage::disk(PortfolioItem::DISK)->delete($portfolioItem->image_path);

        return response()->json(['message' => __('messages.portfolio_deleted')]);
    }

    /** PUT /api/profile/portfolio/order — {"ids": [3, 1, 2]}: all of the member's item ids, in the new order. */
    public function reorder(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $ownIds = $user->portfolioItems()->pluck('id')->all();

        $data = $request->validate([
            'ids' => ['present', 'array'],
            'ids.*' => ['integer', 'distinct'],
        ]);
        $ids = array_map('intval', $data['ids']);

        $sortedNew = $ids;
        $sortedOwn = $ownIds;
        sort($sortedNew);
        sort($sortedOwn);
        if ($sortedNew !== $sortedOwn) {
            abort(response()->json([
                'message' => __('messages.portfolio_order_invalid'),
                'errors' => ['ids' => [__('messages.portfolio_order_invalid')]],
            ], 422));
        }

        DB::transaction(function () use ($request, $user, $ids) {
            foreach ($ids as $position => $id) {
                $user->portfolioItems()->whereKey($id)->update(['sort_order' => $position]);
            }

            AuditLog::record($request, 'portfolio.reordered', $user, ['order' => $ids]);
        });

        return PortfolioItemResource::collection($user->portfolioItems()->get());
    }
}
