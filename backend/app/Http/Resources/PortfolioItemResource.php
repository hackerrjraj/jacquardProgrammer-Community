<?php

namespace App\Http\Resources;

use App\Models\PortfolioItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin \App\Models\PortfolioItem
 */
class PortfolioItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'image_url' => Storage::disk(PortfolioItem::DISK)->url($this->image_path),
            'category' => $this->category,
            'title' => $this->title,
            'description' => $this->description,
            'sort_order' => $this->sort_order,
        ];
    }
}
