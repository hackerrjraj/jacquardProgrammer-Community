<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A member's portfolio item (MASTER_SPEC §19). The image is on the public disk. */
class PortfolioItem extends Model
{
    public const DISK = 'public';

    public const MAX_PER_MEMBER = 12;

    protected $fillable = ['category', 'title', 'description', 'sort_order'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
