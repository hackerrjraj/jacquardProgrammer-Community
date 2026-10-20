<?php

namespace App\Http\Resources;

use App\Models\AssistanceDocument;
use App\Models\AssistanceRequest;
use App\Models\AssistanceVote;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * A full aid request — for the applicant themself and for admin / super_admin.
 * The applicant, decided_by, paid_by and the vote list (who voted how) appear only when loaded (admin answers).
 *
 * @mixin AssistanceRequest
 */
class AssistanceRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'case_id' => $this->case_id,
            'status' => $this->status,
            'reason_type' => $this->reason_type,
            'reason_other' => $this->reason_other,
            'description' => $this->description,
            'amount_requested' => $this->amount_requested,
            'applying_for' => $this->applying_for_name
                ? ['name' => $this->applying_for_name, 'relation' => $this->applying_for_relation]
                : null,
            'consent_public_name' => $this->consent_public_name,
            'votes' => self::voteCounts($this->resource),
            'documents' => $this->whenLoaded('documents', fn () => $this->documents->map(fn (AssistanceDocument $d) => [
                'id' => $d->id,
                'name' => $d->original_name,
                'mime' => $d->mime,
                'size' => $d->size,
                'url' => url("/api/assistance/{$this->id}/documents/{$d->id}"),
                'created_at' => $d->created_at?->toIso8601String(),
            ])->values()),
            'amount_approved' => $this->amount_approved,
            'decision_note' => $this->decision_note,
            'rejection_reason' => $this->rejection_reason,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'payout' => $this->paid_at ? [
                'method' => $this->payout_method,
                'transaction_id' => $this->payout_transaction_id,
                'date' => $this->payout_date?->format('Y-m-d'),
                'note' => $this->payout_note,
                'proof_url' => $this->payout_proof_path ? url("/api/assistance/{$this->id}/payout-proof") : null,
                'paid_at' => $this->paid_at->toIso8601String(),
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'applicant' => $this->whenLoaded('user', fn () => self::person($this->user, withPhone: true)),
            'decided_by' => $this->whenLoaded('decider', fn () => $this->decider ? self::person($this->decider) : null),
            'paid_by' => $this->whenLoaded('payer', fn () => $this->payer ? self::person($this->payer) : null),
            'voters' => $this->whenLoaded('votes', fn () => $this->votes
                ->sortByDesc('updated_at')
                ->map(fn (AssistanceVote $v) => [
                    'user' => $v->user ? self::person($v->user) : null,
                    'vote' => $v->in_favour ? 'yes' : 'no',
                    'voted_at' => $v->updated_at?->toIso8601String(),
                ])->values()),
        ];
    }

    /** @return array{yes: int, no: int} — from withCount (votes_yes / votes_no) when present. */
    public static function voteCounts(AssistanceRequest $r): array
    {
        if (isset($r->votes_yes, $r->votes_no)) {
            return ['yes' => (int) $r->votes_yes, 'no' => (int) $r->votes_no];
        }

        return [
            'yes' => $r->votes()->where('in_favour', true)->count(),
            'no' => $r->votes()->where('in_favour', false)->count(),
        ];
    }

    /** @return array<string, int|string|null> */
    public static function person(User $user, bool $withPhone = false): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            ...($withPhone ? ['phone' => $user->phone] : []),
            'photo_url' => $user->photo_path ? Storage::disk('public')->url($user->photo_path) : null,
        ];
    }
}
