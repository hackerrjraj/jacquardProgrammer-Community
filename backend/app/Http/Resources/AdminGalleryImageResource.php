<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * Gallery image for the admin screens: public fields + publish state, order and dates.
 *
 * @mixin \App\Models\GalleryImage
 */
class AdminGalleryImageResource extends GalleryImageResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'is_published' => $this->is_published,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
