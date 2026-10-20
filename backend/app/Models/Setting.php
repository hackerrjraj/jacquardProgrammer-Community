<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One site setting (key → text value). Editing screen: Fund Settings (Phase 7e, Super Admin). */
class Setting extends Model
{
    /** "YYYY-MM": dues run from this month for every member (owner decision, Phase 7d). */
    public const FUND_START_MONTH = 'fund_start_month';

    /** JSON: the fund's bKash / Nagad / Rocket / bank accounts + payment instructions (App\Support\PaymentAccounts). */
    public const PAYMENT_ACCOUNTS = 'payment_accounts';

    /** JSON: site contact info + About text (App\Support\SiteInfo, Phase 11c). */
    public const SITE_INFO = 'site_info';

    /** Hero slider overlay darkness, whole percent 0-80 (default 40) — App\Http\Controllers\Admin\HeroOverlayController. */
    public const HERO_OVERLAY = 'hero_overlay';

    /** JSON: registration terms & conditions (App\Support\RegistrationTerms). */
    public const REGISTRATION_TERMS = 'registration_terms';

    /** JSON: payment-due reminder switch + message (App\Support\PaymentReminder). */
    public const PAYMENT_REMINDER = 'payment_reminder';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public static function valueOf(string $key, ?string $default = null): ?string
    {
        return self::query()->whereKey($key)->value('value') ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        self::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
