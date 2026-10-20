<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PublicHomeController;
use App\Http\Resources\AssistanceRequestResource;
use App\Models\AssistanceRequest;
use App\Models\AuditLog;
use App\Models\Contribution;
use App\Models\LedgerEntry;
use App\Support\BnDigits;
use App\Support\FundFigures;
use App\Support\Notifier;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Aid decisions and payouts (MASTER_SPEC §30–§32, simple rules of Phase 8a) — admin + super_admin (route middleware).
 * approve (amount) / reject (reason) only while "submitted"; payout once, only when "approved", never more than the
 * fund ledger balance. Nobody acts on their own request. Every status change is written to the audit log.
 */
class AssistanceController extends Controller
{
    public const PER_PAGE = 20;

    /** Only one payout / ledger correction at a time may read the balance and write a ledger entry (Phase 12b shares it). */
    public const PAYOUT_LOCK = 'fund-ledger-debit';

    /**
     * GET /api/admin/assistance?status=&reason_type=&member=&search=&from=&to=&sort=newest|oldest&page=
     * search = case ID, applicant name / mobile, "applying for" name (Bengali digits accepted); from / to = submitted date.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->merge(['search' => BnDigits::toAscii($request->input('search'))]);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(AssistanceRequest::STATUSES)],
            'reason_type' => ['nullable', Rule::in(AssistanceRequest::REASONS)],
            'member' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'sort' => ['nullable', Rule::in(['newest', 'oldest'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $requests = AssistanceRequest::query()
            ->with(['user', 'documents', 'decider', 'payer'])
            ->withVoteCounts()
            ->when($filters['status'] ?? null, fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($filters['reason_type'] ?? null, fn (Builder $q, string $r) => $q->where('reason_type', $r))
            ->when($filters['member'] ?? null, fn (Builder $q, int $id) => $q->where('user_id', $id))
            ->when(trim($filters['search'] ?? ''), function (Builder $q, string $search) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $q->where(fn (Builder $w) => $w->where('case_id', 'like', $like)
                    ->orWhere('applying_for_name', 'like', $like)
                    ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', $like)->orWhere('phone', 'like', $like)));
            })
            ->when($filters['from'] ?? null, fn (Builder $q, string $d) => $q->whereDate('created_at', '>=', $d))
            ->when($filters['to'] ?? null, fn (Builder $q, string $d) => $q->whereDate('created_at', '<=', $d))
            ->when(($filters['sort'] ?? 'newest') === 'oldest', fn (Builder $q) => $q->oldest('id'), fn (Builder $q) => $q->latest('id'))
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return AssistanceRequestResource::collection($requests)->additional([
            'meta' => ['fund_balance' => FundFigures::ledgerBalance()],
        ]);
    }

    /** GET /api/admin/assistance/{id} — with who voted how and the current fund balance (for the payout form). */
    public function show(int $assistance): JsonResponse
    {
        return response()->json([
            'data' => new AssistanceRequestResource($this->loadFull(AssistanceRequest::findOrFail($assistance))),
            'meta' => ['fund_balance' => FundFigures::ledgerBalance()],
        ]);
    }

