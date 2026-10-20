<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * "You have a new notification" (Phase 10a) on each receiver's own channel, so the bell updates without reloading.
 * Carries no text — the website reloads the count / list through the API (in the member's language).
 */
class NotificationCreated implements ShouldBroadcastNow
{
    /** @param  array<int, int>  $userIds */
    public function __construct(public array $userIds) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(fn (int $id) => new PrivateChannel('App.Models.User.'.$id), $this->userIds);
    }

    public function broadcastAs(): string
    {
        return 'notification.new';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [];
    }
}
