<?php

namespace Tests\Feature;

use App\Events\ChatMessageSent;
use App\Models\ChatMessage;
use App\Models\User;
use App\Notifications\ChatPushAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Minishlink\WebPush\WebPush;
use Mockery;
use NotificationChannels\WebPush\ReportHandler;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\TestCase;

/** Phone / desktop push for new chat messages (Phase 10c-3, MASTER_SPEC §35–§36). */
class ChatPushTest extends TestCase
{
    use RefreshDatabase;

    private const EN = ['Accept' => 'application/json', 'Accept-Language' => 'en'];

    private const SECRET = 'my secret bank pin is 4321';

    private User $rahim;

    private User $karim;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([ChatMessageSent::class]);
        $this->rahim = User::factory()->approved()->create(['name' => 'Rahim']);
        $this->karim = User::factory()->approved()->create(['name' => 'Karim']);
    }

    private function pushOn(User $user, string $locale = 'bn'): void
    {
        $user->updatePushSubscription('https://push.example/'.$user->id, 'BPublicKeyValue', 'AuthSecret');
        $user->forceFill(['push_enabled' => true, 'push_locale' => $locale])->save();
    }

    private function open(User $me, User $other): int
    {
        return $this->actingAs($me)->postJson('/api/chat/conversations', ['user_id' => $other->id], self::EN)->json('data.id');
    }

    private function send(User $me, int $conversation, int $status = 201)
    {
        return $this->actingAs($me)->postJson("/api/chat/conversations/{$conversation}/messages", ['body' => self::SECRET], self::EN)
            ->assertStatus($status);
    }

    public function test_push_is_sent_to_the_receiver_for_a_normal_message_and_not_to_the_bell(): void
    {
        Notification::fake();
        $this->pushOn($this->rahim);
        $this->pushOn($this->karim);
        $id = $this->open($this->karim, $this->rahim);

        $this->send($this->karim, $id);

        Notification::assertSentTo($this->rahim, ChatPushAlert::class, fn (ChatPushAlert $n, array $channels) => $n->conversationId === $id
            && $n->senderName === 'Karim' && $channels === [WebPushChannel::class]);
        Notification::assertNotSentTo($this->karim, ChatPushAlert::class);
        Notification::assertSentTimes(ChatPushAlert::class, 1);
        // Chat stays out of the bell (10a).
        $this->assertDatabaseCount('user_notifications', 0);
        $this->actingAs($this->rahim)->getJson('/api/notifications/unread', self::EN)->assertOk()
            ->assertJsonPath('data.unread_count', 0);
    }

    public function test_payload_names_the_sender_only_in_the_receiver_language(): void
    {
        $this->pushOn($this->rahim, 'bn');
        $alert = new ChatPushAlert(7, 'Karim');
        $payload = $alert->toWebPush($this->rahim->fresh(), $alert)->toArray();

        $this->assertSame('JPPHAF', $payload['title']);
        $this->assertSame('Karim আপনাকে একটি মেসেজ পাঠিয়েছেন', $payload['body']);
        $this->assertSame('chat-7', $payload['tag']);
        $this->assertTrue($payload['renotify']);
        $this->assertSame(['url' => '/dashboard/chat/7'], $payload['data']);
        $this->assertSame(['title', 'body', 'icon', 'lang', 'renotify', 'tag', 'data'], array_keys($payload));

        $this->rahim->forceFill(['push_locale' => 'en'])->save();
        $payload = $alert->toWebPush($this->rahim->fresh(), $alert)->toArray();
        $this->assertSame('Karim sent you a message', $payload['body']);
        $this->assertSame('en', $payload['lang']);
    }

    public function test_payload_of_a_real_send_never_has_the_message_text(): void
    {
        $this->pushOn($this->rahim, 'en');
        $sent = [];
        $webPush = Mockery::mock(WebPush::class);
        $webPush->shouldReceive('queueNotification')->andReturnUsing(function ($sub, $payload) use (&$sent) {
            $sent[] = $payload;
        });
        $webPush->shouldReceive('flush')->andReturnUsing(fn () => yield from []);
        $this->app->instance(WebPushChannel::class, new WebPushChannel($webPush, $this->app->make(ReportHandler::class)));

        $id = $this->open($this->karim, $this->rahim);
        $this->send($this->karim, $id);

        $this->assertCount(1, $sent);
        $this->assertStringNotContainsString('4321', $sent[0]);
        $this->assertStringNotContainsString('secret', $sent[0]);
        $payload = json_decode($sent[0], true);
        $this->assertSame('Karim sent you a message', $payload['body']);
        $this->assertSame('chat-'.$id, $payload['tag']);
        $this->assertSame(['url' => '/dashboard/chat/'.$id], $payload['data']);
    }

    public function test_no_push_when_switched_off_or_without_a_subscription(): void
    {
        Notification::fake();
        $this->pushOn($this->rahim);
        $this->rahim->forceFill(['push_enabled' => false])->save();
        $this->karim->forceFill(['push_enabled' => true])->save(); // on, but no device

        $this->send($this->karim, $this->open($this->karim, $this->rahim));
        $this->send($this->rahim, $this->open($this->rahim, $this->karim));

        Notification::assertNothingSent();
        $this->assertSame(2, ChatMessage::count());
    }

    public function test_no_push_when_blocked_either_way(): void
    {
        Notification::fake();
        $this->pushOn($this->rahim);
        $this->pushOn($this->karim);
        $id = $this->open($this->karim, $this->rahim);

        // Receiver blocked the sender.
        $this->actingAs($this->rahim)->postJson('/api/chat/blocks', ['user_id' => $this->karim->id], self::EN)->assertSuccessful();
        $this->send($this->karim, $id, 403);
        // Sender blocked the receiver.
        $this->send($this->rahim, $id, 403);

        Notification::assertNothingSent();
        $this->assertSame(0, ChatMessage::count());
    }

    public function test_no_push_to_an_unavailable_or_unapproved_receiver(): void
    {
        Notification::fake();
        $this->pushOn($this->rahim);
        $id = $this->open($this->karim, $this->rahim);

        // Suspended, then pending (not approved): sending is refused and nothing is pushed.
        $this->rahim->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $this->send($this->karim, $id, 403);
        $this->rahim->forceFill(['status' => User::STATUS_PENDING])->save();
        $this->send($this->karim, $id, 403);

        Notification::assertNothingSent();
        $this->assertSame(0, ChatMessage::count());
    }

    public function test_a_failing_push_never_breaks_sending(): void
    {
        $this->pushOn($this->rahim);
        $webPush = Mockery::mock(WebPush::class);
        $webPush->shouldReceive('queueNotification');
        $webPush->shouldReceive('flush')->andThrow(new \RuntimeException('push service down'));
        $this->app->instance(WebPushChannel::class, new WebPushChannel($webPush, $this->app->make(ReportHandler::class)));
        Log::spy();

        $id = $this->open($this->karim, $this->rahim);
        $this->send($this->karim, $id)->assertJsonPath('data.body', self::SECRET);

        $this->assertSame(1, ChatMessage::count());
        Event::assertDispatched(ChatMessageSent::class);
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'Chat push to user '.$this->rahim->id))->once();
    }
}
