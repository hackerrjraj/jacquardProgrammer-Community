<?php

namespace Tests\Feature;

use App\Events\ChatMessageSent;
use App\Models\AuditLog;
use App\Models\ChatBlock;
use App\Models\ChatReport;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** Chat block + report (Phase 9d-1). */
class ChatSafetyTest extends TestCase
{
    use RefreshDatabase;

    private const EN = ['Accept' => 'application/json', 'Accept-Language' => 'en'];

    private User $rahim;

    private User $karim;

    private int $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rahim = User::factory()->approved()->create(['name' => 'Rahim']);
        $this->karim = User::factory()->approved()->create(['name' => 'Karim']);
        $this->conversation = Conversation::between($this->rahim, $this->karim)->id;
    }

    private function send(User $me, string $body)
    {
        return $this->actingAs($me)->postJson("/api/chat/conversations/{$this->conversation}/messages", ['body' => $body], self::EN);
    }

    private function report(User $me, int $message, array $data = ['reason' => 'spam'])
    {
        return $this->actingAs($me)->postJson("/api/chat/messages/{$message}/report", $data, self::EN);
    }

    public function test_block_stops_messages_both_ways_and_keeps_history(): void
    {
        $this->send($this->rahim, 'Hello')->assertCreated();

        $this->actingAs($this->rahim)->postJson('/api/chat/blocks', ['user_id' => $this->karim->id], self::EN)
            ->assertCreated()->assertJsonPath('data.user.name', 'Karim');
        $this->actingAs($this->rahim)->postJson('/api/chat/blocks', ['user_id' => $this->karim->id], self::EN)->assertOk();
        $this->assertSame(1, ChatBlock::count());

        Event::fake([ChatMessageSent::class]);
        $this->send($this->rahim, 'From blocker')->assertForbidden()
            ->assertJsonPath('message', 'You blocked this member. Unblock them to send messages.');
        $this->send($this->karim, 'From blocked')->assertForbidden()
            ->assertJsonPath('message', "You can't reply to this conversation.");
        Event::assertNotDispatched(ChatMessageSent::class); // nothing goes out live either

        // Both still see the old message; the flags let each screen react.
        $this->actingAs($this->karim)->getJson("/api/chat/conversations/{$this->conversation}/messages")
            ->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->rahim)->getJson("/api/chat/conversations/{$this->conversation}")
            ->assertJsonPath('data.blocked_by_me', true)->assertJsonPath('data.blocked_me', false)->assertJsonPath('data.can_send', false);
        $this->actingAs($this->karim)->getJson('/api/chat/conversations')
            ->assertJsonPath('data.0.blocked_by_me', false)->assertJsonPath('data.0.blocked_me', true)->assertJsonPath('data.0.can_send', false);

        $this->actingAs($this->rahim)->getJson('/api/chat/blocks')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.user.id', $this->karim->id);
        $this->actingAs($this->karim)->getJson('/api/chat/blocks')->assertJsonCount(0, 'data');

        $this->actingAs($this->rahim)->postJson('/api/chat/blocks', ['user_id' => $this->rahim->id], self::EN)
            ->assertStatus(422)->assertJsonPath('message', 'You cannot block yourself.');
        $this->actingAs($this->rahim)->postJson('/api/chat/blocks', ['user_id' => 99999], self::EN)->assertStatus(422);
    }

    public function test_unblock_lets_both_send_again(): void
    {
        ChatBlock::create(['blocker_id' => $this->karim->id, 'blocked_id' => $this->rahim->id]);
        $this->send($this->rahim, 'Hi')->assertForbidden();

        // The blocked side cannot lift someone else's block.
        $this->actingAs($this->rahim)->deleteJson("/api/chat/blocks/{$this->karim->id}")->assertOk();
        $this->assertSame(1, ChatBlock::count());

        $this->actingAs($this->karim)->deleteJson("/api/chat/blocks/{$this->rahim->id}", [], self::EN)
            ->assertOk()->assertJsonPath('message', 'Member unblocked.');
        $this->assertSame(0, ChatBlock::count());
        $this->send($this->rahim, 'Hi')->assertCreated();
        $this->send($this->karim, 'Hello')->assertCreated();
        $this->actingAs($this->rahim)->getJson("/api/chat/conversations/{$this->conversation}")
            ->assertJsonPath('data.can_send', true)->assertJsonPath('data.blocked_me', false);
    }

    public function test_report_only_received_messages_in_own_conversation_once(): void
    {
        $mine = $this->send($this->rahim, 'My own')->json('data.id');
        $theirs = $this->send($this->karim, 'Send me money')->json('data.id');
        $other = User::factory()->approved()->create();

        $this->report($this->rahim, $mine)->assertStatus(422)->assertJsonPath('message', 'You can only report messages you received.');
        $this->report($other, $theirs)->assertForbidden();
        $this->report($this->rahim, 99999)->assertNotFound();
        $this->report($this->rahim, $theirs, ['reason' => 'bad'])->assertJsonValidationErrors('reason');
        $this->report($this->rahim, $theirs, ['reason' => 'other', 'note' => str_repeat('a', 501)])->assertJsonValidationErrors('note');

        $this->report($this->rahim, $theirs, ['reason' => 'fraud_or_money_request', 'note' => '  asked for bKash  '])->assertCreated();
        $this->report($this->rahim, $theirs)->assertStatus(422)->assertJsonPath('message', 'You have already reported this message.');

        $r = ChatReport::sole();
        $this->assertSame([$this->rahim->id, $this->karim->id, $theirs, 'Send me money', 'asked for bKash', 'open'],
            [$r->reporter_id, $r->reported_user_id, $r->message_id, $r->message_snapshot, $r->note, $r->status]);
        $this->assertSame(0, ChatBlock::count()); // reporting does not block

        // The message list tells the reporter which messages they already reported.
        $this->actingAs($this->rahim)->getJson("/api/chat/conversations/{$this->conversation}/messages")
            ->assertJsonPath('data.0.reported', null)->assertJsonPath('data.1.reported', true);
    }

    public function test_report_limit_is_ten_per_day(): void
    {
        $ids = [];
        for ($i = 1; $i <= 11; $i++) {
            $ids[] = $this->send($this->karim, "Spam {$i}")->json('data.id');
        }
        foreach (array_slice($ids, 0, 10) as $id) {
            $this->report($this->rahim, $id)->assertCreated();
        }
        $this->report($this->rahim, $ids[10])->assertStatus(429)
            ->assertJsonPath('message', 'You can send at most 10 reports in 24 hours. Please try again later.');

        $this->travel(25)->hours();
        $this->report($this->rahim, $ids[10])->assertCreated();
    }

    public function test_admin_sees_only_the_snapshot_and_can_review_or_dismiss(): void
    {
        $this->send($this->rahim, 'Private hello');
        $bad = $this->send($this->karim, 'Abusive text')->json('data.id');
        $this->send($this->karim, 'Another private line');
        $this->report($this->rahim, $bad, ['reason' => 'harassment', 'note' => 'Please check'])->assertCreated();
        $id = ChatReport::sole()->id;
        $admin = User::factory()->approved()->create(['role' => User::ROLE_ADMIN]);
        $super = User::factory()->approved()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $list = $this->actingAs($admin)->getJson('/api/admin/chat-reports?status=open')->assertOk()
            ->assertJsonPath('meta.open_count', 1)->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.message_snapshot', 'Abusive text')
            ->assertJsonPath('data.0.reported_user.id', $this->karim->id)
            ->assertJsonPath('data.0.reporter.id', $this->rahim->id);
        $this->assertStringNotContainsString('private', strtolower($list->getContent()));
        $this->assertArrayNotHasKey('conversation_id', $list->json('data.0'));

        $detail = $this->actingAs($super)->getJson("/api/admin/chat-reports/{$id}")->assertOk()
            ->assertJsonPath('data.reason', 'harassment')->assertJsonPath('data.note', 'Please check');
        $this->assertStringNotContainsString('private', strtolower($detail->getContent()));
        // The chat itself stays closed to admins.
        $this->actingAs($admin)->getJson("/api/chat/conversations/{$this->conversation}/messages")->assertForbidden();

        $this->actingAs($admin)->postJson("/api/admin/chat-reports/{$id}/review", [], self::EN)->assertOk()
            ->assertJsonPath('data.status', 'reviewed')->assertJsonPath('data.reviewed_by.id', $admin->id)
            ->assertJsonPath('meta.open_count', 0);
        $this->actingAs($super)->postJson("/api/admin/chat-reports/{$id}/dismiss", [], self::EN)
            ->assertStatus(422)->assertJsonPath('message', 'This report has already been handled.');
        $this->assertSame(1, AuditLog::where('action', 'chat_report.reviewed')->count());

        $this->actingAs($admin)->getJson('/api/admin/chat-reports?status=open')->assertJsonCount(0, 'data');
        $this->actingAs($admin)->getJson('/api/admin/chat-reports?status=reviewed')->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson('/api/admin/chat-reports?status=bad')->assertJsonValidationErrors('status');
    }

    public function test_non_admins_cannot_use_admin_report_endpoints(): void
    {
        $bad = $this->send($this->karim, 'Spam')->json('data.id');
        $this->report($this->rahim, $bad)->assertCreated();
        $id = ChatReport::sole()->id;
        $committee = User::factory()->approved()->create(['role' => User::ROLE_COMMITTEE]);

        foreach ([$this->rahim, $this->karim, $committee] as $user) {
            $this->actingAs($user)->getJson('/api/admin/chat-reports')->assertForbidden();
            $this->actingAs($user)->getJson("/api/admin/chat-reports/{$id}")->assertForbidden();
            $this->actingAs($user)->postJson("/api/admin/chat-reports/{$id}/review")->assertForbidden();
            $this->actingAs($user)->postJson("/api/admin/chat-reports/{$id}/dismiss")->assertForbidden();
        }
        $this->assertSame('open', ChatReport::sole()->status);
    }

    public function test_guests_and_unapproved_cannot_block_or_report(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/chat/blocks')->assertUnauthorized();
        $this->postJson('/api/chat/blocks', ['user_id' => $this->karim->id])->assertUnauthorized();
        $this->getJson('/api/admin/chat-reports')->assertUnauthorized();

        $pending = User::factory()->create();
        $this->actingAs($pending)->postJson('/api/chat/blocks', ['user_id' => $this->karim->id])->assertForbidden();
        $this->actingAs($pending)->postJson('/api/chat/messages/1/report', ['reason' => 'spam'])->assertForbidden();
    }
}
