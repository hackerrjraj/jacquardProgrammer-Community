<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** Gallery image (MASTER_SPEC §23): display version + thumbnail on the public disk. */
class GalleryImage extends Model
{
    public const DISK = 'public';

    /** Most images per upload request (PHP's max_file_uploads is 20). */
    public const MAX_PER_UPLOAD = 20;

    /** Largest accepted upload per image, in KB (5 MB — owner decision, Phase 6b; gallery only). */
    public const MAX_KB = 5120;

    protected $fillable = ['gallery_category_id', 'title_bn', 'title_en', 'sort_order', 'is_published'];

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'sort_order' => 'integer',
            'is_published' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(GalleryCategory::class, 'gallery_category_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /** Display order: sort_order, then newest first. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderByDesc('id');
    }

    public function imageUrl(): string
    {
        return Storage::disk(self::DISK)->url($this->image_path);
    }

    public function thumbUrl(): string
    {
        return Storage::disk(self::DISK)->url($this->thumb_path);
    }

    /** Removes both files from the disk. */
    public function deleteFiles(): void
    {
        Storage::disk(self::DISK)->delete([$this->image_path, $this->thumb_path]);
    }
}
