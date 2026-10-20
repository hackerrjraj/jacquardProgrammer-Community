<?php

namespace App\Support;

use App\Models\CommitteeChatMessage;
use App\Models\CommitteeMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who is in the private Committee Chat — worked out live, never stored, so the Committee Panel and the roles stay
 * the single source: approved, non-demo accounts that are an admin / super_admin, or an ACTIVE committee panel member.
 * Removed or deactivated from the panel (or suspended) → no access to the chat or its history at once.
 */
class CommitteeChat
{
    public static function canAccess(User $user): bool
    {
        if (! $user->isApproved() || $user->isDemo()) {
            return false;
        }

        return $user->isAdmin() || CommitteeMember::where('user_id', $user->id)->where('is_active', true)->exists();
    }

    /** Everyone who is in the chat right now. */
    public static function members(): Builder
    {
        return User::query()
            ->where('status', User::STATUS_APPROVED)->notDemo()
            ->where(fn (Builder $q) => $q
                ->whereIn('role', [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])
                ->orWhereIn('id', CommitteeMember::query()->where('is_active', true)->select('user_id')));
    }

    /** The user's read position; a first-time member starts at "everything so far is read". */
    public static function lastReadId(User $user): int
    {
        $row = DB::table('committee_chat_reads')->where('user_id', $user->id)->first();
        if ($row) {
            return (int) $row->last_read_id;
        }
        $latest = (int) CommitteeChatMessage::max('id');
        DB::table('committee_chat_reads')->insertOrIgnore(['user_id' => $user->id, 'last_read_id' => $latest, 'created_at' => now(), 'updated_at' => now()]);

        return $latest;
    }

    /**
     * Gives every current member a read position (= "all read so far") before a new message is saved, so a member
     * who has not opened the chat yet still sees that message as unread.
     */
    public static function ensureReadRows(): void
    {
        $latest = (int) CommitteeChatMessage::max('id');
        $missing = self::members()->whereNotIn('id', DB::table('committee_chat_reads')->select('user_id'))->pluck('id');
        foreach ($missing as $id) {
            DB::table('committee_chat_reads')->insertOrIgnore(['user_id' => $id, 'last_read_id' => $latest, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public static function markRead(User $user, int $messageId): void
    {
        self::lastReadId($user);
        DB::table('committee_chat_reads')->where('user_id', $user->id)->where('last_read_id', '<', $messageId)
            ->update(['last_read_id' => $messageId, 'updated_at' => now()]);
    }

    /** Unread messages from others; 0 for anyone who may not access the chat. */
    public static function unreadFor(User $user): int
    {
        if (! self::canAccess($user)) {
            return 0;
        }

        return CommitteeChatMessage::where('sender_id', '!=', $user->id)->where('id', '>', self::lastReadId($user))->count();
    }
}
