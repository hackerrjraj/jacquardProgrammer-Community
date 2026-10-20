<?php

namespace Tests\Feature;

use App\Events\NotificationCreated;
use App\Models\AssistanceRequest;
use App\Models\Contribution;
use App\Models\LedgerEntry;
use App\Models\Notice;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserNotification;
use Database\Seeders\DemoProgrammersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** In-app notifications (Phase 10a, MASTER_SPEC §36). */
class NotificationTest extends TestCase
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
        $this->member = User::factory()->approved()->create(['name' => 'Rahim', 'approved_at' => '2026-08-10 09:00:00']);
        $this->admin = User::factory()->admin()->create();
    }

    private function list(User $user, array $headers = self::EN)
    {
        return $this->actingAs($user)->getJson('/api/notifications', $headers);
    }

    private function demo(): User
    {
        return User::factory()->approved()->create(['email' => 'demo'.fake()->unique()->numberBetween(1, 9999).'@'.DemoProgrammersSeeder::EMAIL_DOMAIN]);
    }

    private function submitPayment(string $month): Contribution
    {
        $this->actingAs($this->member)->post('/api/contributions', [
            'method' => 'bkash',
            'sender_number' => '01712345678',
            'transaction_id' => 'ABC'.fake()->unique()->numberBetween(100, 999),
            'amount' => 200,
            'months' => [$month],
        ], self::EN)->assertCreated();

        return Contribution::latest('id')->first();
    }

    public function test_member_approval_notifies_the_member_in_their_language(): void
    {
        Event::fake([NotificationCreated::class]);
        $pending = User::factory()->create();
        $this->actingAs($this->admin)->postJson("/api/admin/members/{$pending->id}/approve", [], self::EN)->assertOk();

        $pending->refresh();
        $this->list($pending)->assertOk()
            ->assertJsonPath('meta.unread_count', 1)
            ->assertJsonPath('data.0.type', 'member_approved')
            ->assertJsonPath('data.0.title', 'Welcome! Your account is approved')
            ->assertJsonPath('data.0.link', '/dashboard')
            ->assertJsonPath('data.0.read', false);
        $this->list($pending, self::BN)->assertJsonPath('data.0.title', 'স্বাগতম! আপনার অ্যাকাউন্ট অনুমোদিত হয়েছে');
        Event::assertDispatched(NotificationCreated::class, fn ($e) => $e->userIds === [$pending->id]
            && $e->broadcastOn()[0]->name === "private-App.Models.User.{$pending->id}");

        // Rejecting a registration sends nothing (they cannot log in; the reason shows at login).
        $other = User::factory()->create();
        $this->actingAs($this->admin)->postJson("/api/admin/members/{$other->id}/reject", ['reason' => 'No'], self::EN)->assertOk();
        $this->assertSame(0, UserNotification::where('user_id', $other->id)->count());
    }

    public function test_payment_verified_and_rejected(): void
    {
        $c = $this->submitPayment('2026-08');
        $this->actingAs($this->admin)->postJson("/api/admin/contributions/{$c->id}/approve", [], self::EN)->assertOk();
        $this->list($this->member)
            ->assertJsonPath('data.0.type', 'payment_approved')
            ->assertJsonPath('data.0.body', 'Your payment of ৳200 has been verified. Thank you!')
            ->assertJsonPath('data.0.link', '/dashboard/payments');
        $this->list($this->member, self::BN)->assertJsonPath('data.0.body', 'আপনার ৳২০০ পেমেন্ট যাচাই করা হয়েছে। ধন্যবাদ!');

        $c2 = $this->submitPayment('2026-09');
        $this->actingAs($this->admin)->postJson("/api/admin/contributions/{$c2->id}/reject", ['reason' => 'TxID not found'], self::EN)->assertOk();
        $this->list($this->member)
            ->assertJsonPath('meta.unread_count', 2)
            ->assertJsonPath('data.0.type', 'payment_rejected')
            ->assertJsonPath('data.0.body', 'Your payment of ৳200 was not accepted. Reason: TxID not found');

        // The admin got nothing.
        $this->list($this->admin)->assertJsonPath('meta.unread_count', 0);
    }

    public function test_aid_request_notifies_voters_and_decisions_notify_the_applicant(): void
    {
        $committee = User::factory()->committee()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $pending = User::factory()->create();
        $suspended = User::factory()->suspended()->create();
        $demo = $this->demo();

        $this->actingAs($this->member)->post('/api/assistance', [
            'reason_type' => 'medical', 'description' => 'Hospital bill.', 'amount_requested' => 15000,
        ], self::EN)->assertCreated();
        $r = AssistanceRequest::latest('id')->first();

        foreach ([$this->admin, $committee, $superAdmin] as $voter) {
            $this->list($voter)->assertJsonPath('meta.unread_count', 1)
                ->assertJsonPath('data.0.type', 'aid_vote_open')
                ->assertJsonPath('data.0.body', "Aid request {$r->case_id} is open. Please give your opinion (Yes / No).")
                ->assertJsonPath('data.0.link', '/dashboard/assistance');
        }
        foreach ([$this->member, $pending, $suspended, $demo] as $nobody) {
            $this->assertSame(0, UserNotification::where('user_id', $nobody->id)->count());
        }

        $this->actingAs($this->admin)->postJson("/api/admin/assistance/{$r->id}/approve", ['amount_approved' => 10000], self::EN)->assertOk();
        $this->list($this->member)->assertJsonPath('data.0.type', 'aid_approved')
            ->assertJsonPath('data.0.body', "Your aid request {$r->case_id} was approved for ৳10,000. The payout will follow.");

        LedgerEntry::create(['type' => LedgerEntry::TYPE_CONTRIBUTION, 'amount' => 20000, 'user_id' => $committee->id, 'entry_date' => '2026-10-01']);
        $this->actingAs($this->admin)->post("/api/admin/assistance/{$r->id}/pay", ['method' => 'bkash', 'payout_date' => '2026-10-15'], self::EN)->assertOk();
        $this->list($this->member)->assertJsonPath('meta.unread_count', 2)->assertJsonPath('data.0.type', 'aid_paid');

        // Rejection carries the reason.
        $this->actingAs($committee)->post('/api/assistance', [
            'reason_type' => 'accident', 'description' => 'Broken arm.', 'amount_requested' => 5000,
        ], self::EN)->assertCreated();
        $r2 = AssistanceRequest::latest('id')->first();
        $this->actingAs($this->admin)->postJson("/api/admin/assistance/{$r2->id}/reject", ['reason' => 'Not enough proof'], self::EN)->assertOk();
        $this->list($committee)->assertJsonPath('data.0.type', 'aid_rejected')
            ->assertJsonPath('data.0.body', "Your aid request {$r2->case_id} was not approved. Reason: Not enough proof");
    }

    public function test_notice_notifies_members_once_and_scheduled_notices_later(): void
    {
        $other = User::factory()->approved()->create();
        $pending = User::factory()->create();
        $demo = $this->demo();

        $this->actingAs($this->admin)->postJson('/api/admin/notices', [
            'title_bn' => 'সাধারণ সভা', 'title_en' => 'General meeting', 'body_bn' => 'বিস্তারিত',
        ], self::EN)->assertCreated();
        $notice = Notice::latest('id')->first();

        $this->list($this->member)->assertJsonPath('data.0.type', 'notice_published')
            ->assertJsonPath('data.0.body', 'General meeting')
            ->assertJsonPath('data.0.link', "/notices/{$notice->id}");
        $this->list($this->member, self::BN)->assertJsonPath('data.0.body', 'সাধারণ সভা');
        $this->assertSame(1, UserNotification::where('user_id', $other->id)->count());
        foreach ([$this->admin, $pending, $demo] as $nobody) {
            $this->assertSame(0, UserNotification::where('user_id', $nobody->id)->count());
        }

        // Unpublish + publish again → no second notification.
        $this->actingAs($this->admin)->postJson("/api/admin/notices/{$notice->id}/unpublish")->assertOk();
        $this->actingAs($this->admin)->postJson("/api/admin/notices/{$notice->id}/publish")->assertOk();
        $this->assertSame(1, UserNotification::where('user_id', $this->member->id)->count());

        // A draft notifies when it is published.
        $this->actingAs($this->admin)->postJson('/api/admin/notices', [
            'title_bn' => 'খসড়া', 'body_bn' => 'x', 'is_published' => false,
        ], self::EN)->assertCreated();
        $draft = Notice::latest('id')->first();
        $this->assertSame(1, UserNotification::where('user_id', $this->member->id)->count());
        $this->actingAs($this->admin)->postJson("/api/admin/notices/{$draft->id}/publish")->assertOk();
        $this->list($this->member)->assertJsonPath('meta.unread_count', 2)->assertJsonPath('data.0.body', 'খসড়া'); // no English title → Bengali

        // Scheduled for tomorrow → nothing now; once the date has come, the next bell check sends it.
        $this->actingAs($this->admin)->postJson('/api/admin/notices', [
            'title_bn' => 'পরে', 'body_bn' => 'x', 'published_at' => '2026-10-16 09:00:00',
        ], self::EN)->assertCreated();
        $this->actingAs($this->member)->getJson('/api/notifications/unread')->assertJsonPath('data.unread_count', 2);
        $this->travelTo('2026-10-16 10:00:00');
        Cache::flush();
        $this->actingAs($this->member)->getJson('/api/notifications/unread')->assertJsonPath('data.unread_count', 3);
        $this->actingAs($other)->getJson('/api/notifications/unread')->assertJsonPath('data.unread_count', 3);
        $this->assertSame(0, UserNotification::where('user_id', $this->admin->id)->count()); // the creator
    }

    public function test_mark_read_only_own_and_read_all(): void
    {
        $other = User::factory()->approved()->create();
        foreach ([$this->member, $this->member, $other] as $u) {
            UserNotification::create(['user_id' => $u->id, 'type' => UserNotification::MEMBER_APPROVED, 'data' => []]);
        }
        [$a, $b] = UserNotification::where('user_id', $this->member->id)->pluck('id')->all();
        $theirs = UserNotification::where('user_id', $other->id)->value('id');

        $this->actingAs($this->member)->postJson("/api/notifications/{$a}/read")->assertOk()->assertJsonPath('data.unread_count', 1);
        $this->actingAs($this->member)->postJson("/api/notifications/{$a}/read")->assertOk()->assertJsonPath('data.unread_count', 1);
        $this->actingAs($this->member)->postJson("/api/notifications/{$theirs}/read")->assertNotFound();
        $this->list($this->member)->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $b)->assertJsonPath('data.1.read', true);

        $this->actingAs($this->member)->postJson('/api/notifications/read-all', [], self::EN)->assertOk()
            ->assertJsonPath('message', 'All notifications marked as read.')->assertJsonPath('data.unread_count', 0);
        $this->actingAs($other)->getJson('/api/notifications/unread')->assertJsonPath('data.unread_count', 1);
    }

    public function test_guests_and_not_approved_are_refused(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();
        $this->getJson('/api/notifications/unread')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/notifications/unread')->assertForbidden();
        $this->actingAs(User::factory()->suspended()->create())->getJson('/api/notifications')->assertForbidden();
    }

    public function test_actions_still_work_when_the_live_server_is_down(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 1, 'broadcasting.connections.reverb.options.scheme' => 'http']);
        $pending = User::factory()->create();
        $this->actingAs($this->admin)->postJson("/api/admin/members/{$pending->id}/approve", [], self::EN)->assertOk();
        $this->assertSame(1, UserNotification::where('user_id', $pending->id)->count());
        $this->assertTrue(Cache::has('chat_live_down'));
    }
}
