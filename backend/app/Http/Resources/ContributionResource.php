<?php

namespace App\Http\Resources;

use App\Models\Contribution;
use App\Models\ContributionMonth;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * A contribution payment. The member and reviewer blocks appear only when loaded (admin lists).
 *
 * @mixin Contribution
 */
class ContributionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'method' => $this->method,
            'sender_number' => $this->sender_number,
            'transaction_id' => $this->transaction_id,
            'amount' => $this->amount,
            'months' => $this->months->map(fn (ContributionMonth $m) => [
                'month' => $m->month->format('Y-m'),
                'amount' => $m->amount,
            ])->values(),
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'proof_url' => $this->proof_path ? url("/api/contributions/{$this->id}/proof") : null,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'member' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'phone' => $this->user->phone,
                'photo_url' => $this->user->photo_path ? Storage::disk('public')->url($this->user->photo_path) : null,
            ]),
            'reviewed_by' => $this->whenLoaded('reviewer', fn () => $this->reviewer ? [
                'id' => $this->reviewer->id,
                'name' => $this->reviewer->name,
            ] : null),
        ];
    }
}
