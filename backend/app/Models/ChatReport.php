<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member's report of one message they received (Phase 9d-1). Admins see only `message_snapshot` (a text copy of
 * that one message) — never the conversation.
 */
class ChatReport extends Model
{
    public const REASONS = ['harassment', 'spam', 'fraud_or_money_request', 'other'];

    public const STATUS_OPEN = 'open';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_DISMISSED = 'dismissed';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_REVIEWED, self::STATUS_DISMISSED];

    /** Reports one member may send in 24 hours. */
    public const DAILY_LIMIT = 10;

    public const NOTE_MAX = 500;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function reportedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
