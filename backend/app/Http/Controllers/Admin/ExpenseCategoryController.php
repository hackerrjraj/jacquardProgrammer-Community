<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ExpenseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Expense categories — admin + super_admin manage them; delete = super_admin only and only while unused. Audit-logged. */
class ExpenseCategoryController extends Controller
{
    /** GET /api/admin/expense-categories — every category (active + hidden) with its expense count. */
    public function index(): JsonResponse
    {
        return response()->json(['data' => ExpenseCategory::query()->withCount('expenses')->orderBy('sort_order')->orderBy('id')->get()->map(fn ($c) => self::present($c))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name_bn' => ['required', 'string', 'max:100', 'regex:/\S/u'],
            'name_en' => ['required', 'string', 'max:100', 'regex:/\S/u'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $this->assertUnique($data);
        $cat = ExpenseCategory::create([
            'name_bn' => trim($data['name_bn']), 'name_en' => trim($data['name_en']),
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => (ExpenseCategory::max('sort_order') ?? -1) + 1,
        ]);
        AuditLog::record($request, 'expense_category.created', $cat, ['name_bn' => $cat->name_bn, 'name_en' => $cat->name_en]);

        return response()->json(['message' => __('messages.expense_category_saved'), 'data' => self::present($cat->loadCount('expenses'))], 201);
    }

    public function update(Request $request, int $category): JsonResponse
    {
        $cat = ExpenseCategory::findOrFail($category);
        $data = $request->validate([
            'name_bn' => ['sometimes', 'required', 'string', 'max:100', 'regex:/\S/u'],
            'name_en' => ['sometimes', 'required', 'string', 'max:100', 'regex:/\S/u'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $this->assertUnique($data, $cat);
        $cat->fill(array_map(fn ($v) => is_string($v) ? trim($v) : $v, $data));
        if ($cat->isDirty()) {
            $changes = collect($cat->getDirty())->map(fn ($new, $f) => ['from' => $cat->getOriginal($f), 'to' => $new])->all();
            $cat->save();
            AuditLog::record($request, 'expense_category.updated', $cat, ['changes' => $changes]);
        }

        return response()->json(['message' => __('messages.expense_category_saved'), 'data' => self::present($cat->loadCount('expenses'))]);
    }

    /** DELETE — super_admin only (route); refused while any expense (even a deleted one) uses it. */
    public function destroy(Request $request, int $category): JsonResponse
    {
        $cat = ExpenseCategory::findOrFail($category);
        if ($cat->expenses()->withTrashed()->exists()) {
            throw ValidationException::withMessages(['category' => __('messages.expense_category_in_use')]);
        }
        $cat->delete();
        AuditLog::record($request, 'expense_category.deleted', $cat, ['name_bn' => $cat->name_bn, 'name_en' => $cat->name_en]);

        return response()->json(['message' => __('messages.expense_category_deleted')]);
    }

    private function assertUnique(array $data, ?ExpenseCategory $self = null): void
    {
        foreach (['name_bn', 'name_en'] as $f) {
            if (isset($data[$f]) && ExpenseCategory::query()->when($self, fn ($q) => $q->whereKeyNot($self->id))
                ->whereRaw('lower('.$f.') = ?', [mb_strtolower(trim($data[$f]))])->exists()) {
                throw ValidationException::withMessages([$f => __('messages.expense_category_duplicate')]);
            }
        }
    }

    /** @return array<string, mixed> */
    private static function present(ExpenseCategory $c): array
    {
        return ['id' => $c->id, 'name_bn' => $c->name_bn, 'name_en' => $c->name_en, 'is_active' => $c->is_active, 'expenses_count' => $c->expenses_count ?? 0];
    }
}
