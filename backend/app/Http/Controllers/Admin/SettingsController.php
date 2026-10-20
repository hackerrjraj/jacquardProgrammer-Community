<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PublicHomeController;
use App\Models\AuditLog;
use App\Models\Contribution;
use App\Models\ContributionMonth;
use App\Models\ContributionRate;
use App\Models\Setting;
use App\Support\BdPhone;
use App\Support\Dues;
use App\Support\PaymentAccounts;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Fund Settings (MASTER_SPEC §25, §37 "Settings (Super Admin)") — super_admin only (route middleware):
 * monthly amount for future months (rate history), receiving accounts + instructions, fund start month.
 * Every change is written to the audit log and clears the cached public stats.
 */
class SettingsController extends Controller
{
    public const AMOUNT_MAX = 100000;

    /** The start month may be set at most this many months after the current month. */
    public const START_MONTH_AHEAD = 12;

    public const START_MONTH_MIN = '2020-01';

    /** "YYYY-MM" */
    private const MONTH_RE = '/^[0-9]{4}-(0[1-9]|1[0-2])$/';

    /** GET /api/admin/settings — everything the Fund Settings screen shows. */
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->payload()]);
    }

    /**
     * POST /api/admin/settings/rates {amount, effective_from: "YYYY-MM"} — a new monthly amount from a FUTURE month
     * (next month or later). Months up to now keep their amount. Saving again for the same month replaces it.
     */
    public function storeRate(Request $request): JsonResponse
    {
        $next = Dues::currentMonth()->addMonth()->format('Y-m');
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:'.self::AMOUNT_MAX],
            'effective_from' => ['required', 'string', 'regex:'.self::MONTH_RE],
        ]);
        // "YYYY-MM" strings compare correctly as text.
        if ($data['effective_from'] < $next) {
            throw ValidationException::withMessages(['effective_from' => __('messages.settings_rate_future', ['month' => $next])]);
        }
        $from = CarbonImmutable::createFromFormat('!Y-m', $data['effective_from']);
        $amount = (int) $data['amount'];

        $existing = ContributionRate::query()->whereDate('effective_from', $from->toDateString())->first();
        $before = ContributionRate::amountFor(
            ContributionRate::history()->reject(fn (ContributionRate $r) => $r->is($existing)),
            $from,
        );
        if ($existing?->amount === $amount || (! $existing && $before === $amount)) {
            throw ValidationException::withMessages(['amount' => __('messages.settings_rate_same')]);
        }

        $oldAmount = $existing?->amount ?? $before;
        DB::transaction(function () use ($request, $existing, $from, $amount, $oldAmount) {
            $rate = $existing ?? new ContributionRate(['effective_from' => $from->toDateString()]);
            $rate->amount = $amount;
            $rate->created_by = $request->user()->id;
            $rate->save();

            AuditLog::record($request, 'settings.rate_scheduled', $rate, [
                'effective_from' => $from->format('Y-m'),
                'old_amount' => $oldAmount,
                'new_amount' => $amount,
                'replaced_scheduled' => (bool) $existing,
            ]);
        });
        $this->clearCaches();

        return response()->json(['message' => __('messages.settings_rate_saved'), 'data' => $this->payload()]);
    }

    /** DELETE /api/admin/settings/rates/{rate} — cancel a change that has not started yet. */
    public function destroyRate(Request $request, ContributionRate $rate): JsonResponse
    {
        if ($rate->effective_from->lte(Dues::currentMonth())) {
            return response()->json(['message' => __('messages.settings_rate_started')], 422);
        }

        DB::transaction(function () use ($request, $rate) {
            AuditLog::record($request, 'settings.rate_cancelled', $rate, [
                'effective_from' => $rate->effective_from->format('Y-m'),
                'amount' => $rate->amount,
            ]);
            $rate->delete();
        });
        $this->clearCaches();

        return response()->json(['message' => __('messages.settings_rate_cancelled'), 'data' => $this->payload()]);
    }

    /**
     * PUT /api/admin/settings/accounts — {bkash|nagad|rocket: {number, type, visible}, bank: {bank_name, account_name,
     * account_number, branch, routing_number, visible}, instructions: {bn, en, visible}}. A shown part needs its main
     * fields; instructions need Bengali (English optional).
     */
    public function updateAccounts(Request $request): JsonResponse
    {
        $input = $request->all();
        foreach (PaymentAccounts::MOBILE as $m) {
            if (isset($input[$m]['number'])) {
                $input[$m]['number'] = BdPhone::normalize($input[$m]['number']);
            }
        }
        foreach (['bank', 'instructions'] as $part) {
            foreach ((array) ($input[$part] ?? []) as $k => $v) {
                $input[$part][$k] = is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v;
            }
        }
        $request->replace($input);

        $rules = [];
        foreach (PaymentAccounts::MOBILE as $m) {
            $rules[$m] = ['required', 'array'];
            $rules["$m.visible"] = ['required', 'boolean'];
            // Rocket account numbers are the mobile number + one check digit (12 digits).
            $rules["$m.number"] = ['nullable', "required_if_accepted:$m.visible", 'string',
                $m === 'rocket' ? 'regex:/^01[3-9][0-9]{8}[0-9]?$/' : 'regex:'.BdPhone::PATTERN];
            $rules["$m.type"] = ['required', Rule::in(PaymentAccounts::TYPES)];
        }
        $rules += [
            'bank' => ['required', 'array'],
            'bank.visible' => ['required', 'boolean'],
            'bank.bank_name' => ['nullable', 'required_if_accepted:bank.visible', 'string', 'max:100'],
            'bank.account_name' => ['nullable', 'required_if_accepted:bank.visible', 'string', 'max:100'],
            'bank.account_number' => ['nullable', 'required_if_accepted:bank.visible', 'string', 'max:30', 'regex:/^[0-9][0-9 \-]*$/'],
            'bank.branch' => ['nullable', 'string', 'max:100'],
            'bank.routing_number' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]+$/'],
            'instructions' => ['required', 'array'],
            'instructions.visible' => ['required', 'boolean'],
            'instructions.bn' => ['nullable', 'required_if_accepted:instructions.visible', 'required_with:instructions.en', 'string', 'max:2000'],
            'instructions.en' => ['nullable', 'string', 'max:2000'],
        ];
        $data = $request->validate($rules, [
            // First match wins: Rocket before the general rule.
            'rocket.number.regex' => __('messages.settings_rocket_invalid'),
            '*.number.regex' => __('messages.settings_number_invalid'),
            // "required to show it" reads simply as "required".
            '*.required_if_accepted' => __('validation.required'),
            'instructions.bn.required_with' => __('validation.required'),
        ]);

        $new = PaymentAccounts::defaults();
        foreach ($new as $part => $fields) {
            foreach ($fields as $field => $default) {
                $value = $data[$part][$field] ?? $default;
                $new[$part][$field] = $field === 'visible' ? (bool) $value : $value;
            }
        }

        $old = PaymentAccounts::stored();
        $changes = [];
        foreach ($new as $part => $fields) {
            foreach ($fields as $field => $value) {
                if ($old[$part][$field] !== $value) {
                    $changes["$part.$field"] = ['old' => $old[$part][$field], 'new' => $value];
                }
            }
        }
        if (! $changes) {
            return response()->json(['message' => __('messages.settings_nothing_changed'), 'data' => $this->payload()]);
        }

        DB::transaction(function () use ($request, $new, $changes) {
            Setting::put(Setting::PAYMENT_ACCOUNTS, json_encode($new, JSON_UNESCAPED_UNICODE));
            AuditLog::record($request, 'settings.accounts_changed', null, ['changes' => $changes]);
        });
        $this->clearCaches();

        return response()->json(['message' => __('messages.settings_accounts_saved'), 'data' => $this->payload()]);
    }

    /**
     * PUT /api/admin/settings/start-month {start_month: "YYYY-MM", confirm: true}. Changes every member's dues.
     * Refused while an approved — or waiting — payment covers a month before the new start month.
     */
    public function updateStartMonth(Request $request): JsonResponse
    {
        $max = Dues::currentMonth()->addMonths(self::START_MONTH_AHEAD)->format('Y-m');
        $data = $request->validate([
            'start_month' => ['required', 'string', 'regex:'.self::MONTH_RE],
            'confirm' => ['accepted'],
        ], [
            'confirm.accepted' => __('messages.settings_start_confirm'),
        ]);
        if ($data['start_month'] < self::START_MONTH_MIN || $data['start_month'] > $max) {
            throw ValidationException::withMessages(['start_month' => __('messages.settings_start_range', ['min' => self::START_MONTH_MIN, 'max' => $max])]);
        }
        $old = Dues::startMonth()->format('Y-m');
        $new = CarbonImmutable::createFromFormat('!Y-m', $data['start_month']);
        if ($new->format('Y-m') === $old) {
            throw ValidationException::withMessages(['start_month' => __('messages.settings_start_same')]);
        }

        $blocking = $this->claimsBefore($new);
        foreach ([Contribution::STATUS_APPROVED => 'settings_start_blocked_paid', Contribution::STATUS_PENDING => 'settings_start_blocked_pending'] as $status => $key) {
            if ($blocking[$status]['count'] > 0) {
                throw ValidationException::withMessages(['start_month' => __("messages.$key", [
                    'count' => $blocking[$status]['count'],
                    'month' => $blocking[$status]['earliest'],
                ])]);
            }
        }

        DB::transaction(function () use ($request, $old, $new) {
            Setting::put(Setting::FUND_START_MONTH, $new->format('Y-m'));
            AuditLog::record($request, 'settings.start_month_changed', null, ['old' => $old, 'new' => $new->format('Y-m')]);
        });
        $this->clearCaches();

        return response()->json(['message' => __('messages.settings_start_saved'), 'data' => $this->payload()]);
    }

    /** @return array<string, array{count: int, earliest: string|null}> claimed months before $month, per payment status */
    private function claimsBefore(CarbonImmutable $month): array
    {
        $rows = ContributionMonth::query()
            ->join('contributions', 'contributions.id', '=', 'contribution_months.contribution_id')
            ->where('contribution_months.claim', true)
            ->where('contribution_months.month', '<', $month->toDateString())
            ->groupBy('contributions.status')
            ->selectRaw('contributions.status, count(*) as n, min(contribution_months.month) as earliest')
            ->toBase()
            ->get()
            ->keyBy('status');

        $out = [];
        foreach ([Contribution::STATUS_APPROVED, Contribution::STATUS_PENDING] as $status) {
            $row = $rows->get($status);
            $out[$status] = [
                'count' => (int) ($row->n ?? 0),
                'earliest' => $row ? substr((string) $row->earliest, 0, 7) : null,
            ];
        }

        return $out;
    }

    private function payload(): array
    {
        $current = Dues::currentMonth();
        $rates = ContributionRate::query()->with('creator:id,name')->orderByDesc('effective_from')->get();
        $start = Dues::startMonth();
        $claimed = $this->claimsBefore(CarbonImmutable::create(9999, 1, 1)); // all claimed months

        return [
            'current_month' => $current->format('Y-m'),
            'next_month' => $current->addMonth()->format('Y-m'),
            'start_month' => $start->format('Y-m'),
            'start_month_min' => self::START_MONTH_MIN,
            'start_month_max' => $current->addMonths(self::START_MONTH_AHEAD)->format('Y-m'),
            // The start month cannot move past the earliest month that is paid or waiting.
            'earliest_paid_month' => $claimed[Contribution::STATUS_APPROVED]['earliest'],
            'earliest_pending_month' => $claimed[Contribution::STATUS_PENDING]['earliest'],
            'current_amount' => ContributionRate::amountFor($rates->sortBy('effective_from')->values(), $current),
            'amount_max' => self::AMOUNT_MAX,
            'rates' => $rates->map(fn (ContributionRate $r) => [
                'id' => $r->id,
                'amount' => $r->amount,
                'effective_from' => $r->effective_from->format('Y-m'),
                'status' => $r->effective_from->gt($current) ? 'scheduled' : 'started',
                'created_by' => $r->creator ? ['id' => $r->creator->id, 'name' => $r->creator->name] : null,
                'updated_at' => $r->updated_at?->toIso8601String(),
            ])->values(),
            'accounts' => PaymentAccounts::stored(),
        ];
    }

    private function clearCaches(): void
    {
        Cache::forget(PublicHomeController::STATS_CACHE_KEY);
    }
}
