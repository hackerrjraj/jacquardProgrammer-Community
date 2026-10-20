<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The phone / desktop push for one 10a in-app notification (Phase 10c-1, MASTER_SPEC §36). Sent by App\Support\Notifier
 * right after the bell row is saved. Queued (Phase 12d): the "JPPHAF Queue" worker started by start-server.bat sends
 * it, so e.g. "Publish notice" no longer waits for every member's push (tests use the sync queue).
 * Payload = the bell's short title / text in the member's push language + the page the bell item opens.
 * No phone numbers, documents or other private details. Expired subscriptions (404 / 410) are deleted by the
 * package's report handler.
 */
class PushAlert extends Notification implements ShouldQueue
{
    use Queueable;

    /** One try only — a retry could show the same push twice. */
    public int $tries = 1;

    public const ICON = '/icons/icon-192.png';

    /** @param  array<string, mixed>  $data  same values as the bell row */
    public function __construct(public string $type, public array $data = []) {}

    /** Push only when the member switched it on and has at least one subscribed browser. */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User && $notifiable->wantsPush() ? [WebPushChannel::class] : [];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $item = new UserNotification(['type' => $this->type, 'data' => $this->data]);
        $locale = ($notifiable->push_locale ?? 'bn') === 'en' ? 'en' : 'bn';
        $previous = app()->getLocale();
        app()->setLocale($locale);
        try {
            $text = $item->text();
        } finally {
            app()->setLocale($previous);
        }

        return (new WebPushMessage)
            ->title('JPPHAF')
            ->body($text['title'].' — '.$text['body'])
            ->icon(self::ICON)
            ->lang($locale)
            ->data(['url' => $item->link()])
            ->options(['TTL' => 86400, 'urgency' => 'normal']);
    }
}
