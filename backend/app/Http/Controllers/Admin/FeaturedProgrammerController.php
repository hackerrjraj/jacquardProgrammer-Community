<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PublicProgrammerController;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Featured programmers (Phase 11b-2, owner Option A) — admin + super_admin (route middleware).
 * Featured programmers are shown first in the Home "Our Programmers" rows, in featured_order (1, 2, 3 … always gap-free).
 * Only programmers shown on Home (User::onHome) can be added. Someone featured who is later suspended / no longer
 * listed keeps the flag but is left out on Home (and comes back by themself if re-approved).
 * Every add / remove / reorder is written to the audit log.
 */
class FeaturedProgrammerController extends Controller
{
    public const SEARCH_LIMIT = 20;

    /** GET /api/admin/featured-programmers — every featured programmer in Home order, with "shown on Home now". */
    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->featuredList()]);
    }

    /**
     * GET /api/admin/featured-programmers/candidates?search= — programmers who can be added: shown on Home and
     * not featured yet. Search by name or mobile (Bengali digits accepted). At most SEARCH_LIMIT, by name.
     */
    public function candidates(Request $request): JsonResponse
    {
        $search = self::asciiDigits(trim((string) ($request->validate(['search' => ['nullable', 'string', 'max:100']])['search'] ?? '')));

        $users = User::query()->onHome()
            ->where('is_featured', false)
            ->when($search !== '', function (Builder $q) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('phone', 'like', $like));
            })
            ->orderBy('name')->orderBy('id')
            ->limit(self::SEARCH_LIMIT)
            ->get();

        return response()->json(['data' => $users->map(fn (User $u) => $this->person($u))->values()]);
    }

    /** POST /api/admin/featured-programmers — {"user_id": 12}: added at the end of the featured list. */
    public function store(Request $request): JsonResponse
    {
        $id = (int) $request->validate(['user_id' => ['required', 'integer']])['user_id'];
        $user = User::query()->find($id);

        if ($user === null || ! User::query()->onHome()->whereKey($id)->exists()) {
            abort(response()->json([
                'message' => __('messages.featured_not_eligible'),
                'errors' => ['user_id' => [__('messages.featured_not_eligible')]],
            ], 422));
        }
        if ($user->is_featured) {
            abort(response()->json([
                'message' => __('messages.featured_already'),
                'errors' => ['user_id' => [__('messages.featured_already')]],
            ], 422));
        }

        DB::transaction(function () use ($request, $user) {
            $user->forceFill([
                'is_featured' => true,
                'featured_order' => (User::where('is_featured', true)->max('featured_order') ?? 0) + 1,
            ])->save();
            self::renumber();
            AuditLog::record($request, 'featured.added', $user, ['position' => $user->refresh()->featured_order]);
        });
        Cache::forget(PublicProgrammerController::HOME_CACHE_KEY);

        return response()->json(['message' => __('messages.featured_added'), 'data' => $this->featuredList()], 201);
    }

    /** DELETE /api/admin/featured-programmers/{id} — no longer featured; the others close the gap. */
    public function destroy(Request $request, int $member): JsonResponse
    {
        $user = User::query()->where('is_featured', true)->findOrFail($member);
        $position = $user->featured_order;

        DB::transaction(function () use ($request, $user, $position) {
            $user->forceFill(['is_featured' => false, 'featured_order' => null])->save();
            self::renumber();
            AuditLog::record($request, 'featured.removed', $user, ['position' => $position]);
        });
        Cache::forget(PublicProgrammerController::HOME_CACHE_KEY);

        return response()->json(['message' => __('messages.featured_removed'), 'data' => $this->featuredList()]);
    }

    /** PUT /api/admin/featured-programmers/order — {"ids": [7, 3, 9]}: every featured id exactly once, in the new order. */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
        ]);
        $ids = array_map('intval', $data['ids']);

        $allIds = User::where('is_featured', true)->pluck('id')->all();
        if (count($ids) !== count($allIds) || array_diff($allIds, $ids) !== []) {
            abort(response()->json([
                'message' => __('messages.featured_order_invalid'),
                'errors' => ['ids' => [__('messages.featured_order_invalid')]],
            ], 422));
        }

        DB::transaction(function () use ($request, $ids) {
            foreach ($ids as $position => $id) {
                User::whereKey($id)->update(['featured_order' => $position + 1]);
            }
            AuditLog::record($request, 'featured.reordered', null, ['order' => $ids]);
        });

        return response()->json(['message' => __('messages.featured_order_saved'), 'data' => $this->featuredList()]);
    }

    // ---- Helpers ------------------------------------------------------------

    /** Featured users ordered for Home: featured_order, then id (a tie can only come from old data). */
    public static function featuredQuery(): Builder
    {
        return User::query()->where('is_featured', true)->orderBy('featured_order')->orderBy('id');
    }

    /** Numbers every featured user 1, 2, 3 … in their current order (closes gaps). */
    private static function renumber(): void
    {
        $position = 0;
        foreach (self::featuredQuery()->pluck('featured_order', 'id') as $id => $order) {
            $position++;
            if ($order !== $position) {
                User::whereKey($id)->update(['featured_order' => $position]);
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function featuredList(): array
    {
        $shown = User::query()->onHome()->where('is_featured', true)->pluck('id')->flip();

        return self::featuredQuery()->get()
            ->map(fn (User $u) => [
                ...$this->person($u),
                'featured_order' => $u->featured_order,
                'shown_on_home' => $shown->has($u->id),
                'status' => $u->status,
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function person(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'photo_url' => $user->photo_path ? Storage::disk('public')->url($user->photo_path) : null,
            'designation' => $user->designation,
            'district' => $user->district,
        ];
    }

    /** Lets admins search a mobile number typed with Bengali digits. */
    private static function asciiDigits(string $value): string
    {
        return strtr($value, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
    }
}
