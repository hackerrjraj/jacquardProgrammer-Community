<?php

namespace App\Models;

use App\Support\ProfileLists;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One choice in the admin-editable Machine or Skill list (Phase 11d-1, MASTER_SPEC §19).
 * Profiles store the stable `key` (users.machines / users.skills), never the name, so a rename shows everywhere.
 * A hidden item (is_active = false) is not offered for new picks, but profiles that have it keep and show it.
 */
#[Fillable(['type', 'key', 'name_bn', 'name_en', 'is_active', 'sort_order'])]
class ProfileListItem extends Model
{
    public const TYPE_MACHINE = 'machine';

    public const TYPE_SKILL = 'skill';

    public const TYPES = [self::TYPE_MACHINE, self::TYPE_SKILL];

    /** List type → the users column that stores its keys. */
    public const COLUMNS = [self::TYPE_MACHINE => 'machines', self::TYPE_SKILL => 'skills'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Names / active state are cached per request for showing profiles — forget them after any change.
        static::saved(fn () => app(ProfileLists::class)->flush());
        static::deleted(fn () => app(ProfileLists::class)->flush());
    }

    /** Every item (active + hidden) in display order. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /** Only the items offered for new picks, in display order. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }

    /** @return array{key: string, name_bn: string, name_en: string} */
    public function toChoice(): array
    {
        return ['key' => $this->key, 'name_bn' => $this->name_bn, 'name_en' => $this->name_en];
    }
}
