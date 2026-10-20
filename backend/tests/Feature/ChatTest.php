<?php

namespace Tests\Feature;

use App\Events\ChatMessageSent;
use App\Events\ChatMessagesRead;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\User;
use Database\Seeders\DemoProgrammersSeeder;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** 1-to-1 chat API + private channel rules (MASTER_SPEC §35, Phase 9a). */
class ChatTest extends TestCase
{
    use RefreshDatabase;

    private const EN = ['Accept' => 'application/json', 'Accept-Language' => 'en'];

    private User $rahim;

    private User $karim;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rahim = User::factory()->approved()->create(['name' => 'Rahim']);
        $this->karim = User::factory()->approved()->create(['name' => 'Karim']);
    }

    private function open(User $me, User $other): int
    {
        return $this->actingAs($me)->postJson('/api/chat/conversations', ['user_id' => $other->id])->json('data.id');
    }

    private function send(User $me, int $conversation, string $body): array
    {
        return $this->actingAs($me)->postJson("/api/chat/conversations/{$conversation}/messages", ['body' => $body], self::EN)
            ->assertCreated()->json('data');
    }

    public function test_open_conversation_once_per_pair_and_refuse_self_demo_and_unapproved(): void
    {
        $this->actingAs($this->rahim)->postJson('/api/chat/conversations', ['user_id' => $this->karim->id])
            ->assertCreated()
            ->assertJsonPath('data.partner.name', 'Karim')
            ->assertJsonPath('data.can_send', true)
            ->assertJsonPath('data.last_message', null)
            ->assertJsonPath('data.unread_count', 0);
        // Same pair, either direction → the same conversation (200, not a new one).
        $id = Conversation::sole()->id;
        $this->actingAs($this->karim)->postJson('/api/chat/conversations', ['user_id' => $this->rahim->id])
            ->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSame(1, Conversation::count());

        $this->actingAs($this->rahim)->postJson('/api/chat/conversations', ['user_id' => $this->rahim->id], self::EN)
            ->assertStatus(422)->assertJsonPath('message', 'You cannot start a chat with yourself.');
        foreach ([
            User::factory()->create(), // pending registration
            User::factory()->suspended()->create(),
            User::factory()->approved()->create(['email' => 'x@'.DemoProgrammersSeeder::EMAIL_DOMAIN]),
        ] as $other) {
            $this->actingAs($this->rahim)->postJson('/api/chat/conversations', ['user_id' => $other->id], self::EN)
                ->assertStatus(422)->assertJsonPath('message', 'This member cannot receive messages right now.');
        }
        $this->actingAs($this->rahim)->postJson('/api/chat/conversations', ['user_id' => 99999], self::EN)->assertStatus(422);
        $this->actingAs($this->rahim)->postJson('/api/chat/conversations', [], self::EN)->assertJsonValidationErrors('user_id');
    }

    public function test_send_read_unread_counts_and_read_ticks(): void
    {
        $id = $this->open($this->rahim, $this->karim);

        $first = $this->send($this->rahim, $id, '  Assalamu alaikum  ');
        $this->assertSame('Assalamu alaikum', $first['body']); // trimmed
        $this->assertTrue($first['mine']);
        $this->assertFalse($first['read']);
        $this->send($this->rahim, $id, 'Are you free today?');

        // Karim: 2 unread in the list, the menu total and the conversation.
        $this->actingAs($this->karim)->getJson('/api/chat/conversations')
            ->assertOk()
            ->assertJsonPath('meta.unread_total', 2)
            ->assertJsonPath('data.0.partner.name', 'Rahim')
            ->assertJsonPath('data.0.unread_count', 2)
            ->assertJsonPath('data.0.last_message.body', 'Are you free today?')
            ->assertJsonPath('data.0.last_message.mine', false);
        $this->actingAs($this->karim)->getJson('/api/chat/unread')->assertJsonPath('data.unread_total', 2);
        // Rahim's own messages do not count as unread for him.
        $this->actingAs($this->rahim)->getJson('/api/chat/unread')->assertJsonPath('data.unread_total', 0);

        // Karim reads → 0 unread; Rahim now sees both messages as read.
        $this->actingAs($this->karim)->postJson("/api/chat/conversations/{$id}/read")
            ->assertOk()->assertJsonPath('data.unread_total', 0);
        $this->actingAs($this->rahim)->getJson("/api/chat/conversations/{$id}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.read', true)
            ->assertJsonPath('data.1.read', true)
            ->assertJsonPath('meta.has_more', false);

        // Karim replies → newest activity first; his message is "mine" for him, not yet read by Rahim.
        $reply = $this->send($this->karim, $id, 'Yes, after 5 pm');
        $this->assertFalse($reply['read']);
        $this->actingAs($this->karim)->getJson("/api/chat/conversations/{$id}/messages")
            ->assertJsonPath('data.0.mine', false)
            ->assertJsonPath('data.0.read', null)
            ->assertJsonPath('data.2.mine', true);
        $this->actingAs($this->rahim)->getJson('/api/chat/unread')->assertJsonPath('data.unread_total', 1);
    }

    public function test_messages_are_paged_oldest_first_with_before(): void
    {
        $id = $this->open($this->rahim, $this->karim);
        $c = Conversation::find($id);
        foreach (range(1, 35) as $n) {
            $c->messages()->create(['sender_id' => $this->rahim->id, 'body' => "Message {$n}"]);
        }

        $page = $this->actingAs($this->karim)->getJson("/api/chat/conversations/{$id}/messages")
            ->assertJsonCount(30, 'data')
            ->assertJsonPath('data.0.body', 'Message 6')
            ->assertJsonPath('data.29.body', 'Message 35')
            ->assertJsonPath('meta.has_more', true);
        $oldest = $page->json('data.0.id');
        $this->actingAs($this->karim)->getJson("/api/chat/conversations/{$id}/messages?before={$oldest}")
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.body', 'Message 1')
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_only_participants_can_read_or_write_not_even_admins(): void
    {
        $id = $this->open($this->rahim, $this->karim);
        $this->send($this->rahim, $id, 'private');

        foreach ([User::factory()->approved()->create(), User::factory()->admin()->create(), User::factory()->superAdmin()->create()] as $outsider) {
            $this->actingAs($outsider)->getJson("/api/chat/conversations/{$id}", self::EN)
                ->assertForbidden()->assertJsonPath('message', 'This conversation is not yours.');
            $this->actingAs($outsider)->getJson("/api/chat/conversations/{$id}/messages", self::EN)->assertForbidden();
            $this->actingAs($outsider)->postJson("/api/chat/conversations/{$id}/messages", ['body' => 'hi'], self::EN)->assertForbidden();
            $this->actingAs($outsider)->postJson("/api/chat/conversations/{$id}/read", [], self::EN)->assertForbidden();
            $this->actingAs($outsider)->getJson('/api/chat/conversations')->assertJsonCount(0, 'data');
        }
        $this->actingAs($this->rahim)->getJson('/api/chat/conversations/9999', self::EN)->assertNotFound();

        // Guests and not-approved accounts are kept out of the whole chat API.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/chat/conversations', self::EN)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/chat/conversations', self::EN)->assertForbidden();
    }

    public function test_message_rules_partner_no_longer_approved_and_rate_limit(): void
    {
        $id = $this->open($this->rahim, $this->karim);
        $url = "/api/chat/conversations/{$id}/messages";

        $this->actingAs($this->rahim)->postJson($url, ['body' => '   '], self::EN)->assertJsonValidationErrors('body');
        $this->actingAs($this->rahim)->postJson($url, ['body' => str_repeat('a', ChatMessage::MAX_LENGTH + 1)], self::EN)
            ->assertJsonValidationErrors('body');
        $this->send($this->rahim, $id, str_repeat('a', ChatMessage::MAX_LENGTH));

        // Karim is suspended: history stays readable for Rahim, but no new messages.
        $this->karim->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $this->actingAs($this->rahim)->getJson("/api/chat/conversations/{$id}")->assertJsonPath('data.can_send', false);
        $this->actingAs($this->rahim)->getJson($url)->assertJsonCount(1, 'data');
        $this->actingAs($this->rahim)->postJson($url, ['body' => 'hello?'], self::EN)
            ->assertStatus(403)->assertJsonPath('message', 'This member is not available for messages right now.');
        $this->karim->forceFill(['status' => User::STATUS_APPROVED])->save();

        // 30 tries per minute per member (the 4 above count too), then a bn/en 429.
        foreach (range(1, 26) as $n) {
            $this->actingAs($this->rahim)->postJson($url, ['body' => "m{$n}"])->assertCreated();
        }
        $this->actingAs($this->rahim)->postJson($url, ['body' => 'one too many'], self::EN)
            ->assertStatus(429)->assertJsonPath('message', 'You are sending messages too fast. Please wait a minute.');
    }

    public function test_private_channel_authorisation(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1',
        ]);
        // Tests boot with the "null" broadcaster: register the channel rules on the reverb one too.
        require base_path('routes/channels.php');
        $id = $this->open($this->rahim, $this->karim);
        $auth = fn (User $user, string $channel) => $this->actingAs($user)
            ->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-{$channel}"]);

        $auth($this->rahim, "chat.conversation.{$id}")->assertOk()->assertJsonStructure(['auth']);
        $auth($this->karim, "chat.conversation.{$id}")->assertOk();
        $auth(User::factory()->admin()->create(), "chat.conversation.{$id}")->assertForbidden();
        $auth($this->rahim, 'chat.conversation.9999')->assertForbidden();
        $auth($this->rahim, "App.Models.User.{$this->rahim->id}")->assertOk();
        $auth($this->rahim, "App.Models.User.{$this->karim->id}")->assertForbidden();

        // Suspended participant: refused.
        $this->karim->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $auth($this->karim, "chat.conversation.{$id}")->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-chat.conversation.{$id}"])
            ->assertUnauthorized();
    }

    public function test_live_events_for_new_message_and_read(): void
    {
        $id = $this->open($this->rahim, $this->karim);
        Event::fake([ChatMessageSent::class, ChatMessagesRead::class]);

        $msg = $this->send($this->rahim, $id, 'হ্যালো');
        Event::assertDispatched(ChatMessageSent::class, function (ChatMessageSent $e) use ($id, $msg) {
            $channels = array_map(fn ($c) => $c->name, $e->broadcastOn());

            return $e->broadcastAs() === 'chat.message'
                && $channels === ["private-chat.conversation.{$id}", 'private-App.Models.User.'.min($this->rahim->id, $this->karim->id), 'private-App.Models.User.'.max($this->rahim->id, $this->karim->id)]
                && $e->broadcastWith() === ['conversation_id' => $id, 'message' => ['id' => $msg['id'], 'sender_id' => $this->rahim->id, 'body' => 'হ্যালো', 'created_at' => $msg['created_at']]];
        });
        // The sender's own message does not send a "read" event.
        Event::assertNotDispatched(ChatMessagesRead::class);

        $this->actingAs($this->karim)->postJson("/api/chat/conversations/{$id}/read")->assertOk();
        Event::assertDispatched(ChatMessagesRead::class, fn (ChatMessagesRead $e) => $e->broadcastAs() === 'chat.read'
            && $e->broadcastWith() === ['conversation_id' => $id, 'reader_id' => $this->karim->id, 'last_read_id' => $msg['id']]
            && count($e->broadcastOn()) === 3);

        // Reading again with nothing new: no second event.
        $this->actingAs($this->karim)->postJson("/api/chat/conversations/{$id}/read")->assertOk();
        Event::assertDispatchedTimes(ChatMessagesRead::class, 1);
    }

    public function test_chat_works_when_the_chat_server_is_down(): void
    {
        Broadcast::extend('down', fn () => new class implements Broadcaster
        {
            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            public function broadcast(array $channels, $event, array $payload = []): void
            {
                throw new BroadcastException('Connection refused');
            }
        });
        config(['broadcasting.connections.down' => ['driver' => 'down'], 'broadcasting.default' => 'down']);

        $id = $this->open($this->rahim, $this->karim);
        $this->send($this->rahim, $id, 'still works');
        $this->actingAs($this->karim)->postJson("/api/chat/conversations/{$id}/read")->assertOk()->assertJsonPath('data.unread_total', 0);
        $this->assertSame(1, ChatMessage::count());

        // After a failure, live sends pause for a few seconds (no second wait for the dead server).
        $this->assertTrue(Cache::has('chat_live_down'));
        Event::fake([ChatMessageSent::class]);
        $this->send($this->karim, $id, 'second');
        Event::assertNotDispatched(ChatMessageSent::class);
    }
}
