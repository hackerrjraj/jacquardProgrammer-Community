<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Notice for the public and member lists (MASTER_SPEC §24). The admin screens use AdminNoticeResource.
 *
 * @mixin \App\Models\Notice
 */
class NoticeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title_bn' => $this->title_bn,
            'title_en' => $this->title_en,
            'body_bn' => $this->body_bn,
            'body_en' => $this->body_en,
            'visibility' => $this->visibility,
            'is_pinned' => $this->is_pinned,
            'published_at' => $this->published_at?->toIso8601String(),
            'attachment' => $this->attachment_path ? [
                'url' => $this->attachmentUrl(),
                'name' => $this->attachment_name,
                'mime_type' => $this->attachment_mime,
                'size' => $this->attachment_size,
                'is_image' => str_starts_with((string) $this->attachment_mime, 'image/'),
                // true → the url needs the login cookie (members-only notice, private disk)
                'is_private' => $this->hasPrivateAttachment(),
            ] : null,
        ];
    }
}
