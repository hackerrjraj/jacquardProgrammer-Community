<?php

namespace App\Models;

use App\Support\BnDigits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One in-app notification (Phase 10a, MASTER_SPEC §36). Stores only the type + values; title, text and link are built
 * when read (in the request's language — lang/{bn,en}/notifications.php).
 */
class UserNotification extends Model
{
    public const UPDATED_AT = null;

    public const MEMBER_APPROVED = 'member_approved';

    public const PAYMENT_APPROVED = 'payment_approved';

    public const PAYMENT_REJECTED = 'payment_rejected';

    public const AID_APPROVED = 'aid_approved';

    public const AID_REJECTED = 'aid_rejected';

    public const AID_PAID = 'aid_paid';

    public const AID_VOTE_OPEN = 'aid_vote_open';

    public const NOTICE_PUBLISHED = 'notice_published';

    public const PAYMENT_DUE_REMINDER = 'payment_due_reminder';

    public const TYPES = [
        self::MEMBER_APPROVED, self::PAYMENT_APPROVED, self::PAYMENT_REJECTED, self::AID_APPROVED,
        self::AID_REJECTED, self::AID_PAID, self::AID_VOTE_OPEN, self::NOTICE_PUBLISHED, self::PAYMENT_DUE_REMINDER,
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    /** The page the notification opens in the website. */
    public function link(): string
    {
        return match ($this->type) {
            self::PAYMENT_APPROVED, self::PAYMENT_REJECTED, self::PAYMENT_DUE_REMINDER => '/dashboard/payments',
            self::AID_APPROVED, self::AID_REJECTED, self::AID_PAID, self::AID_VOTE_OPEN => '/dashboard/assistance',
            self::NOTICE_PUBLISHED => '/notices/'.($this->data['notice_id'] ?? ''),
            default => '/dashboard',
        };
    }

    /** @return array{title: string, body: string} in the current language */
    public function text(): array
    {
        $data = $this->data ?? [];
        $en = app()->getLocale() === 'en';
        $values = [
            'amount' => isset($data['amount']) ? self::money((int) $data['amount'], $en) : '',
            'case_id' => $data['case_id'] ?? '',
            'reason' => $data['reason'] ?? '',
            'message' => ($en && filled($data['message_en'] ?? null) ? $data['message_en'] : ($data['message_bn'] ?? '')),
            'title' => ($en && filled($data['title_en'] ?? null) ? $data['title_en'] : ($data['title_bn'] ?? '')),
        ];

        return [
            'title' => __("notifications.{$this->type}.title", $values),
            'body' => __("notifications.{$this->type}.body", $values),
        ];
    }

    /** ৳ amount with thousands commas; Bengali digits in Bengali. */
    private static function money(int $amount, bool $en): string
    {
        return $en ? number_format($amount) : BnDigits::toBengali(number_format($amount));
    }
}
