<?php

namespace App\Http\Controllers;

use App\Models\AssistanceRequest;
use App\Models\Expense;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Support\Dues;
use App\Support\FundFigures;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Fund Report (MASTER_SPEC §33) — MEMBERS ONLY (login + approved account; Phase 12a made it public, changed by the owner). Whole taka, demo programmers left out everywhere.
 * Never shown: who paid what, supporting documents, payout proofs, descriptions, typed "other" reasons, and the
 * recipient's name unless they ticked the consent box when applying.
 */
class FundReportController extends Controller
{
    public const CASES_PER_PAGE = 20;

    /**
     * GET /api/fund-report — totals (same figures as the Home stats) + one row per month from the fund start month
     * (or the first ledger entry, if older) to the current month, newest first: money collected / paid out that month.
     */
    public function summary(): JsonResponse
    {
        $collected = FundFigures::collected();
        $disbursed = FundFigures::disbursed();

        $sums = DB::table('ledger_entries')
            ->whereIn('type', [LedgerEntry::TYPE_CONTRIBUTION, LedgerEntry::TYPE_DISBURSEMENT])
            ->whereIn('user_id', User::query()->notDemo()->select('users.id'))
            ->groupByRaw('substr(entry_date, 1, 7), type')
            ->selectRaw('substr(entry_date, 1, 7) as month, type, sum(amount) as amount')
            ->get()
            ->groupBy('month');

        $first = Dues::startMonth();
        $oldest = $sums->keys()->sort()->first();
        if ($oldest !== null && $oldest < $first->format('Y-m')) {
            $first = CarbonImmutable::createFromFormat('!Y-m', $oldest);
        }

        $monthly = [];
        for ($m = Dues::currentMonth(); $m->gte($first); $m = $m->subMonth()) {
            $rows = $sums->get($m->format('Y-m'), collect());
            $monthly[] = [
                'month' => $m->format('Y-m'),
                'collected' => (int) ($rows->firstWhere('type', LedgerEntry::TYPE_CONTRIBUTION)->amount ?? 0),
                'disbursed' => (int) ($rows->firstWhere('type', LedgerEntry::TYPE_DISBURSEMENT)->amount ?? 0),
            ];
        }

        return response()->json(['data' => [
            'total_collected' => $collected,
            'total_disbursed' => $disbursed,
            'total_expenses' => FundFigures::expenses(),
            // Phase 12b: corrections (adjustments + reversals) only as one net total — no reasons, no names.
            'corrections' => FundFigures::corrections(),
            'fund_balance' => FundFigures::balance(), // = collected − disbursed + corrections − expenses
            'cases_paid' => FundFigures::casesHelped(),
            'monthly' => $monthly,
            'updated_at' => now()->toIso8601String(),
        ]]);
    }

    /**
     * GET /api/fund-report/expenses?page= — expenses, newest first, 20 per page: date, category, purpose, amount only
     * (never who was paid, the receipt or who entered it).
     */
    public function expenses(): JsonResponse
    {
        $page = Expense::query()
            ->with('category:id,name_bn,name_en')
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->paginate(self::CASES_PER_PAGE);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Expense $e) => [
                'id' => $e->id,
                'date' => $e->expense_date?->toDateString(),
                'category' => $e->category ? ['name_bn' => $e->category->name_bn, 'name_en' => $e->category->name_en] : null,
                'purpose' => $e->purpose,
                'amount' => $e->amount,
            ])->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * GET /api/fund-report/cases?page= — paid aid cases, newest payout first, 20 per page. Only cases with their
     * ledger payout entry are listed, so the list always matches the "paid out" total (a status set by hand never shows).
     * Case ID, reason type, amount paid (the ledger debit), payout date, recipient name only with consent.
     */
    public function cases(): JsonResponse
    {
        $page = AssistanceRequest::query()
            ->where('status', AssistanceRequest::STATUS_PAID)
            ->whereHas('ledgerEntry', fn ($q) => $q->where('type', LedgerEntry::TYPE_DISBURSEMENT))
            ->whereIn('user_id', User::query()->notDemo()->select('users.id'))
            ->with(['user:id,name', 'ledgerEntry:id,assistance_request_id,amount'])
            ->orderByDesc('payout_date')
            ->orderByDesc('id')
            ->paginate(self::CASES_PER_PAGE);

        return response()->json([
            'data' => $page->getCollection()->map(fn (AssistanceRequest $r) => [
                'case_id' => $r->case_id,
                'reason' => $r->reason_type,
                'amount' => (int) $r->ledgerEntry->amount,
                'date' => $r->payout_date?->toDateString(),
                // The person helped: the family member named on the request, otherwise the member.
                'recipient_name' => $r->consent_public_name ? ($r->applying_for_name ?: $r->user?->name) : null,
            ])->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
