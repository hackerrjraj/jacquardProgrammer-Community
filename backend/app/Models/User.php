<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\SiteInfo;
use Database\Factories\UserFactory;
use Database\Seeders\DemoProgrammersSeeder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;

/**
 * role and status are not fillable on purpose — only admin actions change them.
 */
#[Fillable([
    'name', 'phone', 'email', 'password', 'district', 'workplace', 'experience_years', 'machines', 'skills', 'other_machine', 'other_skill',
    'bio', 'phone_visibility', 'email_visibility', 'workplace_visibility', 'employment_status', 'designation',
])]
#[Hidden(['password', 'remember_token', 'is_featured', 'featured_order'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasPushSubscriptions, Notifiable;

    public const ROLE_MEMBER = 'member';

    public const ROLE_COMMITTEE = 'committee';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLES = [self::ROLE_MEMBER, self::ROLE_COMMITTEE, self::ROLE_ADMIN, self::ROLE_SUPER_ADMIN];

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_SUSPENDED];

    /** Management-only roles: they never owe dues (an admin who pays uses a separate member account). */
    public const MANAGEMENT_ROLES = [self::ROLE_ADMIN, self::ROLE_SUPER_ADMIN];

    /** Statuses that owe monthly dues (Phase 7d). */
    public const DUES_STATUSES = [self::STATUS_APPROVED, self::STATUS_SUSPENDED];

    /** Contact visibility choices (MASTER_SPEC §20). */
    public const VISIBILITY_PUBLIC = 'public';

    public const VISIBILITY_MEMBERS = 'members';

    public const VISIBILITY_HIDDEN = 'hidden';

    public const VISIBILITIES = [self::VISIBILITY_PUBLIC, self::VISIBILITY_MEMBERS, self::VISIBILITY_HIDDEN];

    protected $attributes = [
        'role' => self::ROLE_MEMBER,
        'status' => self::STATUS_PENDING,
        'must_change_password' => false,
        'phone_visibility' => self::VISIBILITY_MEMBERS,
        'email_visibility' => self::VISIBILITY_HIDDEN,
        'workplace_visibility' => self::VISIBILITY_PUBLIC,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'experience_years' => 'integer',
            'machines' => 'array',
            'skills' => 'array',
            'approved_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'must_change_password' => 'boolean',
            'push_enabled' => 'boolean',
            'is_featured' => 'boolean',
            'featured_order' => 'integer',
        ];
    }

    /** The cached About committee list (Phase 11c) follows status / name / photo changes at once. */
    protected static function booted(): void
    {
        $fields = ['status', 'name', 'photo_path', 'email'];
        static::saved(function (User $user) use ($fields) {
            if ($user->wasRecentlyCreated || $user->wasChanged($fields)) {
                SiteInfo::forgetCommittee();
            }
        });
        static::deleted(fn () => SiteInfo::forgetCommittee());
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(MemberDocument::class);
    }

    public function workExperiences(): HasMany
    {
        return $this->hasMany(WorkExperience::class)->orderBy('sort_order')->orderBy('id');
    }

    public function portfolioItems(): HasMany
    {
        return $this->hasMany(PortfolioItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Members shown in the public Programmers directory (MASTER_SPEC §21): approved accounts
     * with a programmer profile. Every registration fills district + experience; the seeded
     * admin-only account has neither, so it stays out.
     */
    public function scopeInDirectory(Builder $query): void
    {
        $query->where('status', self::STATUS_APPROVED)
            ->whereNotNull('district')
            ->whereNotNull('experience_years');
    }

    /**
     * Programmers shown in the Home carousel (MASTER_SPEC §13): listed in the directory
     * and not one of the local demo programmers.
     */
    public function scopeOnHome(Builder $query): void
    {
        $query->inDirectory()
            ->where(fn (Builder $q) => $q->whereNull('email')->orWhere('email', 'not like', '%@'.DemoProgrammersSeeder::EMAIL_DOMAIN));
    }

    /** Not one of the local demo programmers (left out of every fund figure). */
    public function scopeNotDemo(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('users.email')->orWhere('users.email', 'not like', '%@'.DemoProgrammersSeeder::EMAIL_DOMAIN));
    }

    /**
     * Accounts that owe monthly dues (owner decision, Phase 7d): approved members and suspended ones —
     * months while suspended are charged too. Pending / rejected registrations owe nothing.
     */
    public function scopeOwesDues(Builder $query): void
    {
        $query->whereIn('users.status', self::DUES_STATUSES)
            ->whereNotIn('users.role', self::MANAGEMENT_ROLES);
    }

    public function owesDues(): bool
    {
        return in_array($this->status, self::DUES_STATUSES, true) && ! $this->isAdmin();
    }

    /** One of the local demo programmers (they cannot apply for or vote on aid, and are left out of fund figures). */
    public function isDemo(): bool
    {
        return $this->email !== null && str_ends_with(mb_strtolower($this->email), '@'.DemoProgrammersSeeder::EMAIL_DOMAIN);
    }

    /** Any approved, non-demo account may apply for aid (MASTER_SPEC §29, Phase 8a). */
    public function canApplyForAid(): bool
    {
        return $this->isApproved() && ! $this->isDemo();
    }

    /** Phone / desktop push (Phase 10c-1): switched on and at least one browser subscribed. */
    public function wantsPush(): bool
    {
        return $this->push_enabled && $this->pushSubscriptions()->exists();
    }

    /** Advisory aid vote (§30, Phase 8b): every approved, non-demo account, any role (never on their own request). */
    public function canVoteOnAid(): bool
    {
        return $this->canApplyForAid();
    }

    /**
     * Whether a contact field with this visibility setting (§20) may be shown to $viewer:
     * public → everyone, members → logged-in approved members, hidden → nobody.
     */
    public static function visibleTo(?string $visibility, ?User $viewer): bool
    {
        return $visibility === self::VISIBILITY_PUBLIC
            || ($visibility === self::VISIBILITY_MEMBERS && $viewer?->isApproved());
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, [self::ROLE_ADMIN, self::ROLE_SUPER_ADMIN], true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    /**
     * Whether $this (an admin) may act on $target: never on yourself, and
     * only a Super Admin may act on admin-level accounts.
     */
    public function canManage(User $target): bool
    {
        if ($this->is($target)) {
            return false;
        }

        return $this->isSuperAdmin() || ! $target->isAdmin();
    }

    /** The bn/en message shown when a non-approved account tries to log in / use the API. */
    public function statusMessage(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => __('messages.account_pending'),
            self::STATUS_REJECTED => $this->rejection_reason
                ? __('messages.account_rejected_reason', ['reason' => $this->rejection_reason])
                : __('messages.account_rejected'),
            self::STATUS_SUSPENDED => __('messages.account_suspended'),
            default => '',
        };
    }
}
