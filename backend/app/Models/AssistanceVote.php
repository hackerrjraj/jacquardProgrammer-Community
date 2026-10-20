<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One person's advisory Yes/No vote on an aid request (MASTER_SPEC §30). */
class AssistanceVote extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'in_favour' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
