<?php

namespace App\Models;

use Database\Factories\HeroSlideFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'image_path', 'title_bn', 'title_en', 'subtitle_bn', 'subtitle_en',
    'button_text_bn', 'button_text_en', 'button_link', 'sort_order', 'is_active',
])]
class HeroSlide extends Model
{
    /** @use HasFactory<HeroSlideFactory> */
    use HasFactory;

    public const DISK = 'public';

    /** Largest accepted image, in KB (2 MB — the Decided image limit, §38). */
    public const MAX_KB = 2048;

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** Active slides in display order. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }

    /** Every slide (active + hidden) in display order — the admin list. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /** Full public URL of the slide image (needs `php artisan storage:link`). */
    public function imageUrl(): string
    {
        return Storage::disk(self::DISK)->url($this->image_path);
    }

    public function deleteImageFile(): void
    {
        if ($this->image_path) {
            Storage::disk(self::DISK)->delete($this->image_path);
        }
    }
}
