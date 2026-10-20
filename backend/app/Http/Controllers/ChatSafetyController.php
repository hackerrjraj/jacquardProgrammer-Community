<?php

namespace App\Http\Controllers;

use App\Models\ChatBlock;
use App\Models\ChatMessage;
use App\Models\ChatReport;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Chat block + report for members (Phase 9d-1, MASTER_SPEC §35). Blocking stops messages both ways and keeps old
 * messages. A report is about one message the member RECEIVED; only a text copy of that message reaches the admins.
 * Reporting does not block.
 */
class ChatSafetyController extends Controller
{
    /** GET /api/chat/blocks — the members I blocked, newest first. */
    public function blocks(Request $request): JsonResponse
    {
        $rows = ChatBlock::query()
            ->where('blocker_id', $request->user()->id)
            ->with('blocked')
            ->latest('id')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (ChatBlock $b) => [
                'user' => self::person($b->blocked),
                'blocked_at' => $b->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /** POST /api/chat/blocks {user_id} — blocking twice is fine (200). */
    public function block(Request $request): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'min:1']]);
        $me = $request->user();

        if ((int) $data['user_id'] === $me->id) {
            return response()->json(['message' => __('messages.chat_block_self')], 422);
        }
        $other = User::find($data['user_id']);
        if (! $other) {
            return response()->json(['message' => __('messages.chat_block_unknown')], 422);
        }

        $block = ChatBlock::firstOrCreate(['blocker_id' => $me->id, 'blocked_id' => $other->id]);

        return response()->json(
            ['message' => __('messages.chat_blocked'), 'data' => ['user' => self::person($other), 'blocked_at' => $block->created_at?->toIso8601String()]],
            $block->wasRecentlyCreated ? 201 : 200,
        );
    }

    /** DELETE /api/chat/blocks/{user} — unblocking someone who is not blocked is fine too. */
    public function unblock(Request $request, int $user): JsonResponse
    {
        ChatBlock::where('blocker_id', $request->user()->id)->where('blocked_id', $user)->delete();

        return response()->json(['message' => __('messages.chat_unblocked')]);
    }

    /** POST /api/chat/messages/{message}/report {reason, note?} — at most 10 reports in 24 hours per member. */
    public function report(Request $request, int $message): JsonResponse
    {
        $me = $request->user();
        $m = ChatMessage::with('conversation')->findOrFail($message);

        // Only my own conversations; only messages I received.
        abort_unless($m->conversation->hasParticipant($me), 403, __('messages.chat_not_participant'));
        if ($m->sender_id === $me->id) {
            return response()->json(['message' => __('messages.chat_report_own')], 422);
        }

        $request->merge(['note' => is_string($request->input('note')) ? trim($request->input('note')) : $request->input('note')]);
        $data = $request->validate([
            'reason' => ['required', Rule::in(ChatReport::REASONS)],
            'note' => ['nullable', 'string', 'max:'.ChatReport::NOTE_MAX],
        ]);

        if (ChatReport::where('reporter_id', $me->id)->where('message_id', $m->id)->exists()) {
            return response()->json(['message' => __('messages.chat_report_duplicate')], 422);
        }
        $recent = ChatReport::where('reporter_id', $me->id)->where('created_at', '>=', now()->subDay())->count();
        if ($recent >= ChatReport::DAILY_LIMIT) {
            return response()->json(['message' => __('messages.chat_report_limit')], 429);
        }

        try {
            $report = ChatReport::create([
                'reporter_id' => $me->id,
                'reported_user_id' => $m->sender_id,
                'message_id' => $m->id,
                'conversation_id' => $m->conversation_id,
                'message_snapshot' => $m->body,
                'reason' => $data['reason'],
                'note' => ($data['note'] ?? '') ?: null,
            ]);
        } catch (UniqueConstraintViolationException) { // the same report sent twice at the same moment
            return response()->json(['message' => __('messages.chat_report_duplicate')], 422);
        }

        return response()->json(['message' => __('messages.chat_reported'), 'data' => ['id' => $report->id, 'message_id' => $m->id]], 201);
    }

    /** @return array{id: int, name: string, photo_url: string|null} */
    private static function person(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'photo_url' => $u->photo_path ? Storage::disk('public')->url($u->photo_path) : null,
        ];
    }
}
