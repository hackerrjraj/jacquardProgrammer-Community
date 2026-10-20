<?php

namespace App\Http\Controllers;

use App\Models\UserNotification;
use App\Support\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * In-app notifications (Phase 10a, MASTER_SPEC §36): the bell's unread count, the list, mark as read.
 * Every member sees only their own notifications; texts come in the request's language.
 */
class NotificationController extends Controller
{
    public const PER_PAGE = 20;

    /** GET /api/notifications?page= — newest first, 20 per page; meta.unread_count. */
    public function index(Request $request): JsonResponse
    {
        Notifier::notifyDueNotices();
        $page = UserNotification::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'data' => $page->getCollection()->map(fn (UserNotification $n) => $this->item($n))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'unread_count' => $this->unreadCount($request),
            ],
        ]);
    }

    /** GET /api/notifications/unread — the bell's number. */
    public function unread(Request $request): JsonResponse
    {
        Notifier::notifyDueNotices();

        return response()->json(['data' => ['unread_count' => $this->unreadCount($request)]]);
    }

    /** POST /api/notifications/{id}/read — only your own (others → 404). */
    public function read(Request $request, int $notification): JsonResponse
    {
        $n = UserNotification::query()->where('user_id', $request->user()->id)->findOrFail($notification);
        if (! $n->read_at) {
            $n->read_at = now();
            $n->save();
        }

        return response()->json(['data' => ['unread_count' => $this->unreadCount($request)]]);
    }

    /** POST /api/notifications/read-all */
    public function readAll(Request $request): JsonResponse
    {
        UserNotification::query()->where('user_id', $request->user()->id)->unread()->update(['read_at' => now()]);

        return response()->json(['message' => __('messages.notifications_all_read'), 'data' => ['unread_count' => 0]]);
    }

    private function unreadCount(Request $request): int
    {
        return UserNotification::query()->where('user_id', $request->user()->id)->unread()->count();
    }

    /** @return array<string, mixed> */
    private function item(UserNotification $n): array
    {
        return [
            'id' => $n->id,
            'type' => $n->type,
            ...$n->text(),
            'link' => $n->link(),
            'read' => $n->read_at !== null,
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }
}
