<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Notice (MASTER_SPEC §24): bn/en title + body, optional attachment, pinned, published.
 * The attachment of a "public" notice is on the public disk (plain URL); a "members only" notice's file is on the
 * private disk and is served only through GET /api/notices/{id}/attachment (approved members, admins).
 */
class Notice extends Model
{
    public const PUBLIC_DISK = 'public';

    public const PRIVATE_DISK = 'private';

    public const VISIBILITY_PUBLIC = 'public';

    public const VISIBILITY_MEMBERS = 'members';

    public const VISIBILITIES = [self::VISIBILITY_PUBLIC, self::VISIBILITY_MEMBERS];

    /** Largest accepted attachment, in KB (5 MB). */
    public const MAX_KB = 5120;

    public const ATTACHMENT_MIMES = 'pdf,jpg,jpeg,png,webp';

    protected $fillable = ['title_bn', 'title_en', 'body_bn', 'body_en', 'visibility', 'is_pinned', 'is_published', 'published_at'];

    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'attachment_size' => 'integer',
        ];
    }

    /** Published and its publish date has come (a future date = scheduled, not shown yet). */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_published', true)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function scopePublicOnly(Builder $query): Builder
    {
        return $query->where('visibility', self::VISIBILITY_PUBLIC);
    }

    /** Pinned first, then newest publish date first. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('is_pinned')->orderByDesc('published_at')->orderByDesc('id');
    }

    /** The disk a notice with this visibility keeps its attachment on. */
    public static function diskFor(string $visibility): string
    {
        return $visibility === self::VISIBILITY_MEMBERS ? self::PRIVATE_DISK : self::PUBLIC_DISK;
    }

    public function attachmentDisk(): string
    {
        return self::diskFor($this->visibility);
    }

    public function hasPrivateAttachment(): bool
    {
        return $this->attachment_path !== null && $this->attachmentDisk() === self::PRIVATE_DISK;
    }

    /** Public file URL, or the login-only download route for a members-only notice. */
    public function attachmentUrl(): ?string
    {
        if (! $this->attachment_path) {
            return null;
        }

        return $this->hasPrivateAttachment()
            ? url("/api/notices/{$this->id}/attachment")
            : Storage::disk(self::PUBLIC_DISK)->url($this->attachment_path);
    }

    /** Removes the attachment file from the disk (the columns are left as they are). */
    public function deleteAttachmentFile(): void
    {
        if ($this->attachment_path) {
            Storage::disk($this->attachmentDisk())->delete($this->attachment_path);
        }
    }
}
