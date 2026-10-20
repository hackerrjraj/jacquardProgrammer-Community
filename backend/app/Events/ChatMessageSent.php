<?php

namespace App\Events;

use App\Models\ChatMessage;
use App\Models\Conversation;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A new chat message (Phase 9c). Sent at once (no queue worker) to the conversation channel (open chat screen) and to
 * both members' own channels (chat list + menu badge, other open tabs).
 */
class ChatMessageSent implements ShouldBroadcastNow
{
    public function __construct(public Conversation $conversation, public ChatMessage $message) {}

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
        return 'chat.message';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversation->id,
            'message' => [
                'id' => $this->message->id,
                'sender_id' => $this->message->sender_id,
                'body' => $this->message->body,
                'created_at' => $this->message->created_at?->toIso8601String(),
            ],
        ];
    }
}
