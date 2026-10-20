<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Payment-due reminder. One JSON setting `payment_reminder` (on/off + message in bn and en).
 * Who receives it: approved, non-demo, non-admin accounts (Admin / Super Admin are management-only) that still have unpaid months according to App\Support\Dues
 * (the single source of truth). A month stops counting only when an admin has confirmed its payment
 * ("paid"); a payment still waiting for review ("pending") does not stop the reminder.
 */
class PaymentReminder
{
    public const DEFAULTS = [
        'message_bn' => 'আপনার বকেয়া পেমেন্ট পরিশোধ করুন',
        'message_en' => 'Please pay your outstanding dues',
    ];

    public const MAX = 300;

    /** @return array{enabled: bool, message_bn: string, message_en: string, updated_at: string|null} */
    public static function stored(): array
    {
        $saved = json_decode((string) Setting::valueOf(Setting::PAYMENT_REMINDER, ''), true);
        $saved = is_array($saved) ? $saved : [];
        $out = ['enabled' => (bool) ($saved['enabled'] ?? true)];
        foreach (self::DEFAULTS as $field => $default) {
            $value = $saved[$field] ?? null;
            $out[$field] = is_string($value) && trim($value) !== '' ? $value : $default;
        }
        $out['updated_at'] = is_string($saved['updated_at'] ?? null) ? $saved['updated_at'] : null;

        return $out;
    }

    public static function enabled(): bool
    {
        return self::stored()['enabled'];
    }

    /** The message in the given language ('en' or anything else = Bengali). */
    public static function message(string $locale): string
    {
        $s = self::stored();

        return $locale === 'en' ? $s['message_en'] : $s['message_bn'];
    }

    /**
     * Unpaid situation of one member: months that are due or only waiting for review.
     *
     * @return array{total_due: int, months_due: int, months_pending: int}
     */
    public static function dueOf(User $member): array
    {
        if ($member->isAdmin()) {
            return ['total_due' => 0, 'months_due' => 0, 'months_pending' => 0];
        }
        $months = Dues::forMember($member);
        $due = $months->where('status', 'due');
        $pending = $months->where('status', 'pending');

        return [
            'total_due' => (int) ($due->sum('amount') + $pending->sum('amount')),
            'months_due' => $due->count(),
            'months_pending' => $pending->count(),
        ];
    }

    /** Whether this account can receive the reminder (approved, not a demo account). */
    public static function eligible(User $member): bool
    {
        return $member->status === User::STATUS_APPROVED && ! $member->isDemo() && ! $member->isAdmin();
    }

    /**
     * Every member who would receive the reminder now, with the amount still unpaid.
     *
     * @return Collection<int, array{user: User, total_due: int, months_due: int, months_pending: int}>
     */
    public static function recipients(): Collection
    {
        return User::query()->where('status', User::STATUS_APPROVED)->whereNotIn('role', User::MANAGEMENT_ROLES)->notDemo()->orderBy('name')->get()
            ->map(fn (User $u) => ['user' => $u] + self::dueOf($u))
            ->filter(fn (array $r) => $r['months_due'] + $r['months_pending'] > 0)
            ->values();
    }

    /**
     * Sends today's reminder (Asia/Dhaka day) to every recipient who has not got one yet today.
     * Safe to run twice: a member who already has today's reminder is skipped, and two runs at the same
     * moment cannot overlap (cache lock).
     *
     * @return int how many members were notified
     */
    public static function sendToday(): int
    {
        if (! self::enabled()) {
            return 0;
        }
        $lock = Cache::lock('payment_reminder_run', 600);
        if (! $lock->get()) {
            return 0;
        }
        try {
            $stored = self::stored();
            $recipients = self::recipients();
            $already = UserNotification::query()
                ->where('type', UserNotification::PAYMENT_DUE_REMINDER)
                ->where('created_at', '>=', now()->startOfDay())
                ->whereIn('user_id', $recipients->pluck('user.id'))
                ->pluck('user_id')->flip();

            $sent = 0;
            foreach ($recipients as $r) {
                if ($already->has($r['user']->id)) {
                    continue;
                }
                Notifier::paymentReminder($r['user'], [
                    'message_bn' => $stored['message_bn'],
                    'message_en' => $stored['message_en'],
                    'amount' => $r['total_due'],
                ]);
                $sent++;
            }

            return $sent;
        } finally {
            $lock->release();
        }
    }
}
