<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * A member's full profile (MASTER_SPEC §19–§20): own profile and the admin view.
 * Not for public pages — those get their own trimmed resource (Phase 5).
 *
 * @mixin \App\Models\User
 */
class ProfileResource extends UserResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'photo_url' => $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null,
            'bio' => $this->bio,
            'employment_status' => $this->employment_status,
            'designation' => $this->designation,
            'phone_visibility' => $this->phone_visibility,
            'email_visibility' => $this->email_visibility,
            'workplace_visibility' => $this->workplace_visibility,
            'work_history' => $this->whenLoaded('workExperiences', fn () => $this->workExperiences->map->toEntry()->values()),
            'portfolio' => PortfolioItemResource::collection($this->whenLoaded('portfolioItems')),
            'documents' => MemberDocumentResource::collection($this->whenLoaded('documents')),
        ];
    }
}
