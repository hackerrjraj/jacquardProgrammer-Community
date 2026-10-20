<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * Append-only fund ledger (MASTER_SPEC §34). Rows can be created, never changed or deleted;
 * corrections are new reversal / adjustment entries (Phase 12b, Super Admin only).
 * - adjustment: money in or out (`direction`), reason required.
 * - reversal: cancels exactly one earlier entry (same amount, opposite effect), reason required; an entry can be
 *   reversed only once and a reversal itself can never be reversed.
 */
class LedgerEntry extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_CONTRIBUTION = 'contribution';

    public const TYPE_DISBURSEMENT = 'disbursement';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_REVERSAL = 'reversal';

    public const TYPES = [self::TYPE_CONTRIBUTION, self::TYPE_DISBURSEMENT, self::TYPE_ADJUSTMENT, self::TYPE_REVERSAL];

    /** Adjustment direction: money into the fund / out of the fund. */
    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'entry_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LedgerEntry $e) {
            if ($e->type === self::TYPE_ADJUSTMENT && trim((string) $e->reason) === '') {
                throw new LogicException('An adjustment needs a reason.');
            }
            if ($e->type !== self::TYPE_REVERSAL) {
                return;
            }
            $original = $e->reverses_id ? self::find($e->reverses_id) : null;
            if (! $original || trim((string) $e->reason) === '') {
                throw new LogicException('A reversal needs the entry it reverses and a reason.');
            }
            if ($original->type === self::TYPE_REVERSAL) {
                throw new LogicException('A reversal cannot be reversed.');
            }
            if (self::where('reverses_id', $original->id)->exists()) {
                throw new LogicException('This entry was already reversed.');
            }
            if ((int) $e->amount !== $original->amount) {
                throw new LogicException('A reversal has the same amount as the entry it reverses.');
            }
        });
        static::updating(fn () => throw new LogicException('Ledger entries cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Ledger entries cannot be deleted.'));
    }

    /**
     * +1 = money into the fund, −1 = money out, for an entry that is not a reversal. An adjustment without a
     * direction counts as money in. A reversal has the opposite sign of the entry it reverses.
     */
    public static function signOf(string $type, ?string $direction): int
    {
        return match ($type) {
            self::TYPE_DISBURSEMENT => -1,
            self::TYPE_ADJUSTMENT => $direction === self::DIRECTION_OUT ? -1 : 1,
            default => 1,
        };
    }

    /** This entry's effect on the balance in taka (signed). */
    public function effect(): int
    {
        if ($this->type === self::TYPE_REVERSAL) {
            $original = $this->reverses;

            return $original ? -self::signOf($original->type, $original->direction) * $this->amount : 0;
        }

        return self::signOf($this->type, $this->direction) * $this->amount;
    }

    public function contribution(): BelongsTo
    {
        return $this->belongsTo(Contribution::class);
    }

    public function assistanceRequest(): BelongsTo
    {
        return $this->belongsTo(AssistanceRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The entry this reversal cancels. */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    /** The reversal that cancels this entry, if any. */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_id');
    }
}
