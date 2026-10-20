<?php

namespace App\Http\Controllers;

use App\Models\Contribution;
use App\Models\User;
use App\Support\Dues;
use App\Support\FundFigures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Logged-in dashboard (MASTER_SPEC §37a). Read-only. */
class DashboardController extends Controller
{
    /**
     * GET /api/dashboard/summary — numbers for the dashboard cards, by role:
     *  - admin / super_admin: scope "all", fund money + active members + pending registrations / payments
     *  - committee:           scope "all", the same without pending registrations / payments (null)
     *  - member:              scope "own", only their own dues — no data about other members
     * Money = whole taka. Disbursed = aid payouts (Phase 8a); balance = full ledger incl. adjustments / reversals (Phase 12b, demo left out).
     */
    public function summary(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->role === User::ROLE_MEMBER) {
            $months = Dues::forMember($user);
            $due = $months->where('status', 'due');

            return response()->json([
                'data' => [
                    'scope' => 'own',
                    'total_paid' => $months->where('status', 'paid')->sum('amount'),
                    'total_pending' => $months->where('status', 'pending')->sum('amount'),
                    'total_due' => $due->sum('amount'),
                    'months_due' => $due->count(),
                ],
            ]);
        }

        $isAdmin = in_array($user->role, [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN], true);
        $collected = FundFigures::collected();
        $outstanding = FundFigures::outstanding();

        return response()->json([
            'data' => [
                'scope' => 'all',
                'active_members' => User::where('status', User::STATUS_APPROVED)->count(),
                'pending_registrations' => $isAdmin
                    ? User::where('status', User::STATUS_PENDING)->count()
                    : null,
                'pending_payments' => $isAdmin
                    ? Contribution::where('status', Contribution::STATUS_PENDING)->count()
                    : null,
                'total_collected' => $collected,
                'collected_this_month' => FundFigures::collected(thisMonthOnly: true),
                'outstanding_dues' => $outstanding['total_due'],
                'members_with_dues' => $outstanding['members_with_dues'],
                'total_disbursed' => FundFigures::disbursed(), // aid payouts (Phase 8a)
                'total_expenses' => FundFigures::expenses(), // expenses module
                'fund_balance' => FundFigures::balance(), // full ledger incl. corrections (Phase 12b)
                'start_month' => Dues::startMonth()->format('Y-m'),
            ],
        ]);
    }
}
