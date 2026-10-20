<?php

namespace App\Support;

use App\Models\Contribution;
use App\Models\ContributionMonth;
use App\Models\ContributionRate;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Monthly dues (MASTER_SPEC §25, §28). Owner decision (Phase 7d): the fund has one START MONTH (setting
 * `fund_start_month`, default 2026-10). Every member owes every month from the start month to the current
 * month — whenever they joined, also while suspended, and re-approval changes nothing. No advance payments.
 * Each month is "paid" (in an approved payment), "pending" (waiting for verification) or "due".
 */
class Dues
{
    public const DEFAULT_START_MONTH = '2026-10';

    /** The fund start month (1st day). A broken setting falls back to the default. */
    public static function startMonth(): CarbonImmutable
    {
        $value = Setting::valueOf(Setting::FUND_START_MONTH, self::DEFAULT_START_MONTH);
        try {
            return CarbonImmutable::createFromFormat('!Y-m', (string) $value)->startOfMonth();
        } catch (Throwable) {
            return CarbonImmutable::createFromFormat('!Y-m', self::DEFAULT_START_MONTH);
        }
    }

    public static function currentMonth(): CarbonImmutable
    {
        return CarbonImmutable::now()->startOfMonth();
    }

    /**
     * The months every member owes right now: start month → current month, with each month's amount.
     *
     * @return Collection<string, int> "YYYY-MM" → amount, oldest first (empty before the start month)
     */
    public static function chargeableMonths(): Collection
    {
        $rates = ContributionRate::history();
        $months = collect();
        for ($m = self::startMonth(); $m->lte(self::currentMonth()); $m = $m->addMonth()) {
            $months->put($m->format('Y-m'), ContributionRate::amountFor($rates, $m));
        }

        return $months;
    }

    /** @return Collection<string, array{month: string, amount: int, status: string, contribution_id: int|null}> keyed by "YYYY-MM", oldest first */
    public static function forMember(User $member): Collection
    {
        $claims = ContributionMonth::query()
            ->where('contribution_months.user_id', $member->id)
            ->where('claim', true)
            ->join('contributions', 'contributions.id', '=', 'contribution_months.contribution_id')
            ->get(['contribution_months.month', 'contribution_months.amount', 'contribution_months.contribution_id', 'contributions.status'])
            ->keyBy(fn (ContributionMonth $m) => $m->month->format('Y-m'));

        $fundStart = self::startMonth();
        $start = $fundStart;
        // A month paid before a later change of the start month still shows (but is never "due").
        if ($claims->isNotEmpty()) {
            $earliest = CarbonImmutable::parse($claims->min(fn (ContributionMonth $m) => $m->month))->startOfMonth();
            $start = $start->min($earliest);
        }
        $end = self::currentMonth();

        $rates = ContributionRate::history();
        $months = collect();

        for ($month = $start; $month->lte($end); $month = $month->addMonth()) {
            $key = $month->format('Y-m');
            $claim = $claims->get($key);
            if (! $claim && $month->lt($fundStart)) {
                continue;
            }

            $months->put($key, [
                'month' => $key,
                // A month inside a payment keeps the amount it was submitted with.
                'amount' => $claim?->amount ?? ContributionRate::amountFor($rates, $month),
                'status' => match ($claim?->status) {
                    Contribution::STATUS_APPROVED => 'paid',
                    Contribution::STATUS_PENDING => 'pending',
                    default => 'due',
                },
                'contribution_id' => $claim?->contribution_id,
            ]);
        }

        return $months;
    }

    /**
     * A correlated sub-query over the claimed months (pending + approved) of the outer `users` row,
     * e.g. claimSub('sum(cm.amount)', approved: true) = total paid. $chargeable = only start → current month.
     */
    public static function claimSub(string $aggregate, bool $approvedOnly = false, bool $chargeable = false): Builder
    {
        return DB::table('contribution_months as cm')
            ->join('contributions as c', 'c.id', '=', 'cm.contribution_id')
            ->whereColumn('cm.user_id', 'users.id')
            ->where('cm.claim', true)
            ->when($approvedOnly, fn (Builder $q) => $q->where('c.status', Contribution::STATUS_APPROVED))
            ->when($chargeable, fn (Builder $q) => $q->whereBetween('cm.month', self::chargeableRange()))
            ->selectRaw($aggregate);
    }

    /** @return array{0: string, 1: string} first and last chargeable day as Y-m-d (end = last day, so a stored time part still matches) */
    public static function chargeableRange(): array
    {
        return [self::startMonth()->toDateString(), self::currentMonth()->endOfMonth()->toDateString()];
    }
}
