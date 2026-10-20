<?php

namespace App\Support;

use App\Events\NotificationCreated;
use App\Models\AssistanceRequest;
use App\Models\Contribution;
use App\Models\Notice;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\PushAlert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Creates in-app notifications (Phase 10a, MASTER_SPEC §36) and tells the receivers' open pages over Reverb.
 * Called after the action is saved; a failure here is logged and never breaks the action itself.
 * Only approved, non-demo accounts receive notifications. Members who switched push on also get it on their phone /
 * desktop (Phase 10c-1) — sent synchronously, as no queue worker runs here.
 */
class Notifier
{
    /** Pause live sends for a few seconds after Reverb failed (shared with chat, 9c). */
    private const LIVE_DOWN_KEY = 'chat_live_down';

    private const LIVE_DOWN_SECONDS = 10;

    /** Pusher accepts at most 100 channels per send. */
    private const CHANNELS_PER_SEND = 100;

    /** How often (seconds) the "scheduled notice is out now" check may run. */
    private const DUE_CHECK_SECONDS = 60;

    private const DUE_CHECK_KEY = 'notices_due_checked';

    public static function memberApproved(User $member): void
    {
        self::send([$member->id], UserNotification::MEMBER_APPROVED);
    }

    public static function paymentDecided(Contribution $c): void
    {
        $approved = $c->status === Contribution::STATUS_APPROVED;
        self::send([$c->user_id], $approved ? UserNotification::PAYMENT_APPROVED : UserNotification::PAYMENT_REJECTED, [
            'contribution_id' => $c->id,
            'amount' => $c->amount,
            ...($approved ? [] : ['reason' => $c->rejection_reason]),
        ]);
    }

    /** Daily payment-due reminder for one member (sent by App\Support\PaymentReminder). */
    public static function paymentReminder(User $member, array $data): void
    {
        self::send([$member->id], UserNotification::PAYMENT_DUE_REMINDER, $data);
    }

    public static function aidDecided(AssistanceRequest $r): void
    {
        $type = match ($r->status) {
            AssistanceRequest::STATUS_APPROVED => UserNotification::AID_APPROVED,
            AssistanceRequest::STATUS_REJECTED => UserNotification::AID_REJECTED,
            AssistanceRequest::STATUS_PAID => UserNotification::AID_PAID,
            default => null,
        };
        if (! $type) {
            return;
        }
        self::send([$r->user_id], $type, [
            'assistance_id' => $r->id,
            'case_id' => $r->case_id,
            ...($r->status === AssistanceRequest::STATUS_REJECTED
                ? ['reason' => $r->rejection_reason]
                : ['amount' => $r->amount_approved]),
        ]);
    }

    /** A new aid request: everyone who may vote on it (§30), except the applicant. */
    public static function aidVoteOpen(AssistanceRequest $r): void
    {
        $ids = self::receivers()->where('id', '!=', $r->user_id)->pluck('id')->all();
        self::send($ids, UserNotification::AID_VOTE_OPEN, ['assistance_id' => $r->id, 'case_id' => $r->case_id]);
    }

    /**
     * A notice that is out now (published, date reached) and has not notified yet → all approved members except
     * the admin who published it. Scheduled notices are picked up later by notifyDueNotices().
     */
    public static function noticeLive(Notice $notice, ?int $exceptUserId = null): void
    {
        try {
            $claimed = Notice::query()->whereKey($notice->id)->live()->whereNull('notified_at')
                ->update(['notified_at' => now()]);
        } catch (Throwable $e) {
            Log::warning('Notice notification not sent: '.$e->getMessage());

            return;
        }
        if (! $claimed) {
            return; // not out yet, or already notified (also when two requests arrive at once)
        }
        $ids = self::receivers()->when($exceptUserId, fn ($q) => $q->where('id', '!=', $exceptUserId))->pluck('id')->all();
        self::send($ids, UserNotification::NOTICE_PUBLISHED, [
            'notice_id' => $notice->id,
            'title_bn' => $notice->title_bn,
            'title_en' => $notice->title_en,
        ], pushAfterResponse: true);
    }

    /**
     * Scheduled notices whose date has come (no scheduler runs here, so this is checked when members load their
     * notifications — at most once a minute).
     */
    public static function notifyDueNotices(): void
    {
        if (! Cache::add(self::DUE_CHECK_KEY, true, self::DUE_CHECK_SECONDS)) {
            return;
        }
        Notice::query()->live()->whereNull('notified_at')->orderBy('id')->limit(20)->get()
            ->each(fn (Notice $n) => self::noticeLive($n, $n->created_by));
    }

    /**
     * Web push (Phase 10c-1) to the receivers who switched it on and have a subscribed browser. One member's failure
     * never stops the others; expired subscriptions (404 / 410) are removed by the push channel.
     *
     * @param  array<int, int>  $ids  approved, non-demo receivers
     * @param  array<string, mixed>  $data
     */
    private static function push(array $ids, string $type, array $data): void
    {
        try {
            $users = User::query()->whereIn('id', $ids)->where('push_enabled', true)->whereHas('pushSubscriptions')->get();
        } catch (Throwable $e) {
            Log::warning("Push {$type} not sent: ".$e->getMessage());

            return;
        }
        foreach ($users as $user) {
            try {
                $user->notify(new PushAlert($type, $data));
            } catch (Throwable $e) {
                Log::warning("Push {$type} to user {$user->id} not sent: ".$e->getMessage());
            }
        }
    }

    /** Approved, non-demo accounts. */
    private static function receivers()
    {
        return User::query()->where('status', User::STATUS_APPROVED)->notDemo();
    }

    /**
     * Saves one notification per receiver (only approved, non-demo accounts) and sends the live "new notification" signal.
     *
     * @param  array<int, int>  $userIds
     * @param  array<string, mixed>  $data
     */
    private static function send(array $userIds, string $type, array $data = [], bool $pushAfterResponse = false): void
    {
        try {
            $ids = self::receivers()->whereIn('id', $userIds)->pluck('id')->all();
            if ($ids === []) {
                return;
            }
            $now = now();
            $json = json_encode($data, JSON_UNESCAPED_UNICODE);
            DB::transaction(function () use ($ids, $type, $json, $now) {
                foreach (array_chunk($ids, 500) as $chunk) {
                    UserNotification::query()->insert(array_map(fn (int $id) => [
                        'user_id' => $id, 'type' => $type, 'data' => $json, 'created_at' => $now,
                    ], $chunk));
                }
            });
        } catch (Throwable $e) {
            Log::warning("Notification {$type} not saved: ".$e->getMessage());

            return;
        }

        // Web push can be slow (one call per browser): for a published notice it runs after the answer was sent.
        if ($pushAfterResponse) {
            app()->terminating(fn () => self::push($ids, $type, $data));
        } else {
            self::push($ids, $type, $data);
        }

        foreach (array_chunk($ids, self::CHANNELS_PER_SEND) as $chunk) {
            if (Cache::has(self::LIVE_DOWN_KEY)) {
                return;
            }
            try {
                event(new NotificationCreated($chunk));
            } catch (Throwable $e) {
                Cache::put(self::LIVE_DOWN_KEY, true, self::LIVE_DOWN_SECONDS);
                Log::warning('Notification live update not sent: '.$e->getMessage());
            }
        }
    }
}
