<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * Notice for the admin screens: public fields + publish state and dates.
 *
 * @mixin \App\Models\Notice
 */
class AdminNoticeResource extends NoticeResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'is_published' => $this->is_published,
            'is_scheduled' => $this->is_published && $this->published_at?->isFuture() === true,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
