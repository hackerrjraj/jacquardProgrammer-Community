<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** Home "Expertise" card (MASTER_SPEC §13): bn/en title + text, icon name, optional image on the public disk. */
class ExpertiseCard extends Model
{
    public const DISK = 'public';

    /** Most cards that can exist (the Home section is a short row). */
    public const MAX_CARDS = 12;

    /** Largest accepted image, in KB (2 MB — the Decided image limit, §38). */
    public const MAX_KB = 2048;

    protected $fillable = ['icon', 'title_bn', 'title_en', 'description_bn', 'description_en', 'sort_order', 'is_published'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_published' => 'boolean',
        ];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /** Display order: sort_order, then oldest first. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /** Title in the current language; English falls back to Bengali when empty. */
    public function localizedTitle(): string
    {
        return app()->getLocale() === 'en' && filled($this->title_en) ? $this->title_en : $this->title_bn;
    }

    public function localizedDescription(): string
    {
        return app()->getLocale() === 'en' && filled($this->description_en) ? $this->description_en : $this->description_bn;
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk(self::DISK)->url($this->image_path) : null;
    }

    public function deleteImageFile(): void
    {
        if ($this->image_path) {
            Storage::disk(self::DISK)->delete($this->image_path);
        }
    }
}
