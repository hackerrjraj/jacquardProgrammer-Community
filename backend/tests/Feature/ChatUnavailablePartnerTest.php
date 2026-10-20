<?php

namespace Tests\Feature;

use App\Events\ChatMessageSent;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\User;
use Database\Seeders\DemoProgrammersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** Suspended / pending / removed / demo / missing chat partners, and a suspended current user (Phase 9d-2). */
class ChatUnavailablePartnerTest extends TestCase
{
    use RefreshDatabase;

    private const EN = ['Accept' => 'application/json', 'Accept-Language' => 'en'];

    private const NOT_AVAILABLE = 'This member is not available for messages right now.';

    private User $rahim;

    private User $karim;

    private int $conversation;

    private int $fromKarim;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rahim = User::factory()->approved()->create(['name' => 'Rahim']);
        $this->karim = User::factory()->approved()->create(['name' => 'Karim']);
        $this->conversation = Conversation::between($this->rahim, $this->karim)->id;
        $this->send($this->rahim, 'Hello Karim')->assertCreated();
        $this->fromKarim = $this->send($this->karim, 'Hello Rahim')->assertCreated()->json('data.id');
    }

    private function send(User $me, string $body)
    {
        return $this->actingAs($me)->postJson("/api/chat/conversations/{$this->conversation}/messages", ['body' => $body], self::EN);
    }

    /** @return array<string, array<string, mixed>> */
    private static function unavailableStates(): array
    {
        return [
            'suspended' => ['status' => User::STATUS_SUSPENDED],
            'pending' => ['status' => User::STATUS_PENDING],
            'removed (rejected)' => ['status' => User::STATUS_REJECTED],
            'demo' => ['status' => User::STATUS_APPROVED, 'email' => 'karim@'.DemoProgrammersSeeder::EMAIL_DOMAIN],
        ];
    }

    public function test_sending_to_an_unavailable_partner_is_refused_with_403_and_history_stays(): void
    {
        $this->actingAs($this->rahim)->getJson("/api/chat/conversations/{$this->conversation}")
            ->assertJsonPath('data.partner_available', true)->assertJsonPath('data.can_send', true);
        $original = $this->karim->only(['status', 'email']);

        foreach (self::unavailableStates() as $label => $state) {
            $this->karim->forceFill($state)->save();
            Event::fake([ChatMessageSent::class]);

            $this->actingAs($this->rahim)->getJson("/api/chat/conversations/{$this->conversation}")->assertOk()
                ->assertJsonPath('data.partner_available', false)
                ->assertJsonPath('data.can_send', false)
                ->assertJsonPath('data.partner.name', 'Karim');
            $this->actingAs($this->rahim)->getJson("/api/chat/conversations/{$this->conversation}/messages")
                ->assertOk()->assertJsonCount(2, 'data');
            $this->send($this->rahim, "still there? ({$label})")->assertForbidden()->assertJsonPath('message', self::NOT_AVAILABLE);

            Event::assertNotDispatched(ChatMessageSent::class);
            $this->assertSame(2, ChatMessage::count(), $label);
            $this->karim->forceFill($original)->save();
        }

        // Bengali message too.
        $this->karim->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $this->actingAs($this->rahim)
            ->postJson("/api/chat/conversations/{$this->conversation}/messages", ['body' => 'hi'], ['Accept' => 'application/json', 'Accept-Language' => 'bn'])
            ->assertForbidden()->assertJsonPath('message', 'এই সদস্য এখন বার্তার জন্য উপলব্ধ নন।');
    }

    public function test_chat_list_still_shows_the_conversation_with_an_unavailable_flag(): void
    {
        $this->karim->forceFill(['status' => User::STATUS_REJECTED])->save();

        $this->actingAs($this->rahim)->getJson('/api/chat/conversations')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.partner.name', 'Karim')
            ->assertJsonPath('data.0.partner_available', false)
            ->assertJsonPath('data.0.can_send', false)
            ->assertJsonPath('data.0.last_message.body', 'Hello Rahim');
    }

    public function test_a_missing_partner_account_does_not_break_the_chat(): void
    {
        // Simulate a partner row that no longer exists (the database normally prevents deleting it).
        DB::statement('PRAGMA defer_foreign_keys = ON'); // checked only at commit — the test transaction is rolled back
        $column = $this->rahim->id < $this->karim->id ? 'user_two_id' : 'user_one_id';
        DB::table('conversations')->where('id', $this->conversation)->update([$column => 999999]);

        $this->actingAs($this->rahim)->getJson('/api/chat/conversations')->assertOk()
            ->assertJsonPath('data.0.partner.id', 999999)
            ->assertJsonPath('data.0.partner.name', null)
            ->assertJsonPath('data.0.partner.photo_url', null)
            ->assertJsonPath('data.0.partner_available', false)
            ->assertJsonPath('data.0.can_send', false);
        $this->actingAs($this->rahim)->getJson("/api/chat/conversations/{$this->conversation}")->assertOk()
            ->assertJsonPath('data.partner.name', null);
        $this->actingAs($this->rahim)->getJson("/api/chat/conversations/{$this->conversation}/messages")
            ->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($this->rahim)->postJson("/api/chat/conversations/{$this->conversation}/read")->assertOk();
        $this->send($this->rahim, 'anyone?')->assertForbidden()->assertJsonPath('message', self::NOT_AVAILABLE);
        $this->assertSame(2, ChatMessage::count());
    }

    public function test_report_and_block_still_work_when_the_partner_is_unavailable(): void
    {
        $this->karim->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $this->actingAs($this->rahim)->postJson("/api/chat/messages/{$this->fromKarim}/report", ['reason' => 'spam'], self::EN)
            ->assertCreated();
        $this->actingAs($this->rahim)->getJson("/api/chat/conversations/{$this->conversation}/messages")
            ->assertJsonPath('data.1.reported', true);

        $this->actingAs($this->rahim)->postJson('/api/chat/blocks', ['user_id' => $this->karim->id], self::EN)
            ->assertCreated()->assertJsonPath('data.user.name', 'Karim');
        $this->actingAs($this->rahim)->getJson('/api/chat/blocks')->assertJsonPath('data.0.user.id', $this->karim->id);
        $this->actingAs($this->rahim)->getJson("/api/chat/conversations/{$this->conversation}")
            ->assertJsonPath('data.blocked_by_me', true)->assertJsonPath('data.partner_available', false);
        $this->actingAs($this->rahim)->deleteJson("/api/chat/blocks/{$this->karim->id}", [], self::EN)->assertOk();

        // A removed (rejected) partner's message can be reported too.
        $this->karim->forceFill(['status' => User::STATUS_REJECTED])->save();
        $other = User::factory()->approved()->create();
        $c2 = Conversation::between($other, $this->karim);
        $m2 = $c2->messages()->create(['sender_id' => $this->karim->id, 'body' => 'Send me money']);
        $c2->forceFill(['last_message_at' => now()])->save();
        $this->actingAs($other)->postJson("/api/chat/messages/{$m2->id}/report", ['reason' => 'fraud_or_money_request'], self::EN)
            ->assertCreated();
    }

    public function test_a_suspended_member_cannot_open_or_send_chats(): void
    {
        $this->rahim->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $this->actingAs($this->rahim)->getJson('/api/chat/conversations', self::EN)
            ->assertForbidden()->assertJsonPath('status', User::STATUS_SUSPENDED);
        $this->actingAs($this->rahim)->getJson("/api/chat/conversations/{$this->conversation}/messages", self::EN)
            ->assertForbidden()->assertJsonPath('status', User::STATUS_SUSPENDED);
        $this->send($this->rahim, 'hello?')->assertForbidden()->assertJsonPath('status', User::STATUS_SUSPENDED);
        $this->actingAs($this->rahim)->postJson('/api/chat/conversations', ['user_id' => $this->karim->id], self::EN)
            ->assertForbidden();
        $this->assertSame(2, ChatMessage::count());

        // Karim sees the chat as unavailable.
        $this->actingAs($this->karim)->getJson("/api/chat/conversations/{$this->conversation}")
            ->assertJsonPath('data.partner_available', false)->assertJsonPath('data.can_send', false);
    }
}
