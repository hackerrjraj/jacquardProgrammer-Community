<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PublicHomeController;
use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Support\BnDigits;
use App\Support\FundFigures;
use App\Support\FundIntegrity;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Fund ledger + corrections (MASTER_SPEC §34, Phase 12b) — Super Admin only (route middleware).
 * Existing entries are never changed or deleted: a correction is a NEW entry —
 *  - adjustment: money in / out with a required reason;
 *  - reversal: cancels one earlier entry (same amount) with a required reason; once per entry, never of a reversal.
 * A correction may not take the fund below zero. Every correction is written to the audit log.
 */
class LedgerController extends Controller
{
    public const PER_PAGE = 20;

    /** GET /api/admin/ledger?type=&page= — newest first, with the full-ledger balance. */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::in(LedgerEntry::TYPES)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = LedgerEntry::query()
            ->with(['user:id,name', 'creator:id,name', 'assistanceRequest:id,case_id', 'reverses', 'reversal:id,reverses_id'])
            ->when($filters['type'] ?? null, fn ($q, string $t) => $q->where('type', $t))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn (LedgerEntry $e) => self::entry($e))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                ...self::figures(),
            ],
        ]);
    }

    /** POST /api/admin/ledger/adjustments {direction: in|out, amount, reason} */
    public function adjust(Request $request): JsonResponse
    {
        $request->merge(['amount' => BnDigits::toAscii($request->input('amount'))]);
        $data = $request->validate([
            'direction' => ['required', Rule::in([LedgerEntry::DIRECTION_IN, LedgerEntry::DIRECTION_OUT])],
            'amount' => ['required', 'integer', 'min:1', 'max:10000000'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $amount = (int) $data['amount'];
        $effect = $data['direction'] === LedgerEntry::DIRECTION_OUT ? -$amount : $amount;

        return $this->write($request, $effect, function (int $before) use ($request, $data, $amount, $effect) {
            $entry = LedgerEntry::create([
                'type' => LedgerEntry::TYPE_ADJUSTMENT,
                'direction' => $data['direction'],
                'amount' => $amount,
                'reason' => trim($data['reason']),
                'entry_date' => now()->toDateString(),
                'created_by' => $request->user()->id,
            ]);
            AuditLog::record($request, 'ledger.adjustment', $entry, [
                'direction' => $entry->direction,
                'amount' => $amount,
                'reason' => $entry->reason,
                'balance' => ['old' => $before, 'new' => $before + $effect],
            ]);

            return $entry;
        }, 'messages.ledger_adjusted');
    }

    /** POST /api/admin/ledger/{entry}/reverse {reason} */
    public function reverse(Request $request, int $entry): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $original = LedgerEntry::findOrFail($entry);

        if ($original->type === LedgerEntry::TYPE_REVERSAL) {
            return response()->json(['message' => __('messages.ledger_reversal_of_reversal')], 422);
        }
        $effect = -$original->effect();

        // Checked inside the lock, before the balance check (the unique index on reverses_id is the last guard).
        $already = fn () => LedgerEntry::where('reverses_id', $original->id)->exists() ? 'already' : null;

        return $this->write($request, $effect, function (int $before) use ($request, $data, $original, $effect) {
            $reversal = LedgerEntry::create([
                'type' => LedgerEntry::TYPE_REVERSAL,
                'amount' => $original->amount,
                'user_id' => $original->user_id,
                'reverses_id' => $original->id,
                'reason' => trim($data['reason']),
                'entry_date' => now()->toDateString(),
                'created_by' => $request->user()->id,
            ]);
            AuditLog::record($request, 'ledger.reversal', $reversal, [
                'reverses_id' => $original->id,
                'reversed_type' => $original->type,
                'amount' => $original->amount,
                'case_id' => $original->assistanceRequest?->case_id,
                'payment_id' => $original->contribution_id,
                'reason' => $reversal->reason,
                'balance' => ['old' => $before, 'new' => $before + $effect],
            ]);

            return $reversal;
        }, 'messages.ledger_reversed', $already);
    }

    /** GET /api/admin/ledger/integrity — read-only list of mismatches (never fixes anything). */
    public function integrity(): JsonResponse
    {
        return response()->json(['data' => [...FundIntegrity::run(), 'checked_at' => now()->toIso8601String()]]);
    }

    /**
     * Shared write: one correction at a time (same lock as the aid payout), balance read inside the transaction,
     * never below zero. `$refuse` (optional) runs first and returns "already" when the entry was reversed meanwhile.
     */
    private function write(Request $request, int $effect, callable $create, string $messageKey, ?callable $refuse = null): JsonResponse
    {
        try {
            $result = Cache::lock(AssistanceController::PAYOUT_LOCK, 30)->block(10, fn () => DB::transaction(function () use ($effect, $create, $refuse) {
                if ($refuse && ($reason = $refuse()) !== null) {
                    return $reason;
                }
                $before = FundFigures::ledgerBalance();
                if ($before + $effect < 0) {
                    return ['negative' => $before + $effect];
                }

                return $create($before);
            }));
        } catch (LockTimeoutException) {
            return response()->json(['message' => __('messages.ledger_try_again')], 409);
        }

        if ($result === 'already') {
            return response()->json(['message' => __('messages.ledger_already_reversed')], 422);
        }
        if (is_array($result)) {
            return response()->json(['message' => __('messages.ledger_balance_negative', ['balance' => number_format($result['negative'])])], 422);
        }

        Cache::forget(PublicHomeController::STATS_CACHE_KEY);
        $figures = self::figures();

        return response()->json([
            'message' => __($messageKey, ['balance' => number_format($figures['balance'])]),
            'data' => self::entry($result->load(['user:id,name', 'creator:id,name', 'assistanceRequest:id,case_id', 'reverses', 'reversal:id,reverses_id'])),
            'meta' => $figures,
        ], 201);
    }

    /** @return array{balance: int, public_balance: int, corrections: int} */
    private static function figures(): array
    {
        return [
            'balance' => FundFigures::ledgerBalance(),     // the real money: every entry (used for payouts too)
            'public_balance' => FundFigures::balance(),     // Home / Fund Report / dashboard (demo left out)
            'corrections' => FundFigures::corrections(),
        ];
    }

    /** @return array<string, mixed> */
    private static function entry(LedgerEntry $e): array
    {
        return [
            'id' => $e->id,
            'type' => $e->type,
            'direction' => $e->direction,
            'amount' => $e->amount,
            'effect' => $e->effect(),
            'entry_date' => $e->entry_date?->toDateString(),
            'reason' => $e->reason,
            'member' => $e->user ? ['id' => $e->user->id, 'name' => $e->user->name] : null,
            'case_id' => $e->assistanceRequest?->case_id,
            'payment_id' => $e->contribution_id,
            'reverses' => $e->reverses ? ['id' => $e->reverses->id, 'type' => $e->reverses->type] : null,
            'reversed_by' => $e->reversal?->id,
            'can_reverse' => $e->type !== LedgerEntry::TYPE_REVERSAL && ! $e->reversal,
            'created_by' => $e->creator?->name,
            'created_at' => $e->created_at?->toIso8601String(),
        ];
    }
}
