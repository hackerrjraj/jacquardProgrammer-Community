<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PublicHomeController;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Support\FundFigures;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Expenses (খরচের হিসাব) — admin + super_admin by route middleware; delete = super_admin only (route).
 * Whole taka. An expense lowers the fund balance and can never take it below zero (same lock as aid payouts and
 * ledger corrections). Soft delete: a deleted expense stops counting. Every change is audit-logged.
 */
class ExpenseController extends Controller
{
    public const PER_PAGE = 20;

    /** GET /api/admin/expenses?from=&to=&category_id=&page= — newest first, plus the summary cards. */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'category_id' => ['nullable', 'integer'],
        ]);
        $filtered = fn () => Expense::query()
            ->when($f['from'] ?? null, fn ($q, $d) => $q->whereDate('expense_date', '>=', $d))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->whereDate('expense_date', '<=', $d))
            ->when($f['category_id'] ?? null, fn ($q, $id) => $q->where('category_id', $id));

        $page = $filtered()
            ->with(['category', 'creator:id,name'])
            ->orderByDesc('expense_date')->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Expense $e) => self::present($e))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'filtered_total' => (int) $filtered()->sum('amount'),
            ],
            'summary' => self::summary(),
        ]);
    }

    /** POST /api/admin/expenses (multipart, optional `receipt`). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $path = $request->file('receipt')?->store('expense-receipts', Expense::DISK);

        $result = $this->locked(function () use ($request, $data, $path) {
            if ($this->wouldGoNegative($data['amount'])) {
                return 'negative';
            }
            $expense = Expense::create([
                ...$this->fields($data),
                'receipt_image' => $path,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);
            AuditLog::record($request, 'expense.created', $expense, $this->logDetails($expense));

            return $expense;
        });

        if (! $result instanceof Expense) {
            if ($path) {
                Storage::disk(Expense::DISK)->delete($path);
            }

            return $this->refused($result);
        }
        Cache::forget(PublicHomeController::STATS_CACHE_KEY);

        return response()->json(['message' => __('messages.expense_created'), 'data' => self::present($result->load('category', 'creator:id,name')), 'summary' => self::summary()], 201);
    }

    /** POST /api/admin/expenses/{id} (multipart) or PUT/PATCH — `remove_receipt` clears the receipt. */
    public function update(Request $request, int $expense): JsonResponse
    {
        $data = $request->validate([...$this->rules(), 'remove_receipt' => ['sometimes', 'boolean']]);
        $newPath = $request->file('receipt')?->store('expense-receipts', Expense::DISK);
        $oldPath = null;

        $result = $this->locked(function () use ($request, $expense, $data, $newPath, &$oldPath) {
            $e = Expense::lockForUpdate()->findOrFail($expense);
            if ($this->wouldGoNegative($data['amount'] - $e->amount)) {
                return 'negative';
            }
            $before = $this->logDetails($e);
            $e->fill($this->fields($data));
            if ($newPath || filter_var($data['remove_receipt'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $oldPath = $e->receipt_image;
                $e->receipt_image = $newPath;
            }
            $e->updated_by = $request->user()->id;
            $e->save();
            AuditLog::record($request, 'expense.updated', $e, ['before' => $before, 'after' => $this->logDetails($e)]);

            return $e;
        });

        if (! $result instanceof Expense) {
            if ($newPath) {
                Storage::disk(Expense::DISK)->delete($newPath);
            }

            return $this->refused($result);
        }
        if ($oldPath) {
            Storage::disk(Expense::DISK)->delete($oldPath);
        }
        Cache::forget(PublicHomeController::STATS_CACHE_KEY);

        return response()->json(['message' => __('messages.expense_updated'), 'data' => self::present($result->load('category', 'creator:id,name')), 'summary' => self::summary()]);
    }

    /** DELETE /api/admin/expenses/{id} — super_admin only (route). Soft delete; the receipt file is kept. */
    public function destroy(Request $request, int $expense): JsonResponse
    {
        DB::transaction(function () use ($request, $expense) {
            $e = Expense::lockForUpdate()->findOrFail($expense);
            AuditLog::record($request, 'expense.deleted', $e, $this->logDetails($e));
            $e->updated_by = $request->user()->id;
            $e->save();
            $e->delete();
        });
        Cache::forget(PublicHomeController::STATS_CACHE_KEY);

        return response()->json(['message' => __('messages.expense_deleted'), 'summary' => self::summary()]);
    }

    /** GET /api/admin/expenses/{id}/receipt — the private receipt image (admin only). */
    public function receipt(int $expense): StreamedResponse
    {
        $e = Expense::findOrFail($expense);
        $disk = Storage::disk(Expense::DISK);
        abort_unless($e->receipt_image && $disk->exists($e->receipt_image), 404);

        return $disk->response($e->receipt_image, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    // ---- Helpers ------------------------------------------------------------

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'expense_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'category_id' => ['required', 'integer', Rule::exists('expense_categories', 'id')],
            'purpose' => ['required', 'string', 'max:2000', 'regex:/\S/u'],
            'paid_to' => ['nullable', 'string', 'max:150'],
            'receipt' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:'.Expense::FILE_MAX_KB],
        ];
    }

    /** @return array<string, mixed> */
    private function fields(array $data): array
    {
        return [
            'expense_date' => $data['expense_date'],
            'amount' => $data['amount'],
            'category_id' => $data['category_id'],
            'purpose' => trim($data['purpose']),
            'paid_to' => isset($data['paid_to']) && trim($data['paid_to']) !== '' ? trim($data['paid_to']) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function logDetails(Expense $e): array
    {
        return [
            'expense_date' => $e->expense_date?->toDateString(),
            'amount' => $e->amount,
            'category_id' => $e->category_id,
            'purpose' => $e->purpose,
            'paid_to' => $e->paid_to,
            'has_receipt' => $e->receipt_image !== null,
        ];
    }

    /** One money change at a time (same lock as aid payouts and ledger corrections). */
    private function locked(callable $work): mixed
    {
        try {
            return Cache::lock(AssistanceController::PAYOUT_LOCK, 30)->block(10, fn () => DB::transaction($work));
        } catch (LockTimeoutException) {
            return 'busy';
        }
    }

    private function wouldGoNegative(int $extra): bool
    {
        return FundFigures::ledgerBalance() - $extra < 0;
    }

    private function refused(mixed $reason): JsonResponse
    {
        if ($reason === 'busy') {
            return response()->json(['message' => __('messages.ledger_try_again')], 409);
        }

        return response()->json(['message' => __('messages.expense_balance_low', ['balance' => number_format(FundFigures::ledgerBalance())])], 422);
    }

    /** @return array<string, int> */
    public static function summary(): array
    {
        return [
            'total_collected' => FundFigures::collected(),
            'total_disbursed' => FundFigures::disbursed(),
            'total_expenses' => FundFigures::expenses(),
            'balance' => FundFigures::balance(),
        ];
    }

    /** @return array<string, mixed> */
    private static function present(Expense $e): array
    {
        return [
            'id' => $e->id,
            'expense_date' => $e->expense_date?->toDateString(),
            'amount' => $e->amount,
            'category' => $e->category ? ['id' => $e->category->id, 'name_bn' => $e->category->name_bn, 'name_en' => $e->category->name_en] : null,
            'purpose' => $e->purpose,
            'paid_to' => $e->paid_to,
            'has_receipt' => $e->receipt_image !== null,
            'receipt_url' => $e->receipt_image ? url("/api/admin/expenses/{$e->id}/receipt") : null,
            'created_by' => $e->creator?->name,
            'created_at' => $e->created_at?->toIso8601String(),
        ];
    }
}
