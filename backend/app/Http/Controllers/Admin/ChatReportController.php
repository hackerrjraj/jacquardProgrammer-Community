<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ChatReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Chat reports for admins (Phase 9d-1) — admin + super_admin (route middleware). Admins see only the text copy of the
 * one reported message; nothing here returns other messages or opens the conversation (MASTER_SPEC §35).
 */
class ChatReportController extends Controller
{
    public const PER_PAGE = 20;

    /** GET /api/admin/chat-reports?status=&page= — newest first; meta.open_count for the menu badge. */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(ChatReport::STATUSES)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = ChatReport::query()
            ->with(['reporter', 'reportedUser', 'reviewer'])
            ->when($filters['status'] ?? null, fn (Builder $q, string $s) => $q->where('status', $s))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn (ChatReport $r) => self::report($r))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'open_count' => ChatReport::where('status', ChatReport::STATUS_OPEN)->count(),
            ],
        ]);
    }

    /** GET /api/admin/chat-reports/{id} */
    public function show(int $report): JsonResponse
    {
        return response()->json(['data' => self::report(ChatReport::with(['reporter', 'reportedUser', 'reviewer'])->findOrFail($report))]);
    }

    /** POST /api/admin/chat-reports/{id}/review */
    public function review(Request $request, int $report): JsonResponse
    {
        return $this->decide($request, $report, ChatReport::STATUS_REVIEWED, 'messages.chat_report_reviewed');
    }

    /** POST /api/admin/chat-reports/{id}/dismiss */
    public function dismiss(Request $request, int $report): JsonResponse
    {
        return $this->decide($request, $report, ChatReport::STATUS_DISMISSED, 'messages.chat_report_dismissed');
    }

    /** Only an open report can be marked; the audit log gets the decision (no message text). */
    private function decide(Request $request, int $id, string $status, string $message): JsonResponse
    {
        $r = ChatReport::findOrFail($id);
        if ($r->status !== ChatReport::STATUS_OPEN) {
            return response()->json(['message' => __('messages.chat_report_not_open')], 422);
        }

        $r->forceFill(['status' => $status, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()])->save();
        AuditLog::record($request, 'chat_report.'.$status, $r, [
            'reporter_id' => $r->reporter_id,
            'reported_user_id' => $r->reported_user_id,
            'reason' => $r->reason,
        ]);

        return response()->json([
            'message' => __($message),
            'data' => self::report($r->load(['reporter', 'reportedUser', 'reviewer'])),
            'meta' => ['open_count' => ChatReport::where('status', ChatReport::STATUS_OPEN)->count()],
        ]);
    }

    /** @return array<string, mixed> */
    private static function report(ChatReport $r): array
    {
        return [
            'id' => $r->id,
            'reason' => $r->reason,
            'note' => $r->note,
            'message_snapshot' => $r->message_snapshot,
            'status' => $r->status,
            'reporter' => self::person($r->reporter),
            'reported_user' => self::person($r->reportedUser),
            'reviewed_by' => $r->reviewer ? ['id' => $r->reviewer->id, 'name' => $r->reviewer->name] : null,
            'reviewed_at' => $r->reviewed_at?->toIso8601String(),
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }

    /** Basic info only; the screen links to the member's admin profile page with the id. */
    private static function person(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'phone' => $u->phone,
            'status' => $u->status,
            'photo_url' => $u->photo_path ? Storage::disk('public')->url($u->photo_path) : null,
        ];
    }
}
