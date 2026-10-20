<?php

namespace App\Http\Controllers;

use App\Http\Resources\AssistanceRequestResource;
use App\Http\Resources\AssistanceVotingResource;
use App\Models\AssistanceDocument;
use App\Models\AssistanceRequest;
use App\Models\AssistanceVote;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\BnDigits;
use App\Support\Notifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Aid requests for members (MASTER_SPEC §29–§30, simple rules of Phase 8a) — any approved account (route middleware):
 * own requests (apply, list, view, cancel), the open-requests list with advisory Yes/No votes, and the private files.
 * Decisions and payouts are in Admin\AssistanceController.
 */
class AssistanceController extends Controller
{
    public const PER_PAGE = 20;

    /** GET /api/assistance — own requests, newest first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $requests = AssistanceRequest::query()
            ->where('user_id', $request->user()->id)
            ->withVoteCounts()
            ->with('documents')
            ->latest('id')
            ->paginate(self::PER_PAGE);

        return AssistanceRequestResource::collection($requests);
    }

    /** GET /api/assistance/{id} — own request only. */
    public function show(Request $request, int $assistance): JsonResponse|AssistanceRequestResource
    {
        $model = AssistanceRequest::withVoteCounts()->with('documents')->findOrFail($assistance);
        if ($model->user_id !== $request->user()->id) {
            return $this->forbidden();
        }

        return new AssistanceRequestResource($model);
    }

    /**
     * POST /api/assistance (multipart): reason_type, reason_other (when "other"), description, amount_requested,
     * applying_for_name? + applying_for_relation, consent_public_name?, documents[]? (0–5 files). Starts as "submitted".
     */
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (! $user->canApplyForAid()) {
            return response()->json(['message' => __('messages.assistance_not_allowed')], 403);
        }

        $request->merge(['amount_requested' => BnDigits::toAscii($request->input('amount_requested'))]);

        $data = $request->validate([
            'reason_type' => ['required', Rule::in(AssistanceRequest::REASONS)],
            'reason_other' => ['nullable', 'required_if:reason_type,'.AssistanceRequest::REASON_OTHER, 'string', 'max:150'],
            'description' => ['required', 'string', 'max:2000'],
            'amount_requested' => ['required', 'integer', 'min:1', 'max:'.AssistanceRequest::MAX_AMOUNT],
            'applying_for_name' => ['nullable', 'string', 'max:100'],
            'applying_for_relation' => ['nullable', 'required_with:applying_for_name', 'string', 'max:50'],
            'consent_public_name' => ['nullable', 'boolean'],
            'documents' => ['nullable', 'array', 'max:'.AssistanceRequest::MAX_DOCUMENTS],
            'documents.*' => ['file', 'mimes:'.AssistanceRequest::FILE_MIMES, 'extensions:'.AssistanceRequest::FILE_MIMES, 'max:'.AssistanceRequest::FILE_MAX_KB],
        ]);

        if ($this->hasOpenRequest($user)) {
            return $this->openExists();
        }

        $forName = trim($data['applying_for_name'] ?? '') ?: null;
        $model = new AssistanceRequest([
            'reason_type' => $data['reason_type'],
            'reason_other' => $data['reason_type'] === AssistanceRequest::REASON_OTHER ? trim($data['reason_other']) : null,
            'description' => trim($data['description']),
            'amount_requested' => (int) $data['amount_requested'],
            'applying_for_name' => $forName,
            'applying_for_relation' => $forName ? trim($data['applying_for_relation']) : null,
            'consent_public_name' => (bool) ($data['consent_public_name'] ?? false),
        ]);
        $model->user_id = $user->id;
        $model->open_key = $user->id;

        // Files first (random names on the private disk); removed again if saving fails.
        $files = collect($request->file('documents', []))->map(fn (UploadedFile $f) => [
            'path' => $f->store('assistance', AssistanceRequest::FILE_DISK),
            'original_name' => mb_substr($f->getClientOriginalName(), 0, 255),
            'mime' => $f->getMimeType() ?? 'application/octet-stream',
            'size' => $f->getSize(),
        ]);

