<?php

namespace App\Events;

use App\Models\Conversation;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A member has read a conversation up to message `last_read_id` (Phase 9c): the sender's ✓ turns into ✓✓, and the
 * reader's own menu badge / list (in other tabs) update.
 */
class ChatMessagesRead implements ShouldBroadcastNow
{
    public function __construct(public Conversation $conversation, public int $readerId, public int $lastReadId) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('chat.conversation.'.$this->conversation->id),
            new PrivateChannel('App.Models.User.'.$this->conversation->user_one_id),
            new PrivateChannel('App.Models.User.'.$this->conversation->user_two_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat.read';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversation->id,
            'reader_id' => $this->readerId,
            'last_read_id' => $this->lastReadId,
        ];
    }
}
