<?php

namespace App\Models;

use App\Support\SiteInfo;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person on the committee panel (Dashboard → Committee, admin + super_admin). The single source for the public
 * About-page list. Being listed here does NOT give any login permission — that stays the account's role.
 */
#[Fillable(['user_id', 'position_id', 'display_order', 'term_start', 'term_end', 'is_active'])]
class CommitteeMember extends Model
{
    protected function casts(): array
    {
        return [
            'term_start' => 'date:Y-m-d',
            'term_end' => 'date:Y-m-d',
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => SiteInfo::forgetCommittee());
        static::deleted(fn () => SiteInfo::forgetCommittee());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(CommitteePosition::class, 'position_id');
    }
}
