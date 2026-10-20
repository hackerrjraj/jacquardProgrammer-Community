<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * One row of the admin / committee Programmers List (MASTER_SPEC §37a):
 * the user fields + photo and employment status + the money block (Phase 7d; null for accounts that owe no dues).
 *
 * @mixin User
 */
class MemberListResource extends UserResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'photo_url' => $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null,
            'employment_status' => $this->employment_status,
            'designation' => $this->designation,
            'payments' => $this->payments_summary,
            // Only with ?month=YYYY-MM (Phase 7f): paid / pending / due / null for that month.
            'month_status' => $this->when($request->filled('month'), fn () => $this->month_status),
        ];
    }
}
