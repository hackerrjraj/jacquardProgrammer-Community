<?php

namespace Tests\Feature;

use App\Models\Contribution;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\PushAlert;
use Database\Seeders\DemoProgrammersSeeder;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;
use Mockery;
use NotificationChannels\WebPush\PushSubscription;
use NotificationChannels\WebPush\ReportHandler;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\TestCase;

/** Phone / desktop Web Push (Phase 10c-1, MASTER_SPEC §36). */
class PushTest extends TestCase
{
    use RefreshDatabase;

    private const EN = ['Accept' => 'application/json', 'Accept-Language' => 'en'];

    private const BN = ['Accept' => 'application/json', 'Accept-Language' => 'bn'];

    private User $member;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Storage::fake('public');
        $this->travelTo('2026-10-15 12:00:00');
        Setting::put(Setting::FUND_START_MONTH, '2026-08');
        config(['webpush.vapid.public_key' => 'TEST_PUBLIC_KEY']);
        $this->member = User::factory()->approved()->create(['approved_at' => '2026-08-10 09:00:00']);
        $this->admin = User::factory()->admin()->create();
    }

    private function subscription(string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123'): array
    {
        return ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'BPublicKeyValue', 'auth' => 'AuthSecret']];
    }

    private function subscribe(User $user, array $headers = self::EN, ?string $endpoint = null)
    {
        return $this->actingAs($user)->postJson('/api/push/subscribe', $endpoint ? $this->subscription($endpoint) : $this->subscription(), $headers);
    }

    private function approvedPayment(User $user): Contribution
    {
        $this->actingAs($user)->post('/api/contributions', [
            'method' => 'bkash',
            'sender_number' => '01712345678',
            'transaction_id' => 'ABC'.fake()->unique()->numberBetween(100, 999),
            'amount' => 200,
            'months' => ['2026-08'],
        ], self::EN)->assertCreated();
        $c = Contribution::latest('id')->first();
        $this->actingAs($this->admin)->postJson("/api/admin/contributions/{$c->id}/approve", [], self::EN)->assertOk();

        return $c;
    }

    public function test_public_key_and_subscribe_unsubscribe_settings(): void
    {
        $this->actingAs($this->member)->getJson('/api/push/public-key', self::EN)->assertOk()
            ->assertJsonPath('data.public_key', 'TEST_PUBLIC_KEY');
        $this->actingAs($this->member)->getJson('/api/push/settings', self::EN)->assertOk()
            ->assertJsonPath('data.push_enabled', false)->assertJsonPath('data.devices', 0);

        $this->subscribe($this->member, self::BN)->assertOk()
            ->assertJsonPath('data.push_enabled', true)->assertJsonPath('data.devices', 1);
        $this->subscribe($this->member, self::EN, 'https://web.push.apple.com/xyz')->assertOk()
            ->assertJsonPath('message', 'Phone / desktop notifications are on for this device.')
            ->assertJsonPath('data.devices', 2);
        $this->member->refresh();
        $this->assertTrue($this->member->push_enabled);
        $this->assertSame('en', $this->member->push_locale);
        $this->assertDatabaseHas('push_subscriptions', [
            'subscribable_id' => $this->member->id, 'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
            'public_key' => 'BPublicKeyValue', 'auth_token' => 'AuthSecret',
        ]);

        // Same browser again → updated, not doubled.
        $this->subscribe($this->member)->assertOk()->assertJsonPath('data.devices', 2);

        // Switch off / on (on needs a device).
        $this->actingAs($this->member)->putJson('/api/push/settings', ['push_enabled' => false], self::BN)->assertOk()
            ->assertJsonPath('data.push_enabled', false);
        $this->assertSame('bn', $this->member->fresh()->push_locale);
        $this->actingAs($this->member)->putJson('/api/push/settings', ['push_enabled' => true], self::EN)->assertOk()
            ->assertJsonPath('data.push_enabled', true);

        // Removing one device keeps it on; removing the last switches it off.
        $this->actingAs($this->member)->postJson('/api/push/unsubscribe', ['endpoint' => 'https://web.push.apple.com/xyz'], self::EN)
            ->assertOk()->assertJsonPath('data.push_enabled', true)->assertJsonPath('data.devices', 1);
        $this->actingAs($this->member)->postJson('/api/push/unsubscribe', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123'], self::EN)
            ->assertOk()->assertJsonPath('data.push_enabled', false)->assertJsonPath('data.devices', 0);
        $this->assertFalse($this->member->fresh()->push_enabled);

        $this->actingAs($this->member)->putJson('/api/push/settings', ['push_enabled' => true], self::EN)
            ->assertStatus(422)->assertJsonPath('message', 'Allow notifications on this device first.');
    }

    public function test_one_browser_moves_to_the_member_who_subscribes_last(): void
    {
        $other = User::factory()->approved()->create();
        $this->subscribe($this->member)->assertOk();
        $this->subscribe($other)->assertOk();
        $this->assertSame(0, $this->member->pushSubscriptions()->count());
        $this->assertSame(1, $other->pushSubscriptions()->count());

        // Unsubscribing someone else's endpoint removes nothing.
        $this->actingAs($this->member)->postJson('/api/push/unsubscribe', $this->subscription(), self::EN)->assertOk();
        $this->assertSame(1, $other->pushSubscriptions()->count());
    }

    public function test_validation(): void
    {
        $this->actingAs($this->member)->postJson('/api/push/subscribe', [], self::EN)->assertStatus(422)
            ->assertJsonValidationErrors(['endpoint', 'keys']);
        $this->actingAs($this->member)->postJson('/api/push/subscribe', ['endpoint' => 'http://insecure.example/x', 'keys' => ['p256dh' => 'a', 'auth' => 'b']], self::EN)
            ->assertStatus(422)->assertJsonValidationErrors(['endpoint']);
        $this->actingAs($this->member)->postJson('/api/push/subscribe', ['endpoint' => 'not a url', 'keys' => ['p256dh' => 'a', 'auth' => 'b']], self::EN)
            ->assertStatus(422)->assertJsonValidationErrors(['endpoint']);
        $this->actingAs($this->member)->postJson('/api/push/subscribe', ['endpoint' => 'https://push.example/'.str_repeat('a', 1100), 'keys' => ['p256dh' => 'a', 'auth' => 'b']], self::EN)
            ->assertStatus(422)->assertJsonValidationErrors(['endpoint']);
        $this->actingAs($this->member)->postJson('/api/push/subscribe', ['endpoint' => 'https://push.example/x', 'keys' => ['p256dh' => 'a']], self::EN)
            ->assertStatus(422)->assertJsonValidationErrors(['keys.auth']);
        $this->actingAs($this->member)->postJson('/api/push/unsubscribe', [], self::EN)->assertStatus(422)
            ->assertJsonValidationErrors(['endpoint']);
        $this->actingAs($this->member)->putJson('/api/push/settings', ['push_enabled' => 'maybe'], self::EN)->assertStatus(422)
            ->assertJsonValidationErrors(['push_enabled']);
        $this->assertSame(0, PushSubscription::count());
        $this->assertFalse($this->member->fresh()->push_enabled);
    }

    public function test_guests_unapproved_suspended_and_demo_are_refused(): void
    {
        $this->getJson('/api/push/public-key')->assertUnauthorized();
        $this->postJson('/api/push/subscribe', $this->subscription())->assertUnauthorized();
        $this->getJson('/api/push/settings')->assertUnauthorized();

        $demo = User::factory()->approved()->create(['email' => 'demo1@'.DemoProgrammersSeeder::EMAIL_DOMAIN]);
        foreach ([User::factory()->create(), User::factory()->suspended()->create(), $demo] as $user) {
            $this->actingAs($user)->getJson('/api/push/public-key', self::EN)->assertForbidden();
            $this->actingAs($user)->postJson('/api/push/subscribe', $this->subscription(), self::EN)->assertForbidden();
            $this->actingAs($user)->postJson('/api/push/unsubscribe', $this->subscription(), self::EN)->assertForbidden();
            $this->actingAs($user)->getJson('/api/push/settings', self::EN)->assertForbidden();
            $this->actingAs($user)->putJson('/api/push/settings', ['push_enabled' => false], self::EN)->assertForbidden();
        }
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_web_push_channel_only_when_switched_on_and_subscribed(): void
    {
        Notification::fake();
        $off = User::factory()->approved()->create();   // subscribed, then switched off
        $noDevice = User::factory()->approved()->create();
        $noDevice->forceFill(['push_enabled' => true])->save(); // on, but no subscription
        $this->subscribe($this->member, self::BN)->assertOk();
        $this->subscribe($off, self::EN, 'https://push.example/off')->assertOk();
        $this->actingAs($off)->putJson('/api/push/settings', ['push_enabled' => false], self::EN)->assertOk();

        $this->approvedPayment($this->member);
        $this->approvedPayment($off);
        $this->approvedPayment($noDevice);

        Notification::assertSentTo($this->member, PushAlert::class, fn (PushAlert $n, array $channels) => $n->type === 'payment_approved'
            && $channels === [WebPushChannel::class]);
        Notification::assertNotSentTo($off, PushAlert::class);
        Notification::assertNotSentTo($noDevice, PushAlert::class);

        // via() itself decides too.
        $alert = new PushAlert('payment_approved', ['amount' => 200]);
        $this->assertSame([WebPushChannel::class], $alert->via($this->member->fresh()));
        $this->assertSame([], $alert->via($off->fresh()));
        $this->assertSame([], $alert->via($noDevice->fresh()));
    }

    public function test_payload_has_short_text_in_the_member_language_and_no_private_details(): void
    {
        $this->subscribe($this->member, self::BN)->assertOk();
        $alert = new PushAlert('payment_approved', ['contribution_id' => 5, 'amount' => 1200]);
        $payload = $alert->toWebPush($this->member->fresh(), $alert)->toArray();

        $this->assertSame('JPPHAF', $payload['title']);
        $this->assertSame('পেমেন্ট যাচাই হয়েছে — আপনার ৳১,২০০ পেমেন্ট যাচাই করা হয়েছে। ধন্যবাদ!', $payload['body']);
        $this->assertSame('/icons/icon-192.png', $payload['icon']);
        $this->assertSame(['url' => '/dashboard/payments'], $payload['data']);
        $this->assertSame(['title', 'body', 'icon', 'lang', 'data'], array_keys($payload));

        $this->actingAs($this->member)->putJson('/api/push/settings', ['push_enabled' => true], self::EN)->assertOk();
        $notice = new PushAlert('notice_published', ['notice_id' => 9, 'title_bn' => 'সভা', 'title_en' => 'Meeting']);
        $payload = $notice->toWebPush($this->member->fresh(), $notice)->toArray();
        $this->assertSame('New notice — Meeting', $payload['body']);
        $this->assertSame(['url' => '/notices/9'], $payload['data']);
    }

    public function test_expired_subscriptions_are_deleted(): void
    {
        $this->subscribe($this->member)->assertOk();
        $this->subscribe($this->member, self::EN, 'https://push.example/alive')->assertOk();
        $gone = 'https://fcm.googleapis.com/fcm/send/abc123';

        $webPush = Mockery::mock(WebPush::class);
        $webPush->shouldReceive('queueNotification')->twice();
        $webPush->shouldReceive('flush')->once()->andReturnUsing(function () use ($gone) {
            yield new MessageSentReport(new Request('POST', $gone), new Response(410), false, 'Gone');
            yield new MessageSentReport(new Request('POST', 'https://push.example/alive'), new Response(201));
        });
        $this->app->instance(WebPushChannel::class, new WebPushChannel($webPush, $this->app->make(ReportHandler::class)));

        $this->approvedPayment($this->member);

        $this->assertDatabaseMissing('push_subscriptions', ['endpoint' => $gone]);
        $this->assertDatabaseHas('push_subscriptions', ['endpoint' => 'https://push.example/alive']);
        // The bell row is saved as before.
        $this->assertDatabaseHas('user_notifications', ['user_id' => $this->member->id, 'type' => 'payment_approved']);
    }

    public function test_a_failing_push_never_breaks_the_action(): void
    {
        $this->subscribe($this->member)->assertOk();
        $webPush = Mockery::mock(WebPush::class);
        $webPush->shouldReceive('queueNotification');
        $webPush->shouldReceive('flush')->andThrow(new \RuntimeException('push service down'));
        $this->app->instance(WebPushChannel::class, new WebPushChannel($webPush, $this->app->make(ReportHandler::class)));

        $c = $this->approvedPayment($this->member);
        $this->assertSame(Contribution::STATUS_APPROVED, $c->fresh()->status);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $this->member->id, 'type' => 'payment_approved']);
    }
}
