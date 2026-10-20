<?php

namespace App\Support;

use App\Models\Contribution;
use App\Models\Expense;
use App\Models\LedgerEntry;
use App\Models\User;
use Database\Seeders\DemoProgrammersSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fund-wide money figures for the dashboard (MASTER_SPEC §37a). Whole taka.
 * Collected = the fund ledger's contribution entries (approved payments only). Dues = members who owe
 * (approved + suspended, Dues rule of Phase 7d). The local demo programmers are left out everywhere.
 */
class FundFigures
{
    /** @return Builder<User> */
    public static function members(): Builder
    {
        return User::query()->owesDues()->notDemo();
    }

    /** Verified money: all time, or only entries dated in the current month. */
    public static function collected(bool $thisMonthOnly = false): int
    {
        return (int) LedgerEntry::query()
            ->where('ledger_entries.type', LedgerEntry::TYPE_CONTRIBUTION)
            ->whereIn('ledger_entries.user_id', User::query()->notDemo()->select('users.id'))
            ->when($thisMonthOnly, fn ($q) => $q->whereBetween('ledger_entries.entry_date', [
                Dues::currentMonth()->toDateString(),
                Dues::currentMonth()->endOfMonth()->toDateString(),
            ]))
            ->sum('ledger_entries.amount');
    }

    /** Aid paid out (ledger disbursement entries, Phase 8a), demo applicants left out like the collected figure. */
    public static function disbursed(): int
    {
        return (int) LedgerEntry::query()
            ->where('ledger_entries.type', LedgerEntry::TYPE_DISBURSEMENT)
            ->whereIn('ledger_entries.user_id', User::query()->notDemo()->select('users.id'))
            ->sum('ledger_entries.amount');
    }

    /** Total of all expenses (soft-deleted ones no longer count). */
    public static function expenses(): int
    {
        return (int) Expense::query()->sum('amount');
    }

    /** Aid cases paid out (one ledger debit per case), demo left out. */
    public static function casesHelped(): int
    {
        return LedgerEntry::query()
            ->where('ledger_entries.type', LedgerEntry::TYPE_DISBURSEMENT)
            ->whereNotNull('ledger_entries.assistance_request_id')
            ->whereIn('ledger_entries.user_id', User::query()->notDemo()->select('users.id'))
            ->count();
    }

    /**
     * Money really in the fund = the FULL ledger (MASTER_SPEC §34): contributions + adjustments − disbursements;
     * a reversal cancels the entry it reverses; minus all expenses. An aid payout is checked against this number (§32) — nothing left out.
     */
    public static function ledgerBalance(): int
    {
        return array_sum(self::effects(withoutDemo: false)) - self::expenses();
    }

    /**
     * The balance shown on Home, /fund-report and the dashboard (Phase 12b): the full ledger incl. adjustments and
     * reversals, only the demo programmers' entries (and reversals of them) left out.
     * = collected − disbursed + corrections − expenses.
     */
    public static function balance(): int
    {
        return array_sum(self::effects(withoutDemo: true)) - self::expenses();
    }

    /** Net effect of all adjustments + reversals (signed taka), demo left out — the public report shows only this total. */
    public static function corrections(): int
    {
        $e = self::effects(withoutDemo: true);

        return $e[LedgerEntry::TYPE_ADJUSTMENT] + $e[LedgerEntry::TYPE_REVERSAL];
    }

