<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PublicHomeController;
use App\Http\Resources\ContributionResource;
use App\Models\AuditLog;
use App\Models\Contribution;
use App\Models\LedgerEntry;
use App\Support\Notifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Payment verification (MASTER_SPEC §27) — admin + super_admin (route middleware).
 * Approve → ledger entry + months paid; reject → reason kept, months and TxID free again.
 * Every decision is written to the audit log and clears the public stats cache.
 */
class ContributionController extends Controller
{
    public const PER_PAGE = 20;

    /**
     * GET /api/admin/contributions?status=pending|approved|rejected&method=&member=&search=&from=&to=&sort=newest|oldest&page=
     * from / to = submitted date (Y-m-d, both days included); sort overrides the default order.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(Contribution::STATUSES)],
            'method' => ['nullable', Rule::in(Contribution::METHODS)],
            'member' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'sort' => ['nullable', Rule::in(['newest', 'oldest'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $status = $filters['status'] ?? null;
        $oldestFirst = isset($filters['sort']) ? $filters['sort'] === 'oldest' : $status === Contribution::STATUS_PENDING;

        $contributions = Contribution::query()
            ->with(['months', 'user', 'reviewer'])
            ->when($status, fn (Builder $q) => $q->where('status', $status))
            ->when($filters['method'] ?? null, fn (Builder $q, string $m) => $q->where('method', $m))
            ->when($filters['member'] ?? null, fn (Builder $q, int $id) => $q->where('user_id', $id))
            ->when(trim($filters['search'] ?? ''), function (Builder $q, string $search) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $q->where(fn (Builder $w) => $w->where('transaction_id', 'like', $like)
                    ->orWhere('sender_number', 'like', $like)
                    ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', $like)->orWhere('phone', 'like', $like)));
            })
            ->when($filters['from'] ?? null, fn (Builder $q, string $d) => $q->whereDate('created_at', '>=', $d))
            ->when($filters['to'] ?? null, fn (Builder $q, string $d) => $q->whereDate('created_at', '<=', $d))
            // By default the pending queue is worked oldest first; everything else newest first.
            ->when($oldestFirst, fn (Builder $q) => $q->oldest('id'), fn (Builder $q) => $q->latest('id'))
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return ContributionResource::collection($contributions);
    }

    /** GET /api/admin/contributions/{id} */
    public function show(int $contribution): ContributionResource
    {
        return new ContributionResource(Contribution::with(['months', 'user', 'reviewer'])->findOrFail($contribution));
    }

    /** POST /api/admin/contributions/{id}/approve — pending only; creates the ledger entry. */
    public function approve(Request $request, int $contribution): JsonResponse
    {
        return $this->decide($request, $contribution, function (Contribution $c) use ($request) {
            // Ledger entry first, then the status — the model refuses "approved" without its entry (Phase 12b).
            LedgerEntry::create([
                'type' => LedgerEntry::TYPE_CONTRIBUTION,
                'amount' => $c->amount,
                'user_id' => $c->user_id,
                'contribution_id' => $c->id,
                'entry_date' => now()->toDateString(),
                'created_by' => $request->user()->id,
            ]);
            $c->status = Contribution::STATUS_APPROVED;
            $c->save();

            AuditLog::record($request, 'contribution.approved', $c, $this->details($c));
        }, 'messages.contribution_approved');
    }

    /** POST /api/admin/contributions/{id}/reject {reason} — pending only; the months and the TxID can be used again. */
    public function reject(Request $request, int $contribution): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $reason = trim($data['reason']);

        return $this->decide($request, $contribution, function (Contribution $c) use ($request, $reason) {
            $c->status = Contribution::STATUS_REJECTED;
            $c->rejection_reason = $reason;
            $c->txid_key = null;
            $c->save();
            $c->months()->update(['claim' => null]);

            AuditLog::record($request, 'contribution.rejected', $c, [...$this->details($c), 'reason' => $reason]);
        }, 'messages.contribution_rejected');
    }

    /** Shared approve / reject: row lock, pending check, no reviewing your own payment, cache cleared. */
    private function decide(Request $request, int $id, callable $apply, string $messageKey): JsonResponse
    {
        $model = Contribution::findOrFail($id);
        if ($model->user_id === $request->user()->id) {
            return response()->json(['message' => __('messages.contribution_own')], 403);
        }

        $result = DB::transaction(function () use ($request, $id, $apply) {
            $c = Contribution::lockForUpdate()->findOrFail($id);
            if (! $c->isPending()) {
                return null;
            }
            $c->reviewed_by = $request->user()->id;
            $c->reviewed_at = now();
            $apply($c);

            return $c;
        });

        if (! $result) {
            return response()->json(['message' => __('messages.contribution_not_pending')], 422);
        }

        Cache::forget(PublicHomeController::STATS_CACHE_KEY);
        Notifier::paymentDecided($result);

        return response()->json([
            'message' => __($messageKey),
            'data' => new ContributionResource($result->load(['months', 'user', 'reviewer'])),
        ]);
    }

    /** @return array<string, mixed> */
    private function details(Contribution $c): array
    {
        return [
            'contribution_id' => $c->id,
            'member_id' => $c->user_id,
            'amount' => $c->amount,
            'method' => $c->method,
            'transaction_id' => $c->transaction_id,
            'months' => $c->months()->get()->map(fn ($m) => $m->month->format('Y-m'))->all(),
        ];
    }
}
