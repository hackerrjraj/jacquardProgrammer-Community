<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Phone / desktop push for a new chat message (Phase 10c-3, MASTER_SPEC §35–§36). Push only — chat messages are never
 * in the bell (10a). The payload says only who wrote, never the message text: "<name> sent you a message" in the
 * receiver's push language. One notification per conversation (tag), so new messages replace it instead of stacking.
 * Sent by ChatController after the HTTP answer (defer), so sending a message does not get slower.
 */
class ChatPushAlert extends Notification
{
    public function __construct(public int $conversationId, public string $senderName) {}

    /** Same rule as PushAlert: switched on + at least one subscribed browser. */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User && $notifiable->wantsPush() ? [WebPushChannel::class] : [];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $locale = ($notifiable->push_locale ?? 'bn') === 'en' ? 'en' : 'bn';

        return (new WebPushMessage)
            ->title('JPPHAF')
            ->body(__('messages.chat_push_body', ['name' => $this->senderName], $locale))
            ->icon(PushAlert::ICON)
            ->lang($locale)
            ->tag('chat-'.$this->conversationId)
            ->renotify()
            ->data(['url' => '/dashboard/chat/'.$this->conversationId])
            ->options(['TTL' => 86400, 'urgency' => 'normal']);
    }
}
