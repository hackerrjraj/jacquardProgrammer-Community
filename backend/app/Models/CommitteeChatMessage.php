<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One message (text and / or one attachment) in the private Committee Chat. */
class CommitteeChatMessage extends Model
{
    public const MAX_LENGTH = 2000;

    public const DISK = 'private';

    protected $guarded = ['id'];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function isImage(): bool
    {
        return in_array($this->attachment_mime, ['image/jpeg', 'image/png'], true);
    }
}
