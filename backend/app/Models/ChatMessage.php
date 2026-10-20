<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One text message in a 1-to-1 conversation (MASTER_SPEC §35). */
class ChatMessage extends Model
{
    /** Longest message (characters). */
    public const MAX_LENGTH = 2000;

    protected $guarded = ['id'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
