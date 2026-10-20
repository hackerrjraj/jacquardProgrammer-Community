<?php

namespace App\Http\Controllers;

use App\Http\Resources\ContributionResource;
use App\Models\AuditLog;
use App\Models\Contribution;
use App\Models\User;
use App\Support\BdPhone;
use App\Support\Dues;
use App\Support\PaymentAccounts;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * A member's own contribution payments (MASTER_SPEC §26, §28) — any approved account (route middleware).
 * Verification by an admin is in Admin\ContributionController.
 */
class ContributionController extends Controller
{
    public const PER_PAGE = 20;

    /** GET /api/contributions — own payments, newest first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $contributions = Contribution::query()
            ->where('user_id', $request->user()->id)
            ->with('months')
            ->latest('id')
            ->paginate(self::PER_PAGE);

        return ContributionResource::collection($contributions);
    }

    /** GET /api/contributions/dues — own months since approval: paid / pending / due, with totals. */
    public function dues(Request $request): JsonResponse
    {
        $months = Dues::forMember($request->user());
        $unpaid = $months->where('status', 'due');

        return response()->json([
            'data' => [
                'months' => $months->values(),
                'total_paid' => $months->where('status', 'paid')->sum('amount'),
                'total_pending' => $months->where('status', 'pending')->sum('amount'),
                'total_due' => $unpaid->sum('amount'),
                'months_due' => $unpaid->count(),
            ],
        ]);
    }

    /** GET /api/contributions/accounts — where to send the money: the shown receiving accounts + instructions (Phase 7e). */
    public function accounts(): JsonResponse
    {
        return response()->json(['data' => PaymentAccounts::forMembers()]);
    }

    /**
     * POST /api/contributions (multipart or JSON): method, sender_number (bKash/Nagad/Rocket), transaction_id (not for cash),
     * amount (whole taka), months[] ("YYYY-MM", unpaid months only), proof? (screenshot). Starts as "pending".
     */
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->merge([
            'sender_number' => BdPhone::normalize($request->input('sender_number')),
            // TxIDs never contain spaces; compare without case.
            'transaction_id' => is_string($request->input('transaction_id'))
                ? mb_strtoupper(preg_replace('/\s+/u', '', $request->input('transaction_id')))
                : $request->input('transaction_id'),
        ]);

        $data = $request->validate([
            'method' => ['required', Rule::in(Contribution::METHODS)],
            'sender_number' => [
                Rule::requiredIf(in_array($request->input('method'), Contribution::MOBILE_METHODS, true)),
                'nullable', 'string', 'regex:'.BdPhone::PATTERN,
            ],
            'transaction_id' => [
                Rule::requiredIf($request->input('method') !== Contribution::METHOD_CASH),
                'nullable', 'string', 'max:50', 'regex:/^[A-Z0-9\-\/]+$/',
            ],
            'amount' => ['required', 'integer', 'min:1', 'max:10000000'],
            'months' => ['required', 'array', 'min:1', 'max:'.Contribution::MAX_MONTHS],
            'months.*' => ['required', 'string', 'date_format:Y-m', 'distinct'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:'.Contribution::PROOF_MAX_KB],
        ]);

        $method = $data['method'];
        $isMobile = in_array($method, Contribution::MOBILE_METHODS, true);
        $txid = $method === Contribution::METHOD_CASH ? null : $data['transaction_id'];
        $txidKey = Contribution::txidKey($method, $txid);

        // Only months that are still unpaid (not paid, not waiting for verification) can be chosen.
        $dues = Dues::forMember($user);
        $chosen = collect($data['months'])->sort()->values();
        $notPayable = $chosen->reject(fn (string $m) => ($dues->get($m)['status'] ?? null) === 'due');
        if ($notPayable->isNotEmpty()) {
            throw ValidationException::withMessages([
                'months' => __('messages.contribution_month_not_payable', ['months' => $notPayable->implode(', ')]),
            ]);
        }

        // §26 default: the amount must equal the dues of the chosen months exactly.
        $total = $chosen->sum(fn (string $m) => $dues->get($m)['amount']);
        if ((int) $data['amount'] !== $total) {
            throw ValidationException::withMessages([
                'amount' => __('messages.contribution_amount_mismatch', ['total' => $total]),
            ]);
        }

        if ($txidKey && Contribution::where('txid_key', $txidKey)->exists()) {
            throw $this->duplicateTxid();
        }

        $contribution = new Contribution([
            'method' => $method,
            'sender_number' => $isMobile ? $data['sender_number'] : null,
            'transaction_id' => $txid,
            'txid_key' => $txidKey,
            'amount' => $total,
        ]);
        $contribution->user_id = $user->id;

        $file = $request->file('proof');
        if ($file) {
            $contribution->proof_path = $file->store('contributions', Contribution::PROOF_DISK); // random file name
            $contribution->proof_mime = $file->getMimeType() ?? 'application/octet-stream';
            $contribution->proof_size = $file->getSize();
        }

        try {
            DB::transaction(function () use ($request, $contribution, $chosen, $dues, $user) {
                $contribution->save();
                foreach ($chosen as $month) {
                    $contribution->months()->create([
                        'user_id' => $user->id,
                        'month' => $month.'-01',
                        'amount' => $dues->get($month)['amount'],
                    ]);
                }

                AuditLog::record($request, 'contribution.submitted', $contribution, [
                    'contribution_id' => $contribution->id,
                    'amount' => $contribution->amount,
                    'method' => $contribution->method,
                    'transaction_id' => $contribution->transaction_id,
                    'months' => $chosen->all(),
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            // Two submissions at the same moment: same TxID or same month.
            $contribution->deleteProofFile();
            throw $txidKey && Contribution::where('txid_key', $txidKey)->exists()
                ? $this->duplicateTxid()
                : ValidationException::withMessages(['months' => __('messages.contribution_month_not_payable', ['months' => $chosen->implode(', ')])]);
        } catch (Throwable $e) {
            $contribution->deleteProofFile();
            throw $e;
        }

        return response()->json([
            'message' => __('messages.contribution_submitted'),
            'data' => new ContributionResource($contribution->load('months')),
        ], 201);
    }

    /** GET /api/contributions/{id}/proof — the screenshot; the member themself, admin or super_admin only. */
    public function proof(Request $request, int $contribution): StreamedResponse|JsonResponse
    {
        $model = Contribution::findOrFail($contribution);

        if (! $model->proofVisibleTo($request->user())) {
            return response()->json(['message' => __('messages.forbidden')], 403);
        }

        $disk = Storage::disk(Contribution::PROOF_DISK);
        if (! $model->proof_path || ! $disk->exists($model->proof_path)) {
            abort(404);
        }

        return $disk->response($model->proof_path, null, [
            'Content-Type' => $model->proof_mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function duplicateTxid(): ValidationException
    {
        return ValidationException::withMessages(['transaction_id' => __('messages.contribution_duplicate_txid')]);
    }
}
