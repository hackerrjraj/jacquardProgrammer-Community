<?php

namespace App\Http\Controllers;

use App\Events\CommitteeChatMessageSent;
use App\Events\CommitteeChatRead;
use App\Models\CommitteeChatMessage;
use App\Models\User;
use App\Notifications\CommitteeChatPushAlert;
use App\Support\CommitteeChat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The private Committee Chat: one group chat for the active committee panel members + admin / super_admin
 * (App\Support\CommitteeChat). Everyone else gets 403 on every route. Same pattern as the 1-to-1 chat
 * (Reverb live delivery, push after the answer, read position for the badge) plus one attachment per message.
 */
class CommitteeChatController extends Controller
{
    public const MESSAGES_PER_PAGE = 30;

    private const LIVE_DOWN_KEY = 'committee_chat_live_down';

    private const LIVE_DOWN_SECONDS = 10;

    /** GET /api/committee-chat/unread — unread total for the menu badge (members of the chat only). */
    public function unread(Request $request): JsonResponse
    {
        return response()->json(['data' => ['unread_total' => CommitteeChat::unreadFor($this->member($request))]]);
    }

    /** GET /api/committee-chat/messages?before={id} — 30 per page, oldest first; meta.members = people in the chat. */
    public function messages(Request $request): JsonResponse
    {
        $data = $request->validate(['before' => ['nullable', 'integer', 'min:1']]);
        $me = $this->member($request);

        $rows = CommitteeChatMessage::query()->with('sender')
            ->when($data['before'] ?? null, fn (Builder $q, int $before) => $q->where('id', '<', $before))
            ->orderByDesc('id')
            ->limit(self::MESSAGES_PER_PAGE + 1)
            ->get();

        return response()->json([
            'data' => $rows->take(self::MESSAGES_PER_PAGE)->reverse()->values()->map(fn ($m) => self::message($m, $me)),
            'meta' => [
                'has_more' => $rows->count() > self::MESSAGES_PER_PAGE,
                'members' => CommitteeChat::members()->count(),
                'unread_total' => CommitteeChat::unreadFor($me),
            ],
        ]);
    }

    /** POST /api/committee-chat/messages {body?, attachment?} — text and / or one file; rate limited (`chat`). */
    public function send(Request $request): JsonResponse
    {
        $me = $this->member($request);
        $request->merge(['body' => is_string($request->input('body')) ? trim($request->input('body')) : $request->input('body')]);
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:'.CommitteeChatMessage::MAX_LENGTH, 'required_without:attachment'],
            // Same checks as member documents: real content + extension, PDF / JPG / PNG, max 5 MB (MASTER_SPEC §19, §38).
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'max:5120', 'required_without:body'],
        ]);

        CommitteeChat::ensureReadRows();
        $file = $request->file('attachment');
        $message = CommitteeChatMessage::create([
            'sender_id' => $me->id,
            'body' => ($data['body'] ?? '') === '' ? null : $data['body'],
            'attachment_path' => $file?->store('committee-chat', CommitteeChatMessage::DISK),
            'attachment_name' => $file ? mb_substr($file->getClientOriginalName(), 0, 200) : null,
            'attachment_mime' => $file?->getMimeType(),
            'attachment_size' => $file?->getSize(),
        ]);
        CommitteeChat::markRead($me, $message->id); // my own message counts as read
        $message->load('sender');

        $recipients = CommitteeChat::members()->pluck('id')->all();
        self::broadcastQuietly(new CommitteeChatMessageSent($recipients, self::message($message, null)));
        // Push after the answer has gone out (like the 1-to-1 chat), only to current members except the sender.
        defer(fn () => self::pushQuietly($me->id, $message->id));

        return response()->json(['data' => self::message($message, $me)], 201);
    }

    /** POST /api/committee-chat/read — everything so far is read; returns the new unread total. */
    public function read(Request $request): JsonResponse
    {
        $me = $this->member($request);
        $latest = (int) CommitteeChatMessage::max('id');
        if ($latest) {
            CommitteeChat::markRead($me, $latest);
            self::broadcastQuietly(new CommitteeChatRead($me->id));
        }

        return response()->json(['data' => ['unread_total' => CommitteeChat::unreadFor($me)]]);
    }

    /** GET /api/committee-chat/messages/{id}/attachment — members only; images inline, other files as download. */
    public function attachment(Request $request, int $message): StreamedResponse
    {
        $this->member($request);
        $m = CommitteeChatMessage::findOrFail($message);
        $disk = Storage::disk(CommitteeChatMessage::DISK);
        abort_unless($m->attachment_path && $disk->exists($m->attachment_path), 404);

        return $disk->response($m->attachment_path, $m->attachment_name, [
            'Content-Type' => $m->attachment_mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], $m->isImage() ? 'inline' : 'attachment');
    }

    // ---- helpers ----

    /** The caller, if allowed in the chat (everyone else: 403, whatever their role). */
    private function member(Request $request): User
    {
        $user = $request->user();
        abort_unless(CommitteeChat::canAccess($user), 403, __('messages.committee_chat_forbidden'));

        return $user;
    }

    /** @return array<string, mixed> */
    public static function message(CommitteeChatMessage $m, ?User $me): array
    {
        return [
            'id' => $m->id,
            'body' => $m->body,
            'mine' => $me ? $m->sender_id === $me->id : null,
            'sender' => [
                'id' => $m->sender_id,
                'name' => $m->sender?->name,
                'photo_url' => $m->sender?->photo_path ? Storage::disk('public')->url($m->sender->photo_path) : null,
            ],
            'attachment' => $m->attachment_path ? [
                'name' => $m->attachment_name,
                'mime' => $m->attachment_mime,
                'size' => $m->attachment_size,
                'is_image' => $m->isImage(),
            ] : null,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }

    /** Live delivery is extra: when Reverb is down the message is still saved (see ChatController). */
    private static function broadcastQuietly(object $event): void
    {
        if (Cache::has(self::LIVE_DOWN_KEY)) {
            return;
        }
        try {
            event($event);
        } catch (Throwable $e) {
            Cache::put(self::LIVE_DOWN_KEY, true, self::LIVE_DOWN_SECONDS);
            Log::warning('Committee chat live update not sent: '.$e->getMessage());
        }
    }

    /** Push to the current members (not the sender) who have push on. Never the message text. */
    private static function pushQuietly(int $senderId, int $messageId): void
    {
        try {
            $sender = User::find($senderId);
            if (! $sender) {
                return;
            }
            foreach (CommitteeChat::members()->where('id', '!=', $senderId)->get() as $member) {
                if ($member->wantsPush()) {
                    $member->notify(new CommitteeChatPushAlert($sender->name));
                }
            }
        } catch (Throwable $e) {
            Log::warning("Committee chat push for message {$messageId} not sent: ".$e->getMessage());
        }
    }
}
