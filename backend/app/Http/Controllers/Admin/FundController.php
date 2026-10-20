<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contribution;
use App\Models\ContributionMonth;
use App\Models\User;
use App\Support\Dues;
use App\Support\FundFigures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only fund views (MASTER_SPEC §28, §37a) — admin, super_admin and committee (read-only).
 * Demo programmers are left out of the fund-wide figures.
 */
class FundController extends Controller
{
    /** GET /api/admin/fund/monthly — one row per month from the fund start month to now, newest first. */
    public function monthly(): JsonResponse
    {
        $rows = FundFigures::monthly();

        return response()->json([
            'data' => [
                'start_month' => Dues::startMonth()->format('Y-m'),
                'members' => FundFigures::members()->count(),
                'months' => $rows,
                'totals' => [
                    'paid_amount' => $rows->sum('paid_amount'),
                    'pending_amount' => $rows->sum('pending_amount'),
                    'due_amount' => $rows->sum('due_amount'),
                ],
            ],
        ]);
    }

    /**
     * GET /api/admin/members/{member}/payments — the member's month-by-month history (§37a.5): each month with
     * paid / pending / due, amount and the payment that covers it (method, TxID, submitted + approved date),
     * plus totals. Accounts that owe no dues (pending / rejected registrations) get an empty list.
     * Phase 7f: a due month also lists its rejected attempts (the month itself stays "due"). `proof_url` (screenshot)
     * and the rejection reason are filled only for admin / super_admin — committee gets null (same rule as the proof route).
     */
    public function memberHistory(Request $request, User $member): JsonResponse
    {
        MemberController::abortUnlessVisible($request, $member);
        $isAdmin = $request->user()->isAdmin();
        $months = $member->owesDues() ? Dues::forMember($member) : collect();
        // "YYYY-MM" => rejected contribution ids, newest first.
        $rejected = $months->isEmpty() ? collect() : ContributionMonth::query()
            ->where('contribution_months.user_id', $member->id)
            ->join('contributions', 'contributions.id', '=', 'contribution_months.contribution_id')
            ->where('contributions.status', Contribution::STATUS_REJECTED)
            ->orderByDesc('contributions.id')
            ->get(['contribution_months.month', 'contribution_months.contribution_id'])
            ->groupBy(fn (ContributionMonth $m) => $m->month->format('Y-m'))
            ->map(fn ($rows) => $rows->pluck('contribution_id')->unique()->values());
        $payments = Contribution::query()
            ->whereIn('id', $months->pluck('contribution_id')->filter()->merge($rejected->flatten())->unique())
            ->get(['id', 'method', 'transaction_id', 'proof_path', 'rejection_reason', 'created_at', 'reviewed_at'])
            ->keyBy('id');
        $due = $months->where('status', 'due');
        $proofUrl = fn (Contribution $p) => $isAdmin && $p->proof_path ? url("/api/contributions/{$p->id}/proof") : null;

        return response()->json([
            'data' => [
                'member' => ['id' => $member->id, 'name' => $member->name, 'status' => $member->status],
                'owes_dues' => $member->owesDues(),
                'start_month' => Dues::startMonth()->format('Y-m'),
                // Newest first, like the member's own page.
                'months' => $months->reverse()->values()->map(function (array $m) use ($payments, $rejected, $proofUrl, $isAdmin) {
                    $p = $m['contribution_id'] ? $payments->get($m['contribution_id']) : null;

                    return [
                        'month' => $m['month'],
                        'amount' => $m['amount'],
                        'status' => $m['status'],
                        'payment' => $p ? [
                            'id' => $p->id,
                            'method' => $p->method,
                            'transaction_id' => $p->transaction_id,
                            'submitted_at' => $p->created_at?->toIso8601String(),
                            'approved_at' => $m['status'] === 'paid' ? $p->reviewed_at?->toIso8601String() : null,
                            'proof_url' => $proofUrl($p),
                        ] : null,
                        'rejected' => $m['status'] !== 'due' ? [] : $rejected->get($m['month'], collect())
                            ->map(fn (int $id) => $payments->get($id))
                            ->filter()
                            ->map(fn (Contribution $r) => [
                                'id' => $r->id,
                                'method' => $r->method,
                                'transaction_id' => $r->transaction_id,
                                'submitted_at' => $r->created_at?->toIso8601String(),
                                'rejected_at' => $r->reviewed_at?->toIso8601String(),
                                'reason' => $isAdmin ? $r->rejection_reason : null,
                                'proof_url' => $proofUrl($r),
                            ])->values(),
                    ];
                }),
                'total_paid' => $months->where('status', 'paid')->sum('amount'),
                'total_pending' => $months->where('status', 'pending')->sum('amount'),
                'total_due' => $due->sum('amount'),
                'months_due' => $due->count(),
            ],
        ]);
    }
}
