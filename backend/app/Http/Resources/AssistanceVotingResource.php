<?php

namespace App\Http\Resources;

use App\Models\AssistanceRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An open aid request as other members see it on the voting list (MASTER_SPEC §30): the case, the vote counts
 * and the viewer's own vote — never who else voted how, never the documents or the applicant's phone.
 *
 * @mixin AssistanceRequest
 */
class AssistanceVotingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

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
            'applicant' => AssistanceRequestResource::person($this->user),
            'votes' => AssistanceRequestResource::voteCounts($this->resource),
            'my_vote' => $this->my_vote === null ? null : ((bool) $this->my_vote ? 'yes' : 'no'),
            'can_vote' => $this->isSubmitted() && $viewer->canVoteOnAid() && $this->user_id !== $viewer->id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
