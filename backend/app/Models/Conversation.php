<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A 1-to-1 chat between two members (MASTER_SPEC §35). user_one_id is always the smaller user id, so a pair has
 * exactly one conversation. Only the two participants can read it — there is no admin access (§35).
 */
class Conversation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    public function userOne(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_one_id');
    }

    public function userTwo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_two_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function lastMessage(): HasOne
    {
        return $this->hasOne(ChatMessage::class)->latestOfMany();
    }

    /** The conversation between two users, created when it does not exist yet. */
    public static function between(User $a, User $b): self
    {
        [$one, $two] = $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];

        return self::firstOrCreate(['user_one_id' => $one, 'user_two_id' => $two]);
    }

    public function scopeForUser(Builder $query, User $user): void
    {
        $query->where(fn (Builder $q) => $q->where('user_one_id', $user->id)->orWhere('user_two_id', $user->id));
    }

    public function hasParticipant(User $user): bool
    {
        return $this->user_one_id === $user->id || $this->user_two_id === $user->id;
    }

    /** The other member — null only if their account row no longer exists (9d-2: screens show "Former member"). */
    public function partnerOf(User $user): ?User
    {
        return $this->user_one_id === $user->id ? $this->userTwo : $this->userOne;
    }

    /** The last message the other member has read (works even when their account row is gone). */
    public function partnerLastReadId(User $user): ?int
    {
        return $this->user_one_id === $user->id ? $this->user_two_last_read_id : $this->user_one_last_read_id;
    }

    /** The column that stores the last message $user has read. */
    public function readColumnOf(User $user): string
    {
        return $this->user_one_id === $user->id ? 'user_one_last_read_id' : 'user_two_last_read_id';
    }

    public function lastReadBy(User $user): ?int
    {
        return $this->{$this->readColumnOf($user)};
    }

    /** Messages from the other person that $user has not read yet. */
    public function unreadCountFor(User $user): int
    {
        return $this->messages()
            ->where('sender_id', '!=', $user->id)
            ->when($this->lastReadBy($user), fn ($q, int $id) => $q->where('id', '>', $id))
            ->count();
    }

    /**
     * Marks everything up to (and including) message $messageId as read by $user — never moves backwards.
     * Returns true when the read position moved.
     */
    public function markReadBy(User $user, int $messageId): bool
    {
        $column = $this->readColumnOf($user);
        if ($messageId <= (int) $this->{$column}) {
            return false;
        }

        return $this->forceFill([$column => $messageId])->save();
    }
}
