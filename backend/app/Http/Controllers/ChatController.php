<?php

namespace App\Http\Controllers;

use App\Events\ChatMessageSent;
use App\Events\ChatMessagesRead;
use App\Models\ChatBlock;
use App\Models\ChatMessage;
use App\Models\ChatReport;
use App\Models\Conversation;
use App\Models\User;
use App\Notifications\ChatPushAlert;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * 1-to-1 chat between approved members (MASTER_SPEC §35, Phase 9a). Only the two participants can read or write a
 * conversation — there is no admin view. Approved, non-demo accounts only; a member who is no longer approved keeps
 * their history visible to the other person, but cannot get new messages.
 */
class ChatController extends Controller
{
    public const PER_PAGE = 20;

    public const MESSAGES_PER_PAGE = 30;

    private const LIVE_DOWN_KEY = 'chat_live_down';

    private const LIVE_DOWN_SECONDS = 10;

    /** GET /api/chat/conversations — mine with at least one message, newest activity first; meta.unread_total. */
    public function index(Request $request): JsonResponse
    {
        $me = $request->user();
        $page = Conversation::query()
            ->forUser($me)
            ->whereNotNull('last_message_at')
            ->with(['userOne', 'userTwo', 'lastMessage'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);
        $blocks = self::blockSets($me);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Conversation $c) => $this->conversation($c, $me, $blocks))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'unread_total' => self::unreadTotal($me),
            ],
        ]);
    }

    /** GET /api/chat/unread — total unread messages (for the menu badge). */
    public function unread(Request $request): JsonResponse
    {
        return response()->json(['data' => ['unread_total' => self::unreadTotal($request->user())]]);
    }

    /** POST /api/chat/conversations {user_id} — opens (or creates) the conversation with another member. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'min:1']]);
        $me = $request->user();

        if ((int) $data['user_id'] === $me->id) {
            return response()->json(['message' => __('messages.chat_self')], 422);
        }
        $partner = User::find($data['user_id']);
        if (! $partner || ! self::canChatWith($partner)) {
            return response()->json(['message' => __('messages.chat_partner_unavailable')], 422);
        }

        $conversation = Conversation::between($me, $partner);

        return response()->json(
            ['data' => $this->conversation($conversation->load(['userOne', 'userTwo', 'lastMessage']), $me)],
            $conversation->wasRecentlyCreated ? 201 : 200,
        );
    }

    /** GET /api/chat/conversations/{id} — participants only. */
    public function show(Request $request, int $conversation): JsonResponse
    {
        $c = $this->find($request, $conversation);

        return response()->json(['data' => $this->conversation($c->load(['userOne', 'userTwo', 'lastMessage']), $request->user())]);
    }

    /**
     * GET /api/chat/conversations/{id}/messages?before={messageId} — up to 30 messages, oldest first
     * (before = load the older page). Each mine-message says whether the other person has read it.
     */
    public function messages(Request $request, int $conversation): JsonResponse
    {
        $data = $request->validate(['before' => ['nullable', 'integer', 'min:1']]);
        $me = $request->user();
        $c = $this->find($request, $conversation);

        $rows = $c->messages()
            ->when($data['before'] ?? null, fn (Builder $q, int $before) => $q->where('id', '<', $before))
            ->orderByDesc('id')
            ->limit(self::MESSAGES_PER_PAGE + 1)
            ->get();
        $hasMore = $rows->count() > self::MESSAGES_PER_PAGE;
        $partnerRead = (int) $c->partnerLastReadId($me);
        // Messages of this page that I have already reported (9d-1) — the screen hides "Report" for them.
        $reported = ChatReport::where('reporter_id', $me->id)->whereIn('message_id', $rows->pluck('id'))->pluck('message_id')->all();

        return response()->json([
            'data' => $rows->take(self::MESSAGES_PER_PAGE)->reverse()->values()
                ->map(fn (ChatMessage $m) => self::message($m, $me, $partnerRead, in_array($m->id, $reported, true))),
            'meta' => ['has_more' => $hasMore, 'partner_last_read_id' => $partnerRead ?: null],
        ]);
    }

    /** POST /api/chat/conversations/{id}/messages {body} — rate limited (`chat`). */
    public function send(Request $request, int $conversation): JsonResponse
    {
        $me = $request->user();
        $c = $this->find($request, $conversation);

        $request->merge(['body' => is_string($request->input('body')) ? trim($request->input('body')) : $request->input('body')]);
        $data = $request->validate(['body' => ['required', 'string', 'max:'.ChatMessage::MAX_LENGTH]]);

        // A block in either direction stops sending (9d-1). The blocked side gets a neutral message only.
        $partner = $c->partnerOf($me);
        $block = $partner ? ChatBlock::stateBetween($me, $partner) : ['blocked_by_me' => false, 'blocked_me' => false];
        if ($block['blocked_by_me'] || $block['blocked_me']) {
            return response()->json(['message' => __($block['blocked_by_me'] ? 'messages.chat_you_blocked' : 'messages.chat_cannot_reply')], 403);
        }
        // Partner suspended, pending, rejected (removed), demo or gone (9d-2): refused, history stays readable.
        if (! $partner || ! self::canChatWith($partner)) {
            return response()->json(['message' => __('messages.chat_partner_not_available')], 403);
        }

        $message = DB::transaction(function () use ($c, $me, $data) {
            $message = $c->messages()->create(['sender_id' => $me->id, 'body' => $data['body']]);
            $c->forceFill(['last_message_at' => $message->created_at])->save();
            $c->markReadBy($me, $message->id); // my own message counts as read by me

            return $message;
        });
        self::broadcastQuietly(new ChatMessageSent($c, $message));
        // Phone / desktop push (10c-3) after the answer has gone out — no queue worker needed, sending stays fast.
        $partnerId = $partner->id;
        defer(fn () => self::pushQuietly($c->id, $me->id, $partnerId));

        return response()->json(['data' => self::message($message, $me, (int) $c->partnerLastReadId($me))], 201);
    }

    /** POST /api/chat/conversations/{id}/read — marks every message so far as read; returns the new unread total. */
    public function read(Request $request, int $conversation): JsonResponse
    {
        $me = $request->user();
        $c = $this->find($request, $conversation);
        $latest = (int) $c->messages()->max('id');
        if ($latest && $c->markReadBy($me, $latest)) {
            self::broadcastQuietly(new ChatMessagesRead($c, $me->id, $latest));
        }

        return response()->json(['data' => ['unread_count' => 0, 'unread_total' => self::unreadTotal($me)]]);
    }

    // ---- helpers ----

    /**
     * Live delivery (Phase 9c) is extra: when the chat server (Reverb) is down the message is still saved and the API
     * answers normally — the other person sees it on the next load. After a failure, live sends are skipped for a few
     * seconds so every send / read does not wait for the connection timeout again.
     */
    private static function broadcastQuietly(object $event): void
    {
        if (Cache::has(self::LIVE_DOWN_KEY)) {
            return;
        }
        try {
            event($event); // ShouldBroadcastNow → sent right here (no queue worker), errors land below
        } catch (Throwable $e) {
            Cache::put(self::LIVE_DOWN_KEY, true, self::LIVE_DOWN_SECONDS);
            Log::warning('Chat live update not sent: '.$e->getMessage());
        }
    }

    /**
     * Push for a new message (Phase 10c-3): only to an approved, non-demo receiver with push on + a subscribed browser,
     * and only while neither of the two has blocked the other. Checked again here, as this runs after the answer.
     * Never the message text. A failure is only logged — the message is already saved and delivered live.
     */
    private static function pushQuietly(int $conversationId, int $senderId, int $receiverId): void
    {
        try {
            $sender = User::find($senderId);
            $receiver = User::find($receiverId);
            if (! $sender || ! $receiver || ! self::canChatWith($receiver) || ! $receiver->wantsPush()) {
                return;
            }
            $block = ChatBlock::stateBetween($sender, $receiver);
            if ($block['blocked_by_me'] || $block['blocked_me']) {
                return;
            }
            $receiver->notify(new ChatPushAlert($conversationId, $sender->name));
        } catch (Throwable $e) {
            Log::warning("Chat push to user {$receiverId} not sent: ".$e->getMessage());
        }
    }

    /** Approved, non-demo accounts can be written to (MASTER_SPEC §35). */
    public static function canChatWith(User $user): bool
    {
        return $user->isApproved() && ! $user->isDemo();
    }

    /** All unread messages of $user over every conversation, in one query. */
    public static function unreadTotal(User $user): int
    {
        return ChatMessage::query()
            ->join('conversations as c', 'c.id', '=', 'chat_messages.conversation_id')
            ->where('chat_messages.sender_id', '!=', $user->id)
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('c.user_one_id', $user->id)->whereRaw('chat_messages.id > coalesce(c.user_one_last_read_id, 0)'))
                ->orWhere(fn ($w) => $w->where('c.user_two_id', $user->id)->whereRaw('chat_messages.id > coalesce(c.user_two_last_read_id, 0)')))
            ->count();
    }

    /** The conversation, if $request->user() takes part in it (others: 403 — admins included). */
    private function find(Request $request, int $id): Conversation
    {
        $c = Conversation::findOrFail($id);
        abort_unless($c->hasParticipant($request->user()), 403, __('messages.chat_not_participant'));

        return $c;
    }

    /**
     * Ids of members I blocked and of members who blocked me (Phase 9d-1).
     *
     * @return array{by_me: list<int>, me: list<int>}
     */
    private static function blockSets(User $me): array
    {
        return [
            'by_me' => ChatBlock::where('blocker_id', $me->id)->pluck('blocked_id')->all(),
            'me' => ChatBlock::where('blocked_id', $me->id)->pluck('blocker_id')->all(),
        ];
    }

    /**
     * @param  array{by_me: list<int>, me: list<int>}|null  $blocks
     * @return array<string, mixed>
     */
    private function conversation(Conversation $c, User $me, ?array $blocks = null): array
    {
        $partner = $c->partnerOf($me);
        $last = $c->lastMessage;
        $blocks ??= self::blockSets($me);
        $partnerId = $partner->id ?? ($c->user_one_id === $me->id ? $c->user_two_id : $c->user_one_id);
        $blockedByMe = in_array($partnerId, $blocks['by_me'], true);
        $blockedMe = in_array($partnerId, $blocks['me'], true);
        // 9d-2: suspended / pending / rejected (removed) / demo / missing partner → "Unavailable" on the screens.
        $available = $partner !== null && self::canChatWith($partner);

        return [
            'id' => $c->id,
            'partner' => [
                'id' => $partnerId,
                'name' => $partner?->name, // null = account gone → the screens show "Former member"
                'photo_url' => $partner?->photo_path ? Storage::disk('public')->url($partner->photo_path) : null,
            ],
            'partner_available' => $available,
            'can_send' => $available && ! $blockedByMe && ! $blockedMe,
            'blocked_by_me' => $blockedByMe,
            'blocked_me' => $blockedMe,
            'last_message' => $last ? [
                'id' => $last->id,
                'body' => $last->body,
                'mine' => $last->sender_id === $me->id,
                'created_at' => $last->created_at?->toIso8601String(),
            ] : null,
            'unread_count' => $c->unreadCountFor($me),
            'partner_last_read_id' => $c->partnerLastReadId($me),
            'last_message_at' => $c->last_message_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private static function message(ChatMessage $m, User $me, int $partnerRead, bool $reported = false): array
    {
        $mine = $m->sender_id === $me->id;

        return [
            'id' => $m->id,
            'body' => $m->body,
            'mine' => $mine,
            // Only for my own messages: has the other person read it?
            'read' => $mine ? $m->id <= $partnerRead : null,
            // Only for the partner's messages: have I reported it already?
            'reported' => $mine ? null : $reported,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
