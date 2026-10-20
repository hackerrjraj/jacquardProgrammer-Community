<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * Private channels (MASTER_SPEC §35, Phase 9a). Only approved accounts may listen; used for live delivery in 9c.
 */

// A member's own channel (new-message / unread updates for the menu badge).
Broadcast::channel('App.Models.User.{id}', fn (User $user, int $id) => $user->isApproved() && $user->id === $id);

// One conversation: only its two participants (no admin access to private chats).
Broadcast::channel('chat.conversation.{conversation}', function (User $user, int $conversation) {
    return $user->isApproved()
        && (bool) Conversation::find($conversation)?->hasParticipant($user);
});