    /**
     * Signed effect on the balance per entry type. Amounts are unsigned in MySQL, so sums are read per
     * (type, direction, reversed entry's type / direction) and the sign is applied in PHP (never "amount × −1" in SQL).
     *
     * @return array<string, int>
     */
    private static function effects(bool $withoutDemo): array
    {
        $demo = $withoutDemo
            ? User::query()->where('email', 'like', '%@'.DemoProgrammersSeeder::EMAIL_DOMAIN)->pluck('id')->all()
            : [];

        $rows = DB::table('ledger_entries as e')
            ->leftJoin('ledger_entries as o', 'o.id', '=', 'e.reverses_id')
            ->when($demo, fn ($q) => $q
                ->where(fn ($w) => $w->whereNull('e.user_id')->orWhereNotIn('e.user_id', $demo))
                ->where(fn ($w) => $w->whereNull('o.user_id')->orWhereNotIn('o.user_id', $demo)))
            ->groupBy('e.type', 'e.direction', 'o.type', 'o.direction')
            ->selectRaw('e.type, e.direction, o.type as o_type, o.direction as o_direction, sum(e.amount) as amount')
            ->get();

        $effects = array_fill_keys(LedgerEntry::TYPES, 0);
        foreach ($rows as $r) {
            if ($r->type === LedgerEntry::TYPE_REVERSAL) {
                // A reversal whose original is missing has no effect (the integrity check lists it).
                $sign = $r->o_type === null ? 0 : -LedgerEntry::signOf($r->o_type, $r->o_direction);
            } else {
                $sign = LedgerEntry::signOf($r->type, $r->direction);
            }
            $effects[$r->type] = ($effects[$r->type] ?? 0) + $sign * (int) $r->amount;
        }

        return $effects;
    }

    /** @return array{total_due: int, members_with_dues: int, months_due: int} */
    public static function outstanding(): array
    {
        $months = Dues::chargeableMonths();
        $n = $months->count();
        $expected = (int) $months->sum();

        $rows = self::members()
            ->select('users.id')
            ->selectSub(Dues::claimSub('coalesce(sum(cm.amount), 0)', chargeable: true), 'claimed_amount')
            ->selectSub(Dues::claimSub('count(*)', chargeable: true), 'claimed_count')
            ->toBase()
            ->get();

        return [
            'total_due' => (int) $rows->sum(fn ($r) => max(0, $expected - (int) $r->claimed_amount)),
            'members_with_dues' => $rows->filter(fn ($r) => (int) $r->claimed_count < $n)->count(),
            'months_due' => (int) $rows->sum(fn ($r) => max(0, $n - (int) $r->claimed_count)),
        ];
    }

    /**
     * One row per chargeable month, newest first: how many of the members who owe have paid it, are waiting
     * for a check, or still owe it, with the amounts.
     *
     * @return Collection<int, array<string, int|string>>
     */
    public static function monthly(): Collection
    {
        $months = Dues::chargeableMonths();
        $members = self::members()->count();

        $claims = DB::table('contribution_months as cm')
            ->join('contributions as c', 'c.id', '=', 'cm.contribution_id')
            ->where('cm.claim', true)
            ->whereIn('cm.user_id', self::members()->select('users.id'))
            ->whereBetween('cm.month', Dues::chargeableRange())
            ->groupBy('cm.month', 'c.status')
            ->selectRaw('cm.month, c.status, count(*) as members, sum(cm.amount) as amount')
            ->get()
            ->groupBy(fn ($r) => substr((string) $r->month, 0, 7));

        return $months->map(function (int $amount, string $month) use ($claims, $members) {
            $rows = $claims->get($month, collect());
            $paid = $rows->firstWhere('status', Contribution::STATUS_APPROVED);
            $pending = $rows->firstWhere('status', Contribution::STATUS_PENDING);
            $dueMembers = max(0, $members - (int) ($paid->members ?? 0) - (int) ($pending->members ?? 0));

            return [
                'month' => $month,
                'amount' => $amount,
                'members' => $members,
                'paid_members' => (int) ($paid->members ?? 0),
                'paid_amount' => (int) ($paid->amount ?? 0),
                'pending_members' => (int) ($pending->members ?? 0),
                'pending_amount' => (int) ($pending->amount ?? 0),
                'due_members' => $dueMembers,
                'due_amount' => $dueMembers * $amount,
            ];
        })->reverse()->values();
    }
}
