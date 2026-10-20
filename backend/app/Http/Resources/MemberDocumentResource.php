<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A member's private document. The file is never public: download_url is an
 * API route that checks the login (owner, admin, super_admin). Viewers who may
 * not download (committee) get only id, type and upload date.
 *
 * @mixin \App\Models\MemberDocument
 */
class MemberDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $canDownload = $this->canBeDownloadedBy($request->user());

        return [
            'id' => $this->id,
            'type' => $this->type,
            'original_name' => $this->when($canDownload, $this->original_name),
            'mime_type' => $this->when($canDownload, $this->mime_type),
            'size' => $this->when($canDownload, $this->size),
            'download_url' => $this->when($canDownload, fn () => url("/api/profile/documents/{$this->id}/download")),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
