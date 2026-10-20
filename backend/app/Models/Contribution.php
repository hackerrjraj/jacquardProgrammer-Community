<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * A member's contribution payment (MASTER_SPEC §26–§27): pending until an admin approves or rejects it.
 * Only an approved payment creates a ledger entry and marks its months as paid.
 */
class Contribution extends Model
{
    public const METHOD_BKASH = 'bkash';

    public const METHOD_NAGAD = 'nagad';

    public const METHOD_ROCKET = 'rocket';

    public const METHOD_BANK = 'bank';

    public const METHOD_CASH = 'cash';

    public const METHODS = [self::METHOD_BKASH, self::METHOD_NAGAD, self::METHOD_ROCKET, self::METHOD_BANK, self::METHOD_CASH];

    /** Mobile-money methods need the sender's number. */
    public const MOBILE_METHODS = [self::METHOD_BKASH, self::METHOD_NAGAD, self::METHOD_ROCKET];

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED];

    /** Screenshot: JPG/PNG/WebP up to 2 MB (§38 image limit), private disk (§38). */
    public const PROOF_DISK = 'private';

    public const PROOF_MAX_KB = 2048;

    /** At most this many months in one submission. */
    public const MAX_MONTHS = 24;

    protected $guarded = ['id'];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'proof_size' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * Financial integrity (Phase 12b): a payment is "approved" (money received) only together with its ledger
     * contribution entry, inside one database transaction. An approved payment never changes status again.
     */
    protected static function booted(): void
    {
        static::saving(function (Contribution $c) {
            if ($c->exists && $c->getOriginal('status') === self::STATUS_APPROVED && $c->isDirty('status')) {
                throw new LogicException('An approved payment cannot change its status.');
            }
            if ($c->status !== self::STATUS_APPROVED || ! $c->isDirty('status')) {
                return;
            }
            $hasEntry = $c->exists && LedgerEntry::query()
                ->where('type', LedgerEntry::TYPE_CONTRIBUTION)
                ->where('contribution_id', $c->id)
                ->exists();
            if (! $hasEntry || DB::transactionLevel() === 0) {
                throw new LogicException('A payment becomes "approved" only together with its ledger entry, in one transaction.');
            }
        });
    }

    /** Key that makes a TxID unique per method (case and spaces ignored). */
    public static function txidKey(string $method, ?string $transactionId): ?string
    {
        return $transactionId === null || $transactionId === '' ? null : $method.':'.mb_strtoupper($transactionId);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function months(): HasMany
    {
        return $this->hasMany(ContributionMonth::class)->orderBy('month');
    }

    public function ledgerEntry(): HasOne
    {
        return $this->hasOne(LedgerEntry::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** The member themself, admin or super_admin may open the screenshot. */
    public function proofVisibleTo(User $viewer): bool
    {
        return $this->user_id === $viewer->id || $viewer->isAdmin();
    }

    public function deleteProofFile(): void
    {
        if ($this->proof_path) {
            Storage::disk(self::PROOF_DISK)->delete($this->proof_path);
        }
    }
}
