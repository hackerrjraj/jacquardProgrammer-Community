<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One member blocking another in chat (Phase 9d-1). While a block exists in either direction, neither of the two can
 * send messages to the other. Old messages stay.
 */
class ChatBlock extends Model
{
    protected $guarded = ['id'];

    public function blocked(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_id');
    }

    /** @return array{blocked_by_me: bool, blocked_me: bool} */
    public static function stateBetween(User $me, User $other): array
    {
        $rows = self::query()
            ->where(fn ($q) => $q->where('blocker_id', $me->id)->where('blocked_id', $other->id))
            ->orWhere(fn ($q) => $q->where('blocker_id', $other->id)->where('blocked_id', $me->id))
            ->pluck('blocker_id');

        return ['blocked_by_me' => $rows->contains($me->id), 'blocked_me' => $rows->contains($other->id)];
    }
}