        try {
            DB::transaction(function () use ($request, $model, $files) {
                $model->save();
                $model->case_id = AssistanceRequest::makeCaseId($model->id, (int) $model->created_at->format('Y'));
                $model->save();
                $files->each(fn (array $f) => $model->documents()->create($f));

                AuditLog::record($request, 'assistance.submitted', $model, [
                    'case_id' => $model->case_id,
                    'status' => ['old' => null, 'new' => AssistanceRequest::STATUS_SUBMITTED],
                    'reason_type' => $model->reason_type,
                    'amount_requested' => $model->amount_requested,
                    'documents' => $files->count(),
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            // A second request sent at the same moment.
            AssistanceRequest::deleteFiles($files->pluck('path')->all());

            return $this->openExists();
        } catch (Throwable $e) {
            AssistanceRequest::deleteFiles($files->pluck('path')->all());
            throw $e;
        }
        Notifier::aidVoteOpen($model);

        return response()->json([
            'message' => __('messages.assistance_submitted'),
            'data' => new AssistanceRequestResource($model->load('documents')),
        ], 201);
    }

    /** POST /api/assistance/{id}/cancel — the applicant, only while no decision has been made. */
    public function cancel(Request $request, int $assistance): JsonResponse
    {
        $model = AssistanceRequest::findOrFail($assistance);
        if ($model->user_id !== $request->user()->id) {
            return $this->forbidden();
        }

        $result = DB::transaction(function () use ($request, $assistance) {
            $r = AssistanceRequest::lockForUpdate()->findOrFail($assistance);
            if (! $r->isSubmitted()) {
                return null;
            }
            $r->status = AssistanceRequest::STATUS_CANCELLED;
            $r->cancelled_at = now();
            $r->open_key = null;
            $r->save();

            AuditLog::record($request, 'assistance.cancelled', $r, [
                'case_id' => $r->case_id,
                'status' => ['old' => AssistanceRequest::STATUS_SUBMITTED, 'new' => AssistanceRequest::STATUS_CANCELLED],
            ]);

            return $r;
        });

        if (! $result) {
            return response()->json(['message' => __('messages.assistance_not_submitted')], 422);
        }

        return response()->json([
            'message' => __('messages.assistance_cancelled'),
            'data' => new AssistanceRequestResource($result->load('documents')),
        ]);
    }

    /** GET /api/assistance/open — requests waiting for a decision, newest first, with vote counts and the viewer's own vote. */
    public function open(Request $request): AnonymousResourceCollection
    {
        $viewerId = $request->user()->id;

        $requests = AssistanceRequest::query()
            ->where('status', AssistanceRequest::STATUS_SUBMITTED)
            ->with('user')
            ->withVoteCounts()
            ->addSelect(['my_vote' => AssistanceVote::query()
                ->select('in_favour')
                ->whereColumn('assistance_votes.assistance_request_id', 'assistance_requests.id')
                ->where('assistance_votes.user_id', $viewerId)
                ->limit(1)])
            ->latest('id')
            ->paginate(self::PER_PAGE);

        return AssistanceVotingResource::collection($requests);
    }

    /** POST /api/assistance/{id}/vote {vote: yes|no} — advisory; one per person, changeable until the decision. */
    public function vote(Request $request, int $assistance): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $model = AssistanceRequest::findOrFail($assistance);

        if (! $user->canVoteOnAid()) {
            return response()->json(['message' => __('messages.assistance_vote_not_allowed')], 403);
        }
        if ($model->user_id === $user->id) {
            return response()->json(['message' => __('messages.assistance_vote_own')], 403);
        }

        $data = $request->validate(['vote' => ['required', Rule::in(['yes', 'no'])]]);
        $inFavour = $data['vote'] === 'yes';

        $result = DB::transaction(function () use ($request, $assistance, $user, $inFavour) {
            // Locked so a vote can never slip in after the decision.
            $r = AssistanceRequest::lockForUpdate()->findOrFail($assistance);
            if (! $r->isSubmitted()) {
                return null;
            }

            $vote = AssistanceVote::firstOrNew(['assistance_request_id' => $r->id, 'user_id' => $user->id]);
            $old = $vote->exists ? ($vote->in_favour ? 'yes' : 'no') : null;
            $new = $inFavour ? 'yes' : 'no';
            if ($old !== $new) {
                $vote->in_favour = $inFavour;
                $vote->save();
                AuditLog::record($request, 'assistance.voted', $r, [
                    'case_id' => $r->case_id,
                    'vote' => ['old' => $old, 'new' => $new],
                ]);
            }

            return $r;
        });

        if (! $result) {
            return response()->json(['message' => __('messages.assistance_voting_closed')], 422);
        }

        return response()->json([
            'message' => __('messages.assistance_voted'),
            'data' => [
                'votes' => AssistanceRequestResource::voteCounts($result),
                'my_vote' => $inFavour ? 'yes' : 'no',
            ],
        ]);
    }

    /** GET /api/assistance/{id}/documents/{document} — the applicant, admin or super_admin. */
    public function document(Request $request, int $assistance, int $document): StreamedResponse|JsonResponse
    {
        $model = AssistanceRequest::findOrFail($assistance);
        if (! $model->filesVisibleTo($request->user())) {
            return $this->forbidden();
        }

        $doc = AssistanceDocument::where('assistance_request_id', $model->id)->findOrFail($document);

        return $this->file($doc->path, $doc->mime);
    }

    /** GET /api/assistance/{id}/payout-proof — the applicant, admin or super_admin. */
    public function payoutProof(Request $request, int $assistance): StreamedResponse|JsonResponse
    {
        $model = AssistanceRequest::findOrFail($assistance);
        if (! $model->filesVisibleTo($request->user())) {
            return $this->forbidden();
        }

        return $this->file($model->payout_proof_path, $model->payout_proof_mime);
    }

    private function file(?string $path, ?string $mime): StreamedResponse
    {
        $disk = Storage::disk(AssistanceRequest::FILE_DISK);
        if (! $path || ! $disk->exists($path)) {
            abort(404);
        }

        return $disk->response($path, null, [
            'Content-Type' => $mime ?? 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function hasOpenRequest(User $user): bool
    {
        return AssistanceRequest::where('user_id', $user->id)->whereIn('status', AssistanceRequest::OPEN_STATUSES)->exists();
    }

    private function openExists(): JsonResponse
    {
        return response()->json(['message' => __('messages.assistance_open_exists')], 422);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['message' => __('messages.forbidden')], 403);
    }
}
