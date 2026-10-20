<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Gallery category (tab). `images_count` = published images (public list) or all images (admin list).
 *
 * @mixin \App\Models\GalleryCategory
 */
class GalleryCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name_bn' => $this->name_bn,
            'name_en' => $this->name_en,
            'sort_order' => $this->sort_order,
            'images_count' => $this->whenCounted('images'),
        ];
    }
}
