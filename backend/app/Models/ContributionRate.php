<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/** Monthly contribution amount history (MASTER_SPEC §25). A month's due = the amount effective for that month. */
class ContributionRate extends Model
{
    public const DEFAULT_AMOUNT = 200;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'effective_from' => 'date',
        ];
    }

    /** The Super Admin who saved this amount (null for the default row). */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** All rates, oldest first — load once, then use amountFor() per month. */
    public static function history(): Collection
    {
        return self::query()->orderBy('effective_from')->get();
    }

    /** The amount effective for $month (1st of the month), from a history() list. */
    public static function amountFor(Collection $history, CarbonInterface $month): int
    {
        $rate = $history->last(fn (self $r) => $r->effective_from->lte($month));

        return $rate?->amount ?? self::DEFAULT_AMOUNT;
    }
}
