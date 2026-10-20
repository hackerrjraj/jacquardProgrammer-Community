<?php

namespace App\Http\Resources;

use App\Models\ExpertiseCard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Home Expertise card (MASTER_SPEC §13). `title` / `description` are in the request's language (English falls back
 * to Bengali); the raw bn/en fields are sent too, like notices. Admin screens get the extra fields.
 *
 * @mixin ExpertiseCard
 */
class ExpertiseCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'icon' => $this->icon,
            'title' => $this->localizedTitle(),
            'description' => $this->localizedDescription(),
            'title_bn' => $this->title_bn,
            'title_en' => $this->title_en,
            'description_bn' => $this->description_bn,
            'description_en' => $this->description_en,
            'image_url' => $this->imageUrl(),
            'sort_order' => $this->sort_order,
        ];

        if ($request->is('api/admin/*')) {
            $data += [
                'is_published' => $this->is_published,
                'created_at' => $this->created_at?->toIso8601String(),
                'updated_at' => $this->updated_at?->toIso8601String(),
            ];
        }

        return $data;
    }
}
