<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A fund expense (money spent). Soft deleted; a deleted expense no longer reduces the fund balance. */
#[Fillable(['expense_date', 'amount', 'category_id', 'purpose', 'paid_to', 'receipt_image', 'created_by', 'updated_by'])]
class Expense extends Model
{
    use SoftDeletes;

    /** Receipts are private (checked before download), like member documents. */
    public const DISK = 'local';

    public const FILE_MAX_KB = 2048;

    protected function casts(): array
    {
        return ['expense_date' => 'date', 'amount' => 'integer'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
