<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A member's private document. The file lives on the `private` disk and is served only through the API. */
class MemberDocument extends Model
{
    public const DISK = 'private';

    /** Most documents one member may keep. */
    public const MAX_PER_MEMBER = 20;

    protected $fillable = ['type', 'original_name', 'path', 'mime_type', 'size'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Only the owner, admin and super_admin may open the file. Committee sees the
     * list (type + date) but never the file (MASTER_SPEC §10; aid review is Phase 8).
     */
    public function canBeDownloadedBy(?User $user): bool
    {
        return $user !== null && ($this->user_id === $user->id || $user->isAdmin());
    }
}
