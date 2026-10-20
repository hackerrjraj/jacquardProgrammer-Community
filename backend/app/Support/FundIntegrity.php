<?php

namespace App\Support;

use App\Models\AssistanceRequest;
use App\Models\Contribution;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\LedgerEntry;

/**
 * Read-only fund integrity check (Phase 12b, MASTER_SPEC §34) — Super Admin page section + `php artisan fund:check`.
 * Lists mismatches between the ledger, aid cases and payments. It NEVER changes data: corrections are made by hand
 * with an adjustment / reversal entry.
 *
 * Each issue: code, severity ("problem" = numbers do not match, "note" = allowed but worth a look), and the ids /
 * amounts involved. The dashboard turns the code into a readable sentence.
 */
class FundIntegrity
{
    /** @return array{ok: bool, balance: int, checked_entries: int, issues: list<array<string, mixed>>} */
    public static function run(): array
    {
        $entries = LedgerEntry::query()->with('reverses')->orderBy('id')->get();
        $issues = [];
        $add = function (string $code, array $data = [], string $severity = 'problem') use (&$issues) {
            $issues[] = ['code' => $code, 'severity' => $severity, ...$data];
        };

        $payouts = $entries->where('type', LedgerEntry::TYPE_DISBURSEMENT);
        $contributionEntries = $entries->where('type', LedgerEntry::TYPE_CONTRIBUTION);
        $reversedIds = $entries->where('type', LedgerEntry::TYPE_REVERSAL)->pluck('reverses_id')->filter();

        // 1. Paid aid case without its ledger payout (e.g. a status set by hand).
        $paidCases = AssistanceRequest::query()->where('status', AssistanceRequest::STATUS_PAID)->orderBy('id')->get();
        $payoutByCase = $payouts->whereNotNull('assistance_request_id')->keyBy('assistance_request_id');
        foreach ($paidCases as $case) {
            $entry = $payoutByCase->get($case->id);
            if (! $entry) {
                $add('paid_case_without_payout', ['case_id' => $case->case_id, 'amount' => $case->amount_approved]);
            } elseif ($entry->amount !== $case->amount_approved) {
                $add('payout_amount_mismatch', ['case_id' => $case->case_id, 'entry_id' => $entry->id, 'amount' => $entry->amount, 'expected' => $case->amount_approved]);
            }
            if ($entry && $reversedIds->contains($entry->id)) {
                $add('payout_reversed', ['case_id' => $case->case_id, 'entry_id' => $entry->id, 'amount' => $entry->amount], 'note');
            }
        }

        // 2. Ledger payout without a case, or whose case is not "paid".
        $cases = AssistanceRequest::query()->whereIn('id', $payouts->pluck('assistance_request_id')->filter())->get()->keyBy('id');
        foreach ($payouts as $entry) {
            $case = $entry->assistance_request_id ? $cases->get($entry->assistance_request_id) : null;
            if (! $case) {
                $add('payout_without_case', ['entry_id' => $entry->id, 'amount' => $entry->amount]);
            } elseif ($case->status !== AssistanceRequest::STATUS_PAID) {
                $add('payout_case_not_paid', ['entry_id' => $entry->id, 'case_id' => $case->case_id, 'amount' => $entry->amount, 'status' => $case->status]);
            }
        }

        // 3. Approved payment without its ledger entry (or a different amount).
        $entryByPayment = $contributionEntries->whereNotNull('contribution_id')->keyBy('contribution_id');
        $approved = Contribution::query()->where('status', Contribution::STATUS_APPROVED)->orderBy('id')->get();
        foreach ($approved as $payment) {
            $entry = $entryByPayment->get($payment->id);
            if (! $entry) {
                $add('payment_without_entry', ['payment_id' => $payment->id, 'amount' => $payment->amount]);
            } elseif ($entry->amount !== $payment->amount) {
                $add('payment_amount_mismatch', ['payment_id' => $payment->id, 'entry_id' => $entry->id, 'amount' => $entry->amount, 'expected' => $payment->amount]);
            }
            if ($entry && $reversedIds->contains($entry->id)) {
                $add('payment_reversed', ['payment_id' => $payment->id, 'entry_id' => $entry->id, 'amount' => $entry->amount], 'note');
            }
        }

        // 4. Ledger contribution entry without an approved payment.
        $payments = Contribution::query()->whereIn('id', $contributionEntries->pluck('contribution_id')->filter())->get()->keyBy('id');
        foreach ($contributionEntries as $entry) {
            $payment = $entry->contribution_id ? $payments->get($entry->contribution_id) : null;
            if (! $payment || $payment->status !== Contribution::STATUS_APPROVED) {
                $add('entry_without_payment', ['entry_id' => $entry->id, 'amount' => $entry->amount, 'payment_id' => $payment?->id]);
            }
        }

        // 5. Broken reversals (the app refuses these, but the check looks anyway).
        foreach ($entries->where('type', LedgerEntry::TYPE_REVERSAL) as $entry) {
            $original = $entry->reverses;
            if (! $original || $original->type === LedgerEntry::TYPE_REVERSAL || $original->amount !== $entry->amount) {
                $add('reversal_invalid', ['entry_id' => $entry->id, 'amount' => $entry->amount, 'reverses_id' => $entry->reverses_id]);
            }
        }
        foreach ($reversedIds->countBy()->filter(fn (int $n) => $n > 1) as $id => $n) {
            $add('reversed_twice', ['entry_id' => (int) $id, 'count' => $n]);
        }

        // 6a. Expenses: positive whole-taka amount, a purpose, an existing category (soft-deleted ones are not checked).
        $expenses = Expense::query()->get();
        $categoryIds = ExpenseCategory::query()->pluck('id')->all();
        foreach ($expenses as $x) {
            $raw = (float) $x->getRawOriginal('amount');
            if ($raw <= 0 || $raw != floor($raw) || trim((string) $x->purpose) === '' || ! in_array($x->category_id, $categoryIds, true)) {
                $add('expense_invalid', ['expense_id' => $x->id, 'amount' => $x->amount]);
            }
        }

        // 6. Balance: the stored figure must equal the sum of every entry (minus the expenses), one by one; the fund never goes below 0.
        $balance = FundFigures::ledgerBalance();
        $walked = (int) $entries->sum(fn (LedgerEntry $e) => $e->effect()) - (int) $expenses->sum(fn (Expense $x) => $x->amount);
        if ($walked !== $balance) {
            $add('balance_mismatch', ['amount' => $balance, 'expected' => $walked]);
        }
        if ($balance < 0) {
            $add('balance_negative', ['amount' => $balance]);
        }

        return [
            'ok' => collect($issues)->where('severity', 'problem')->isEmpty(),
            'balance' => $balance,
            'checked_entries' => $entries->count(),
            'issues' => $issues,
        ];
    }
}
