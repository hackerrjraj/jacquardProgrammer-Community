<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A new Committee Chat message. Sent only to the own channels of the people who are in the chat at this moment, so
 * someone removed from the panel stops receiving at once (a shared channel would stay open until they reconnect).
 */
class CommitteeChatMessageSent implements ShouldBroadcastNow
{
    /**
     * @param  list<int>  $recipientIds
     * @param  array<string, mixed>  $message
     */
    public function __construct(public array $recipientIds, public array $message) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(fn (int $id) => new PrivateChannel('App.Models.User.'.$id), $this->recipientIds);
    }

    public function broadcastAs(): string
    {
        return 'committee_chat.message';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['message' => $this->message];
    }
}
