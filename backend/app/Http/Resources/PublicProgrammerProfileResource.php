<?php

namespace App\Http\Resources;

use App\Http\Controllers\ChatController;
use App\Models\PortfolioItem;
use App\Models\ProfileListItem;
use App\Models\User;
use App\Support\ProfileLists;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Public programmer profile (MASTER_SPEC §22) = the directory card + full bio, skills,
 * work history and portfolio. `phone` / `email` are left out entirely unless the member's
 * visibility setting (§20) allows them for this visitor.
 * Never: documents, NID/birth certificate, payment data, role, status, audit data.
 *
 * @mixin User
 */
class PublicProgrammerProfileResource extends PublicProgrammerResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $card = parent::toArray($request);
        unset($card['bio_excerpt']);

        return [
            ...$card,
            $this->mergeWhen($this->shows($this->phone_visibility, $request), fn () => ['phone' => $this->phone]),
            $this->mergeWhen($this->shows($this->email_visibility, $request) && $this->email, fn () => ['email' => $this->email]),
            // Phase 9b: whether the "Message" button may be shown (false for the demo programmers).
            'accepts_messages' => ChatController::canChatWith($this->resource),
            'bio' => $this->bio,
            'skills' => app(ProfileLists::class)->resolve(ProfileListItem::TYPE_SKILL, $this->skills, $this->other_skill),
            'work_history' => $this->workExperiences->map->toEntry()->values(),
            'portfolio' => $this->portfolioItems->map(fn (PortfolioItem $item) => [
                'id' => $item->id,
                'category' => $item->category,
                'title' => $item->title,
                'description' => $item->description,
                'image_url' => Storage::disk(PortfolioItem::DISK)->url($item->image_path),
            ])->values(),
        ];
    }
}