    /** POST /api/admin/assistance/{id}/approve {amount_approved, note?} — any whole taka ≥ 1 (may differ from the request). */
    public function approve(Request $request, int $assistance): JsonResponse
    {
        $request->merge(['amount_approved' => BnDigits::toAscii($request->input('amount_approved'))]);
        $data = $request->validate([
            'amount_approved' => ['required', 'integer', 'min:1', 'max:'.AssistanceRequest::MAX_AMOUNT],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->decide($request, $assistance, function (AssistanceRequest $r) use ($request, $data) {
            $r->status = AssistanceRequest::STATUS_APPROVED;
            $r->amount_approved = (int) $data['amount_approved'];
            $r->decision_note = trim($data['note'] ?? '') ?: null;
            $r->save(); // still open until paid

            AuditLog::record($request, 'assistance.approved', $r, [
                ...$this->details($r, AssistanceRequest::STATUS_APPROVED),
                'amount_approved' => $r->amount_approved,
                'note' => $r->decision_note,
            ]);
        }, 'messages.assistance_approved');
    }

    /** POST /api/admin/assistance/{id}/reject {reason, note?} */
    public function reject(Request $request, int $assistance): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->decide($request, $assistance, function (AssistanceRequest $r) use ($request, $data) {
            $r->status = AssistanceRequest::STATUS_REJECTED;
            $r->rejection_reason = trim($data['reason']);
            $r->decision_note = trim($data['note'] ?? '') ?: null;
            $r->open_key = null;
            $r->save();

            AuditLog::record($request, 'assistance.rejected', $r, [
                ...$this->details($r, AssistanceRequest::STATUS_REJECTED),
                'reason' => $r->rejection_reason,
                'note' => $r->decision_note,
            ]);
        }, 'messages.assistance_rejected');
    }

    /**
     * POST /api/admin/assistance/{id}/pay (multipart) {method, payout_date (Y-m-d, not in the future), transaction_id?,
     * note?, payout_proof?} — once, for the full approved amount; writes a "disbursement" debit to the fund ledger.
     */
    public function pay(Request $request, int $assistance): JsonResponse
    {
        $model = AssistanceRequest::findOrFail($assistance);
        if ($model->user_id === $request->user()->id) {
            return $this->ownRequest();
        }

        $request->merge([
            'transaction_id' => is_string($request->input('transaction_id'))
                ? mb_strtoupper(preg_replace('/\s+/u', '', $request->input('transaction_id')))
                : $request->input('transaction_id'),
            'payout_date' => BnDigits::toAscii($request->input('payout_date')),
        ]);

        $data = $request->validate([
            'method' => ['required', Rule::in(Contribution::METHODS)],
            'payout_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'transaction_id' => ['nullable', 'string', 'max:50', 'regex:/^[A-Z0-9\-\/]+$/'],
            'note' => ['nullable', 'string', 'max:500'],
            'payout_proof' => ['nullable', 'file', 'mimes:'.AssistanceRequest::FILE_MIMES, 'extensions:'.AssistanceRequest::FILE_MIMES, 'max:'.AssistanceRequest::FILE_MAX_KB],
        ]);

        $file = $request->file('payout_proof');
        $proof = $file ? [
            'path' => $file->store('assistance-payouts', AssistanceRequest::FILE_DISK),
            'mime' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => $file->getSize(),
        ] : null;

        try {
            $result = Cache::lock(self::PAYOUT_LOCK, 30)->block(10, fn () => DB::transaction(function () use ($request, $assistance, $data, $proof) {
                $r = AssistanceRequest::lockForUpdate()->findOrFail($assistance);
                if ($r->status !== AssistanceRequest::STATUS_APPROVED) {
                    return 'not_approved';
                }

                $balance = FundFigures::ledgerBalance();
                if ($balance < $r->amount_approved) {
                    return ['balance' => number_format($balance), 'amount' => number_format($r->amount_approved)];
                }

                LedgerEntry::create([
                    'type' => LedgerEntry::TYPE_DISBURSEMENT,
                    'amount' => $r->amount_approved,
                    'user_id' => $r->user_id,
                    'assistance_request_id' => $r->id,
                    'reason' => $r->case_id,
                    'entry_date' => $data['payout_date'],
                    'created_by' => $request->user()->id,
                ]);

                $r->forceFill([
                    'status' => AssistanceRequest::STATUS_PAID,
                    'open_key' => null,
                    'payout_method' => $data['method'],
                    'payout_transaction_id' => ($data['transaction_id'] ?? '') ?: null,
                    'payout_date' => $data['payout_date'],
                    'payout_note' => trim($data['note'] ?? '') ?: null,
                    'payout_proof_path' => $proof['path'] ?? null,
                    'payout_proof_mime' => $proof['mime'] ?? null,
                    'payout_proof_size' => $proof['size'] ?? null,
                    'paid_by' => $request->user()->id,
                    'paid_at' => now(),
                ])->save();

                AuditLog::record($request, 'assistance.paid', $r, [
                    ...$this->details($r, AssistanceRequest::STATUS_PAID, AssistanceRequest::STATUS_APPROVED),
                    'amount' => $r->amount_approved,
                    'method' => $r->payout_method,
                    'transaction_id' => $r->payout_transaction_id,
                    'payout_date' => $data['payout_date'],
                    'balance_before' => $balance,
                ]);

                return $r;
            }));
        } catch (LockTimeoutException $e) {
            AssistanceRequest::deleteFiles([$proof['path'] ?? null]);

            return response()->json(['message' => __('messages.assistance_try_again')], 409);
        } catch (Throwable $e) {
            AssistanceRequest::deleteFiles([$proof['path'] ?? null]);
            throw $e;
        }

        if (! $result instanceof AssistanceRequest) {
            AssistanceRequest::deleteFiles([$proof['path'] ?? null]);

            return response()->json([
                'message' => $result === 'not_approved'
                    ? __('messages.assistance_not_approved')
                    : __('messages.assistance_balance_low', $result),
            ], 422);
        }

        Cache::forget(PublicHomeController::STATS_CACHE_KEY);
        Notifier::aidDecided($result);

        return response()->json([
            'message' => __('messages.assistance_paid'),
            'data' => new AssistanceRequestResource($this->loadFull($result)),
            'meta' => ['fund_balance' => FundFigures::ledgerBalance()],
        ]);
    }

    /** Shared approve / reject: not your own request, row lock, only while "submitted" (votes close here). */
    private function decide(Request $request, int $id, callable $apply, string $messageKey): JsonResponse
    {
        $model = AssistanceRequest::findOrFail($id);
        if ($model->user_id === $request->user()->id) {
            return $this->ownRequest();
        }

        $result = DB::transaction(function () use ($request, $id, $apply) {
            $r = AssistanceRequest::lockForUpdate()->findOrFail($id);
            if (! $r->isSubmitted()) {
                return null;
            }
            $r->decided_by = $request->user()->id;
            $r->decided_at = now();
            $apply($r);

            return $r;
        });

        if (! $result) {
            return response()->json(['message' => __('messages.assistance_not_submitted')], 422);
        }
        Notifier::aidDecided($result);

        return response()->json([
            'message' => __($messageKey),
            'data' => new AssistanceRequestResource($this->loadFull($result)),
        ]);
    }

    private function loadFull(AssistanceRequest $r): AssistanceRequest
    {
        return $r->load(['user', 'documents', 'decider', 'payer', 'votes.user'])
            ->loadCount([
                'votes as votes_yes' => fn (Builder $v) => $v->where('in_favour', true),
                'votes as votes_no' => fn (Builder $v) => $v->where('in_favour', false),
            ]);
    }

    /** @return array<string, mixed> */
    private function details(AssistanceRequest $r, string $new, string $old = AssistanceRequest::STATUS_SUBMITTED): array
    {
        return [
            'case_id' => $r->case_id,
            'applicant_id' => $r->user_id,
            'status' => ['old' => $old, 'new' => $new],
            'amount_requested' => $r->amount_requested,
            'votes' => AssistanceRequestResource::voteCounts($r),
        ];
    }

    private function ownRequest(): JsonResponse
    {
        return response()->json(['message' => __('messages.assistance_own')], 403);
    }
}
