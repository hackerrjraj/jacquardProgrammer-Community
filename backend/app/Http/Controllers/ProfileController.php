<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProfileRequest;
use App\Http\Requests\UploadMemberDocumentRequest;
use App\Http\Requests\UploadProfilePhotoRequest;
use App\Http\Resources\MemberDocumentResource;
use App\Http\Resources\ProfileResource;
use App\Models\AuditLog;
use App\Models\MemberDocument;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A member's own profile (MASTER_SPEC §19–§20) + photo and private documents (§38).
 * Every change is written to the audit log.
 */
class ProfileController extends Controller
{
    /** Relations included in every profile response. */
    public const WITH = ['documents', 'workExperiences', 'portfolioItems'];

    /** GET /api/profile */
    public function show(Request $request): ProfileResource
    {
        return new ProfileResource($request->user()->load(self::WITH));
    }

    /** PUT /api/profile — only the fields sent are changed; work_history (if sent) replaces the whole list. */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $history = array_key_exists('work_history', $data) ? $data['work_history'] : null;
        unset($data['work_history']);

        DB::transaction(function () use ($request, $user, $data, $history) {
            $user->fill($data);
            $changes = [];
            foreach ($user->getDirty() as $field => $new) {
                $changes[$field] = ['from' => $user->getOriginal($field), 'to' => $user->{$field}];
            }
            $user->save();

            if ($history !== null) {
                $old = $user->workExperiences()->get()->map->toEntry()->all();
                $user->workExperiences()->delete();
                foreach (array_values($history) as $i => $entry) {
                    $user->workExperiences()->create([
                        'company' => $entry['company'],
                        'position' => $entry['position'],
                        'start_year' => (int) $entry['start_year'],
                        'end_year' => $entry['end_year'] === 'present' ? null : (int) $entry['end_year'],
                        'sort_order' => $i,
                    ]);
                }
                $new = $user->workExperiences()->get()->map->toEntry()->all();
                if ($old !== $new) {
                    $changes['work_history'] = ['from' => $old, 'to' => $new];
                }
            }

            AuditLog::record($request, 'profile.updated', $user, ['changes' => $changes]);
        });

        return $this->done('messages.profile_updated', $user);
    }

    /** POST /api/profile/photo (multipart, field `photo`) — replaces and deletes the old photo. */
    public function uploadPhoto(UploadProfilePhotoRequest $request): JsonResponse
    {
        $user = $request->user();
        $old = $user->photo_path;
        $path = $request->file('photo')->store('profile-photos', 'public'); // random file name

        DB::transaction(function () use ($request, $user, $path) {
            $user->photo_path = $path;
            $user->save();

            AuditLog::record($request, 'profile.photo_updated', $user);
        });

        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return $this->done('messages.photo_updated', $user);
    }

    /** POST /api/profile/documents (multipart, fields `type` + `document`) */
    public function uploadDocument(UploadMemberDocumentRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->documents()->count() >= MemberDocument::MAX_PER_MEMBER) {
            return response()->json(['message' => __('messages.documents_limit', ['max' => MemberDocument::MAX_PER_MEMBER])], 422);
        }

        $file = $request->file('document');
        $path = $file->store("member-documents/{$user->id}", MemberDocument::DISK); // random file name

        $document = DB::transaction(function () use ($request, $user, $file, $path) {
            $document = $user->documents()->create([
                'type' => $request->validated('type'),
                'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                'path' => $path,
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'size' => $file->getSize(),
            ]);

            AuditLog::record($request, 'profile.document_added', $user, [
                'document_id' => $document->id,
                'type' => $document->type,
                'name' => $document->original_name,
            ]);

            return $document;
        });

        return response()->json([
            'message' => __('messages.document_uploaded'),
            'data' => new MemberDocumentResource($document),
        ], 201);
    }

    /** DELETE /api/profile/documents/{document} — own documents only (others get 404). */
    public function destroyDocument(Request $request, int $document): JsonResponse
    {
        $user = $request->user();
        $doc = $user->documents()->findOrFail($document);

        DB::transaction(function () use ($request, $user, $doc) {
            $doc->delete();

            AuditLog::record($request, 'profile.document_deleted', $user, [
                'document_id' => $doc->id,
                'type' => $doc->type,
                'name' => $doc->original_name,
            ]);
        });

        Storage::disk(MemberDocument::DISK)->delete($doc->path);

        return response()->json(['message' => __('messages.document_deleted')]);
    }

    /**
     * GET /api/profile/documents/{document}/download — the owner, admin or super_admin
     * only. Committee and other members get 403 (MASTER_SPEC §10).
     */
    public function downloadDocument(Request $request, MemberDocument $document): StreamedResponse|JsonResponse
    {
        if (! $document->canBeDownloadedBy($request->user())) {
            return response()->json(['message' => __('messages.forbidden')], 403);
        }

        $disk = Storage::disk(MemberDocument::DISK);
        if (! $disk->exists($document->path)) {
            abort(404);
        }

        return $disk->download($document->path, $document->original_name, [
            'Content-Type' => $document->mime_type,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function done(string $messageKey, User $user): JsonResponse
    {
        return response()->json([
            'message' => __($messageKey),
            'data' => new ProfileResource($user->load(self::WITH)),
        ]);
    }
}
