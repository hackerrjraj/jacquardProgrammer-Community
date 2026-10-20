<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public gallery image (MASTER_SPEC §23). The admin list uses AdminGalleryImageResource (extra fields).
 *
 * @mixin \App\Models\GalleryImage
 */
class GalleryImageResource extends JsonResource
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
            'image_url' => $this->imageUrl(),
            'thumb_url' => $this->thumbUrl(),
            'width' => $this->width,
            'height' => $this->height,
            'category' => $this->whenLoaded('category', fn () => [
                'key' => $this->category->key,
                'name_bn' => $this->category->name_bn,
                'name_en' => $this->category->name_en,
            ]),
        ];
    }
}
