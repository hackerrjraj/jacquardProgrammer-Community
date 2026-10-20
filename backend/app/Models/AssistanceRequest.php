<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * An aid request (MASTER_SPEC §29–§32, simple rules of Phase 8a):
 * submitted → approved (admin sets the amount) → paid (payout = ledger debit), or submitted → rejected / cancelled.
 * Votes are advisory only — they never change the status.
 */
class AssistanceRequest extends Model
{
    public const REASON_MEDICAL = 'medical';

    public const REASON_ACCIDENT = 'accident';

    public const REASON_DEATH = 'death';

    public const REASON_JOB_LOSS = 'job_loss';

    public const REASON_OTHER = 'other';

    public const REASONS = [self::REASON_MEDICAL, self::REASON_ACCIDENT, self::REASON_DEATH, self::REASON_JOB_LOSS, self::REASON_OTHER];

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_PAID = 'paid';

    public const STATUSES = [self::STATUS_SUBMITTED, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED, self::STATUS_PAID];

    /** Open = not finished yet; a member may have only one at a time. */
    public const OPEN_STATUSES = [self::STATUS_SUBMITTED, self::STATUS_APPROVED];

    /** Supporting documents and payout proof: JPG/PNG/WebP/PDF up to 5 MB (§38 document limit), private disk. */
    public const FILE_DISK = 'private';

    public const FILE_MAX_KB = 5120;

    public const FILE_MIMES = 'jpg,jpeg,png,webp,pdf';

    public const MAX_DOCUMENTS = 5;

    /** Highest amount that can be typed (a safety cap for typing mistakes, not a fund rule). */
    public const MAX_AMOUNT = 10000000;

    protected $guarded = ['id'];

    protected $attributes = [
        'status' => self::STATUS_SUBMITTED,
        'consent_public_name' => false,
    ];

    protected function casts(): array
    {
        return [
            'amount_requested' => 'integer',
            'amount_approved' => 'integer',
            'consent_public_name' => 'boolean',
            'payout_proof_size' => 'integer',
            'payout_date' => 'date',
            'decided_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * Financial integrity (Phase 12b): "paid" only together with its ledger payout entry, inside one database
     * transaction (the payout route writes the debit first, then the status). A paid case never changes status again.
     */
    protected static function booted(): void
    {
        static::saving(function (AssistanceRequest $r) {
            if ($r->exists && $r->getOriginal('status') === self::STATUS_PAID && $r->isDirty('status')) {
                throw new LogicException('A paid aid case cannot change its status.');
            }
            if ($r->status !== self::STATUS_PAID || ! $r->isDirty('status')) {
                return;
            }
            $hasPayout = $r->exists && LedgerEntry::query()
                ->where('type', LedgerEntry::TYPE_DISBURSEMENT)
                ->where('assistance_request_id', $r->id)
                ->exists();
            if (! $hasPayout || DB::transactionLevel() === 0) {
                throw new LogicException('An aid case becomes "paid" only together with its ledger payout, in one transaction.');
            }
        });
    }

    /** "JPPHAF-AR-2026-0007" — year of submission + the row number (never reused). */
    public static function makeCaseId(int $id, int $year): string
    {
        return sprintf('JPPHAF-AR-%d-%04d', $year, $id);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AssistanceDocument::class)->orderBy('id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(AssistanceVote::class);
    }

    public function ledgerEntry(): HasOne
    {
        return $this->hasOne(LedgerEntry::class);
    }

    /** Adds votes_yes / votes_no counts. */
    public function scopeWithVoteCounts(Builder $query): void
    {
        $query->withCount([
            'votes as votes_yes' => fn (Builder $v) => $v->where('in_favour', true),
            'votes as votes_no' => fn (Builder $v) => $v->where('in_favour', false),
        ]);
    }

    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    /** Documents and payout proof: the applicant, admin or super_admin. */
    public function filesVisibleTo(User $viewer): bool
    {
        return $this->user_id === $viewer->id || $viewer->isAdmin();
    }

    /** @param  array<int, string>  $paths */
    public static function deleteFiles(array $paths): void
    {
        $paths = array_filter($paths);
        if ($paths) {
            Storage::disk(self::FILE_DISK)->delete($paths);
        }
    }
}
