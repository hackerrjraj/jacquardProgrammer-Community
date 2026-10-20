<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Push for a new Committee Chat message. Same rules as ChatPushAlert: push only (not in the bell), never the message
 * text, receiver's push language, one notification (tag) that new messages replace.
 */
class CommitteeChatPushAlert extends Notification
{
    public function __construct(public string $senderName) {}

    public function via(object $notifiable): array
    {
        return $notifiable instanceof User && $notifiable->wantsPush() ? [WebPushChannel::class] : [];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $locale = ($notifiable->push_locale ?? 'bn') === 'en' ? 'en' : 'bn';

        return (new WebPushMessage)
            ->title('JPPHAF')
            ->body(__('messages.committee_chat_push_body', ['name' => $this->senderName], $locale))
            ->icon(PushAlert::ICON)
            ->lang($locale)
            ->tag('committee-chat')
            ->renotify()
            ->data(['url' => '/dashboard/committee-chat'])
            ->options(['TTL' => 86400, 'urgency' => 'normal']);
    }
}
