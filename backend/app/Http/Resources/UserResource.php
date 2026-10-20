<?php

namespace App\Http\Resources;

use App\Models\ProfileListItem;
use App\Models\User;
use App\Support\CommitteeChat;
use App\Support\ProfileLists;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A member's own account data (GET /api/me) and the admin member list.
 * Not for public pages — those get their own trimmed resource (Phase 5).
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Email + account-admin fields only for the account itself and admin / super_admin. Committee reads the
        // Programmers List / profiles read-only and does not need them (Phase 12c, MASTER_SPEC §37a).
        $viewer = $request->user();
        $full = ! $viewer || $viewer->isAdmin() || $viewer->id === $this->id;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->when($full, fn () => $this->email),
            'district' => $this->district,
            'workplace' => $this->workplace,
            'experience_years' => $this->experience_years,
            'machines' => app(ProfileLists::class)->resolve(ProfileListItem::TYPE_MACHINE, $this->machines),
            'skills' => app(ProfileLists::class)->resolve(ProfileListItem::TYPE_SKILL, $this->skills),
            'other_machine' => $this->other_machine,
            'other_skill' => $this->other_skill,
            'role' => $this->role,
            'status' => $this->status,
            'rejection_reason' => $this->when($full, fn () => $this->rejection_reason),
            'must_change_password' => $this->when($full, fn () => (bool) $this->must_change_password),
            'approved_at' => $this->when($full, fn () => $this->approved_at?->toIso8601String()),
            'approved_by' => $this->when($full, fn () => $this->approved_by),
            'created_at' => $this->created_at?->toIso8601String(),
            // Own account only: may open the private Committee Chat (decides the menu item; the API checks again).
            'committee_chat' => $this->when($viewer && $viewer->id === $this->id, fn () => CommitteeChat::canAccess($this->resource)),
        ];
    }
}
