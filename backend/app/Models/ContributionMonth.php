<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One month covered by a contribution, with that month's due amount (MASTER_SPEC §26). */
class ContributionMonth extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'month' => 'date',
            'amount' => 'integer',
            'claim' => 'boolean',
        ];
    }

    public function contribution(): BelongsTo
    {
        return $this->belongsTo(Contribution::class);
    }
}
