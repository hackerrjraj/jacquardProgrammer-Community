<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminNoticeResource;
use App\Models\AuditLog;
use App\Models\Notice;
use App\Support\Notifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Notice management (MASTER_SPEC §24, §37 Content) — admin + super_admin (route middleware).
 * Every change is written to the audit log; deleting a notice (or its attachment) deletes the file.
 * Attachments of "members only" notices live on the private disk; changing the visibility moves the file.
 * Notifying members about a new notice (§36) is Phase 10.
 */
class NoticeController extends Controller
{
    public const PER_PAGE = 20;

    /** GET /api/admin/notices?status=published|unpublished|scheduled&visibility=public|members&page= */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['published', 'unpublished', 'scheduled'])],
            'visibility' => ['nullable', Rule::in(Notice::VISIBILITIES)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $notices = Notice::query()->ordered()
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => match ($status) {
                'published' => $q->live(),
                'unpublished' => $q->where('is_published', false),
                'scheduled' => $q->where('is_published', true)->where('published_at', '>', now()),
            })
            ->when($filters['visibility'] ?? null, fn (Builder $q, string $v) => $q->where('visibility', $v))
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return AdminNoticeResource::collection($notices);
    }

    /** GET /api/admin/notices/{id} — any notice, published or not. */
    public function show(int $notice): AdminNoticeResource
    {
        return new AdminNoticeResource(Notice::findOrFail($notice));
    }

    /**
     * POST /api/admin/notices (JSON or multipart): title_bn, body_bn, title_en?, body_en?, visibility? (default public),
     * is_published? (default true), is_pinned? (default false), published_at? (empty + published → now), attachment? (file).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            ...$this->textRules(required: true),
            'is_published' => ['sometimes', 'boolean'],
            'is_pinned' => ['sometimes', 'boolean'],
            'attachment' => $this->attachmentRules(required: false),
        ]);

        $notice = new Notice([
            'title_bn' => $this->clean($data['title_bn']),
            'title_en' => $this->clean($data['title_en'] ?? null),
            'body_bn' => $this->clean($data['body_bn']),
            'body_en' => $this->clean($data['body_en'] ?? null),
            'visibility' => $data['visibility'] ?? Notice::VISIBILITY_PUBLIC,
            'is_published' => $data['is_published'] ?? true,
            'is_pinned' => $data['is_pinned'] ?? false,
            'published_at' => $data['published_at'] ?? null,
        ]);
        if ($notice->is_published && $notice->published_at === null) {
            $notice->published_at = now();
        }
        $notice->created_by = $request->user()->id;

        $file = $request->file('attachment');
        if ($file) {
            $this->putAttachment($notice, $file);
        }

        try {
            DB::transaction(function () use ($request, $notice) {
                $notice->save();
                AuditLog::record($request, 'notice.created', $notice, [
                    'notice_id' => $notice->id,
                    'title_bn' => $notice->title_bn,
                    'title_en' => $notice->title_en,
                    'visibility' => $notice->visibility,
                    'is_published' => $notice->is_published,
                    'is_pinned' => $notice->is_pinned,
                    'published_at' => $notice->published_at?->toIso8601String(),
                    'attachment' => $notice->attachment_name,
                ]);
            });
        } catch (Throwable $e) {
            $notice->deleteAttachmentFile();
            throw $e;
        }
        Notifier::noticeLive($notice, $request->user()->id);

        return response()->json([
            'message' => __('messages.notice_created'),
            'data' => new AdminNoticeResource($notice),
        ], 201);
    }

    /**
     * PATCH /api/admin/notices/{id} (JSON) — send only what changes: title_bn, title_en, body_bn, body_en,
     * visibility, published_at. Publish / pin and the attachment have their own routes.
     */
    public function update(Request $request, int $notice): JsonResponse
    {
        $model = Notice::findOrFail($notice);
        $data = $request->validate($this->textRules(required: false));

        foreach (['title_bn', 'title_en', 'body_bn', 'body_en'] as $field) {
            if (array_key_exists($field, $data)) {
                $model->{$field} = $this->clean($data[$field]);
            }
        }
        if (array_key_exists('visibility', $data)) {
            $model->visibility = $data['visibility'];
        }
        if (array_key_exists('published_at', $data)) {
            // Empty on a published notice → now (a published notice always has a date).
            $model->published_at = $data['published_at'] ?? ($model->is_published ? now() : null);
        }

        // Visibility changed → the attachment moves to the matching disk (public ↔ private).
        $fromDisk = Notice::diskFor($model->getOriginal('visibility'));
        $toDisk = $model->attachmentDisk();
        $move = $model->attachment_path && $fromDisk !== $toDisk
            && Storage::disk($fromDisk)->exists($model->attachment_path);

        if ($move) {
            $copied = Storage::disk($toDisk)->writeStream(
                $model->attachment_path,
                Storage::disk($fromDisk)->readStream($model->attachment_path)
            );
            if (! $copied) {
                throw new \RuntimeException("Could not move notice attachment {$model->attachment_path}.");
            }
        }

        try {
            $this->saveWithLog($request, $model, 'notice.updated');
        } catch (Throwable $e) {
            if ($move) {
                Storage::disk($toDisk)->delete($model->attachment_path);
            }
            throw $e;
        }

        if ($move) {
            Storage::disk($fromDisk)->delete($model->attachment_path);
        }

        return $this->ok($model, 'notice_updated');
    }

    /** POST /api/admin/notices/{id}/attachment (multipart: attachment) — adds or replaces the file. */
    public function replaceAttachment(Request $request, int $notice): JsonResponse
    {
        $model = Notice::findOrFail($notice);
        $request->validate(['attachment' => $this->attachmentRules(required: true)]);

        $oldPath = $model->attachment_path;
        $oldName = $model->attachment_name;
        $this->putAttachment($model, $request->file('attachment'));

        try {
            DB::transaction(function () use ($request, $model, $oldName) {
                $model->save();
                AuditLog::record($request, 'notice.attachment_replaced', $model, [
                    'notice_id' => $model->id,
                    'from' => $oldName,
                    'to' => $model->attachment_name,
                ]);
            });
        } catch (Throwable $e) {
            $model->deleteAttachmentFile();
            throw $e;
        }

        if ($oldPath) {
            Storage::disk($model->attachmentDisk())->delete($oldPath);
        }

        return $this->ok($model, 'notice_attachment_saved');
    }

    /** DELETE /api/admin/notices/{id}/attachment — removes the file. */
    public function removeAttachment(Request $request, int $notice): JsonResponse
    {
        $model = Notice::findOrFail($notice);

        if ($model->attachment_path) {
            $old = clone $model;
            $name = $model->attachment_name;
            $model->forceFill(['attachment_path' => null, 'attachment_name' => null, 'attachment_mime' => null, 'attachment_size' => null]);

            DB::transaction(function () use ($request, $model, $name) {
                $model->save();
                AuditLog::record($request, 'notice.attachment_removed', $model, ['notice_id' => $model->id, 'name' => $name]);
            });

            $old->deleteAttachmentFile();
        }

        return $this->ok($model, 'notice_attachment_removed');
    }

    /** POST /api/admin/notices/{id}/publish — a notice published for the first time gets today's date. */
    public function publish(Request $request, int $notice): JsonResponse
    {
        $model = Notice::findOrFail($notice);
        $model->is_published = true;
        $model->published_at ??= now();
        $this->saveWithLog($request, $model, 'notice.published');
        Notifier::noticeLive($model, $request->user()->id);

        return $this->ok($model, 'notice_published');
    }

    /** POST /api/admin/notices/{id}/unpublish */
    public function unpublish(Request $request, int $notice): JsonResponse
    {
        $model = Notice::findOrFail($notice);
        $model->is_published = false;
        $this->saveWithLog($request, $model, 'notice.unpublished');

        return $this->ok($model, 'notice_unpublished');
    }

    /** POST /api/admin/notices/{id}/pin */
    public function pin(Request $request, int $notice): JsonResponse
    {
        $model = Notice::findOrFail($notice);
        $model->is_pinned = true;
        $this->saveWithLog($request, $model, 'notice.pinned');

        return $this->ok($model, 'notice_pinned');
    }

    /** POST /api/admin/notices/{id}/unpin */
    public function unpin(Request $request, int $notice): JsonResponse
    {
        $model = Notice::findOrFail($notice);
        $model->is_pinned = false;
        $this->saveWithLog($request, $model, 'notice.unpinned');

        return $this->ok($model, 'notice_unpinned');
    }

    /** DELETE /api/admin/notices/{id} — the attachment file is deleted too. */
    public function destroy(Request $request, int $notice): JsonResponse
    {
        $model = Notice::findOrFail($notice);

        DB::transaction(function () use ($request, $model) {
            $model->delete();
            AuditLog::record($request, 'notice.deleted', $model, [
                'notice_id' => $model->id,
                'title_bn' => $model->title_bn,
                'title_en' => $model->title_en,
                'visibility' => $model->visibility,
                'attachment' => $model->attachment_name,
            ]);
        });

        $model->deleteAttachmentFile();

        return response()->json(['message' => __('messages.notice_deleted')]);
    }

    // ---- Helpers ------------------------------------------------------------

    /** @return array<string, array<int, mixed>> */
    private function textRules(bool $required): array
    {
        $req = $required ? ['required'] : ['sometimes', 'required'];
        $opt = $required ? ['nullable'] : ['sometimes', 'nullable'];

        return [
            'title_bn' => [...$req, 'string', 'max:200'],
            'title_en' => [...$opt, 'string', 'max:200'],
            'body_bn' => [...$req, 'string', 'max:10000'],
            'body_en' => [...$opt, 'string', 'max:10000'],
            'visibility' => ['sometimes', 'required', Rule::in(Notice::VISIBILITIES)],
            'published_at' => [...$opt, 'date'],
        ];
    }

    /** @return array<int, string> */
    private function attachmentRules(bool $required): array
    {
        return [$required ? 'required' : 'nullable', 'file', 'mimes:'.Notice::ATTACHMENT_MIMES, 'extensions:'.Notice::ATTACHMENT_MIMES, 'max:'.Notice::MAX_KB];
    }

    /**
     * Stores the file under a random name on the disk for the notice's visibility (public / private) and fills the
     * attachment columns (not saved yet).
     */
    private function putAttachment(Notice $notice, UploadedFile $file): void
    {
        $notice->forceFill([
            'attachment_path' => $file->store('notices', $notice->attachmentDisk()),
            'attachment_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'attachment_mime' => $file->getMimeType(),
            'attachment_size' => $file->getSize(),
        ]);
    }

    /** Saves the notice and logs the action with its changes (nothing is logged when nothing changed). */
    private function saveWithLog(Request $request, Notice $notice, string $action): void
    {
        DB::transaction(function () use ($request, $notice, $action) {
            $changes = [];
            foreach ($notice->getDirty() as $field => $new) {
                $old = $notice->getOriginal($field);
                $changes[$field] = [
                    'from' => $old instanceof \DateTimeInterface ? $old->format(DATE_ATOM) : $old,
                    'to' => $new instanceof \DateTimeInterface ? $new->format(DATE_ATOM) : $new,
                ];
            }
            $notice->save();

            if ($changes !== []) {
                AuditLog::record($request, $action, $notice, ['notice_id' => $notice->id, 'changes' => $changes]);
            }
        });
    }

    private function ok(Notice $notice, string $message): JsonResponse
    {
        return response()->json([
            'message' => __('messages.'.$message),
            'data' => new AdminNoticeResource($notice),
        ]);
    }

    /** Trimmed text, or null when empty. */
    private function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
