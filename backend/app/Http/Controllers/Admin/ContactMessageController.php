<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ContactMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Contact form messages for admins (Phase 11a, MASTER_SPEC §37) — admin + super_admin (route middleware) read them and
 * mark them read / unread; only super_admin deletes (route middleware). The delete is audit-logged without the text.
 */
class ContactMessageController extends Controller
{
    public const PER_PAGE = 20;

    /** GET /api/admin/contact-messages?status=unread|read&search=&page= — newest first; meta.unread_count for the badge. */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:unread,read'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $search = self::asciiDigits(trim($filters['search'] ?? ''));

        $page = ContactMessage::query()
            ->when($filters['status'] ?? null, fn (Builder $q, string $s) => $q->where('is_read', $s === 'read'))
            ->when($search !== '', function (Builder $q) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn (Builder $w) => $w->where('name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('subject', 'like', $like));
            })
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn (ContactMessage $m) => self::message($m))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'unread_count' => self::unreadCount(),
            ],
        ]);
    }

    /** GET /api/admin/contact-messages/{id} — reading does not change anything; the screen calls /read. */
    public function show(int $message): JsonResponse
    {
        return response()->json(['data' => self::message(ContactMessage::findOrFail($message))]);
    }

    /** POST /api/admin/contact-messages/{id}/read */
    public function markRead(int $message): JsonResponse
    {
        return $this->setRead($message, true);
    }

    /** POST /api/admin/contact-messages/{id}/unread */
    public function markUnread(int $message): JsonResponse
    {
        return $this->setRead($message, false);
    }

    /** DELETE /api/admin/contact-messages/{id} — super_admin only. The audit log never gets the message text. */
    public function destroy(Request $request, int $message): JsonResponse
    {
        $m = ContactMessage::findOrFail($message);

        DB::transaction(function () use ($request, $m) {
            AuditLog::record($request, 'contact_message.deleted', $m, [
                'name' => $m->name,
                'phone' => $m->phone,
                'subject' => $m->subject,
                'received_at' => $m->created_at?->toIso8601String(),
            ]);
            $m->delete();
        });

        return response()->json([
            'message' => __('messages.contact_message_deleted'),
            'meta' => ['unread_count' => self::unreadCount()],
        ]);
    }

    private function setRead(int $id, bool $read): JsonResponse
    {
        $m = ContactMessage::findOrFail($id);
        $m->forceFill(['is_read' => $read])->save();

        return response()->json([
            'data' => self::message($m),
            'meta' => ['unread_count' => self::unreadCount()],
        ]);
    }

    private static function unreadCount(): int
    {
        return ContactMessage::where('is_read', false)->count();
    }

    /** Lets admins search a mobile number typed with Bengali digits. */
    private static function asciiDigits(string $value): string
    {
        return strtr($value, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
    }

    /** @return array<string, mixed> */
    private static function message(ContactMessage $m): array
    {
        return [
            'id' => $m->id,
            'name' => $m->name,
            'phone' => $m->phone,
            'email' => $m->email,
            'subject' => $m->subject,
            'message' => $m->message,
            'is_read' => (bool) $m->is_read,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
