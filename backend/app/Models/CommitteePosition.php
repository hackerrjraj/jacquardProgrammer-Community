<?php

namespace App\Models;

use App\Support\SiteInfo;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One entry of the admin-editable committee position list (President, Secretary, Treasurer …). */
#[Fillable(['name_en', 'name_bn', 'sort_order'])]
class CommitteePosition extends Model
{
    protected static function booted(): void
    {
        static::saved(fn () => SiteInfo::forgetCommittee());
        static::deleted(fn () => SiteInfo::forgetCommittee());
    }

    public function members(): HasMany
    {
        return $this->hasMany(CommitteeMember::class, 'position_id');
    }

    /** The name in the current request language (Bangla falls back to English). */
    public function localName(): string
    {
        return app()->getLocale() === 'bn' && $this->name_bn !== '' ? $this->name_bn : $this->name_en;
    }
}
