<?php

namespace App\Http\Controllers;

use App\Http\Resources\NoticeResource;
use App\Models\Notice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Notices for readers (MASTER_SPEC §24), read-only, pinned first then newest.
 * Public routes (no login, 60/min/IP): published "public" notices only.
 * Member routes (approved members): published notices incl. "members only".
 * Unpublished, scheduled (future publish date) and unknown notices → the same 404.
 */
class NoticeController extends Controller
{
    public const PER_PAGE = 12;

    /** GET /api/public/notices?page= */
    public function publicIndex(Request $request): AnonymousResourceCollection
    {
        return $this->list($request, Notice::query()->live()->publicOnly());
    }

    /** GET /api/public/notices/{id} */
    public function publicShow(string $id): NoticeResource
    {
        return $this->find($id, Notice::query()->live()->publicOnly());
    }

    /** GET /api/notices?page= */
    public function memberIndex(Request $request): AnonymousResourceCollection
    {
        return $this->list($request, Notice::query()->live());
    }

    /** GET /api/notices/{id} */
    public function memberShow(string $id): NoticeResource
    {
        return $this->find($id, Notice::query()->live());
    }

    /**
     * GET /api/notices/{id}/attachment — the file of a notice, shown inline (approved members: live notices only;
     * admin / super_admin: any notice, for the admin preview). Members-only files are only reachable this way.
     */
    public function attachment(Request $request, string $id): StreamedResponse
    {
        $query = $request->user()->isAdmin() ? Notice::query() : Notice::query()->live();
        $notice = ctype_digit($id) ? $query->find((int) $id) : null;
        $disk = $notice?->attachment_path ? Storage::disk($notice->attachmentDisk()) : null;

        if ($disk === null || ! $disk->exists($notice->attachment_path)) {
            throw new HttpResponseException(response()->json(['message' => __('messages.notice_not_found')], 404));
        }

        return $disk->response($notice->attachment_path, $notice->attachment_name, [
            'Content-Type' => $notice->attachment_mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function list(Request $request, Builder $query): AnonymousResourceCollection
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);

        return NoticeResource::collection($query->ordered()->paginate(self::PER_PAGE)->withQueryString());
    }

    private function find(string $id, Builder $query): NoticeResource
    {
        $notice = ctype_digit($id) ? $query->find((int) $id) : null;

        if ($notice === null) {
            throw new HttpResponseException(response()->json(['message' => __('messages.notice_not_found')], 404));
        }

        return new NoticeResource($notice);
    }
}
