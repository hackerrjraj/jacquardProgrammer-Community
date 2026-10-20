<?php

namespace App\Http\Resources;

use App\Models\ProfileListItem;
use App\Models\User;
use App\Support\ProfileLists;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A directory card (MASTER_SPEC §21) — public, no login. Only fields meant for the public:
 * never phone/email here, never role, status, documents or payment data.
 * `workplace` is left out entirely unless the member's workplace visibility allows it.
 *
 * @mixin \App\Models\User
 */
class PublicProgrammerResource extends JsonResource
{
    public const EXCERPT_LENGTH = 160;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'photo_url' => $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null,
            'designation' => $this->designation,
            'employment_status' => $this->employment_status,
            'district' => $this->district,
            $this->mergeWhen($this->shows($this->workplace_visibility, $request), fn () => ['workplace' => $this->workplace]),
            'experience_years' => $this->experience_years,
            'machines' => app(ProfileLists::class)->resolve(ProfileListItem::TYPE_MACHINE, $this->machines, $this->other_machine),
            'bio_excerpt' => $this->bio ? Str::limit($this->bio, self::EXCERPT_LENGTH) : null,
            'portfolio_count' => (int) ($this->portfolio_items_count ?? 0),
        ];
    }

    /** Visibility check against the visitor (a logged-in approved member sees "members only" fields). */
    protected function shows(?string $visibility, Request $request): bool
    {
        return User::visibleTo($visibility, $request->user('sanctum'));
    }
}
