<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A private supporting document of an aid request (MASTER_SPEC §29, §38). */
class AssistanceDocument extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(AssistanceRequest::class, 'assistance_request_id');
    }
}
