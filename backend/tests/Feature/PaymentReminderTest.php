<?php

namespace Tests\Feature;

use App\Jobs\SendPaymentReminders;
use App\Models\AuditLog;
use App\Models\Contribution;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Payment-due reminder: who gets it, daily de-duplication, on/off, admin page, dashboard popup status. */
class PaymentReminderTest extends TestCase
{
    use RefreshDatabase;

    private const EN = ['Accept' => 'application/json', 'Accept-Language' => 'en'];

    private const BN = ['Accept' => 'application/json', 'Accept-Language' => 'bn'];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->travelTo('2026-10-15 15:00:00'); // start month 2026-10 → only October is owed
        $this->admin = User::factory()->admin()->create(['name' => 'Admin']);
    }

    private function pay(User $member, bool $approve): Contribution
    {
        $this->actingAs($member)->postJson('/api/contributions', [
            'method' => 'bkash', 'sender_number' => '01712345678',
            'transaction_id' => 'TX'.fake()->unique()->numberBetween(1000, 9999),
            'amount' => 200, 'months' => ['2026-10'],
        ], self::EN)->assertCreated();
        $c = Contribution::latest('id')->first();
        if ($approve) {
            $this->actingAs($this->admin)->postJson("/api/admin/contributions/{$c->id}/approve", [], self::EN)->assertOk();
        }

        return $c;
    }

    private function remindersOf(User $u): int
    {
        return UserNotification::where('user_id', $u->id)->where('type', UserNotification::PAYMENT_DUE_REMINDER)->count();
    }

    public function test_only_members_with_dues_are_notified_and_a_pending_payment_does_not_stop_it(): void
    {
        $due = User::factory()->approved()->create();
        $paid = User::factory()->approved()->create();
        $pending = User::factory()->approved()->create();
        $waiting = User::factory()->create(['status' => User::STATUS_PENDING]);
        $this->pay($paid, true);
        $this->pay($pending, false);

        (new SendPaymentReminders)->handle();

        $this->assertSame(1, $this->remindersOf($due));
        $this->assertSame(0, $this->remindersOf($paid));
        $this->assertSame(1, $this->remindersOf($pending));
        $this->assertSame(0, $this->remindersOf($waiting));

        $item = $this->actingAs($due)->getJson('/api/notifications', self::BN)->assertOk()->json('data.0');
        $this->assertSame('/dashboard/payments', $item['link']);
        $this->assertStringContainsString('আপনার বকেয়া পেমেন্ট পরিশোধ করুন', $item['body']);
    }

    public function test_a_member_stops_receiving_it_once_the_admin_confirms_the_payment(): void
    {
        $member = User::factory()->approved()->create();
        $c = $this->pay($member, false);
        (new SendPaymentReminders)->handle();
        $this->assertSame(1, $this->remindersOf($member));

        $this->actingAs($this->admin)->postJson("/api/admin/contributions/{$c->id}/approve", [], self::EN)->assertOk();
        $this->travelTo('2026-10-16 15:00:00');
        (new SendPaymentReminders)->handle();
        $this->assertSame(1, $this->remindersOf($member));
    }

    public function test_no_duplicate_on_the_same_day_but_a_new_day_sends_again(): void
    {
        $member = User::factory()->approved()->create();

        (new SendPaymentReminders)->handle();
        (new SendPaymentReminders)->handle();
        $this->assertSame(1, $this->remindersOf($member));

        $this->travelTo('2026-10-16 14:00:00');
        (new SendPaymentReminders)->handle();
        $this->assertSame(2, $this->remindersOf($member));
    }

    public function test_nobody_is_notified_when_the_reminder_is_off(): void
    {
        $member = User::factory()->approved()->create();
        $this->actingAs($this->admin)->putJson('/api/admin/payment-reminder', ['enabled' => false], self::EN)->assertOk();

        (new SendPaymentReminders)->handle();

        $this->assertSame(0, $this->remindersOf($member));
    }

    public function test_admin_and_super_admin_never_get_the_reminder_or_popup(): void
    {
        $super = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_APPROVED]);
        $member = User::factory()->approved()->create();

        (new SendPaymentReminders)->handle();

        $this->assertSame(0, $this->remindersOf($this->admin));
        $this->assertSame(0, $this->remindersOf($super));
        $this->assertSame(1, $this->remindersOf($member));

        foreach ([$super, $this->admin] as $manager) {
            $this->actingAs($manager)->getJson('/api/payment-reminder/status', self::EN)->assertOk()
                ->assertJsonPath('data.show', false)->assertJsonPath('data.total_due', 0);
        }
        $this->actingAs($member)->getJson('/api/payment-reminder/status', self::EN)->assertOk()
            ->assertJsonPath('data.show', true);
    }

    public function test_job_is_scheduled_daily_at_2pm_dhaka(): void
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($e) => $e->description === 'payment-reminders');
        $this->assertNotNull($event);
        $this->assertSame('0 14 * * *', $event->expression);
        $this->assertSame('Asia/Dhaka', (string) $event->timezone);
    }

    public function test_admin_page_saves_message_logs_audit_and_lists_recipients(): void
    {
        $due = User::factory()->approved()->create(['name' => 'Karim']);
        $paid = User::factory()->approved()->create();
        $this->pay($paid, true);

        $this->actingAs($this->admin)->getJson('/api/admin/payment-reminder', self::EN)->assertOk()
            ->assertJsonPath('data.message_bn', 'আপনার বকেয়া পেমেন্ট পরিশোধ করুন')
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.recipient_count', 1) // Karim only (admins are management-only)
            ->assertJsonPath('data.recipients.0.name', 'Karim')
            ->assertJsonPath('data.recipients.0.total_due', 200);

        $this->actingAs($this->admin)->putJson('/api/admin/payment-reminder', [
            'enabled' => true, 'message_bn' => 'পেমেন্ট দিন', 'message_en' => 'Pay now',
        ], self::EN)->assertOk()->assertJsonPath('data.message_en', 'Pay now');

        $log = AuditLog::where('action', 'payment_reminder.updated')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertEqualsCanonicalizing(['message_bn', 'message_en'], $log->details['fields']);
        $this->assertSame('Pay now', json_decode(Setting::valueOf(Setting::PAYMENT_REMINDER), true)['message_en']);

        (new SendPaymentReminders)->handle();
        $this->assertSame(1, $this->remindersOf($due));
        $this->assertStringContainsString('Pay now', $this->actingAs($due)->getJson('/api/notifications', self::EN)->json('data.0.body'));
    }

    public function test_admin_page_is_for_admins_only(): void
    {
        $member = User::factory()->approved()->create();
        $committee = User::factory()->approved()->create(['role' => User::ROLE_COMMITTEE]);
        $super = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_APPROVED]);

        $this->actingAs($member)->getJson('/api/admin/payment-reminder', self::EN)->assertForbidden();
        $this->actingAs($committee)->putJson('/api/admin/payment-reminder', ['enabled' => false], self::EN)->assertForbidden();
        $this->actingAs($super)->getJson('/api/admin/payment-reminder', self::EN)->assertOk();
    }

    public function test_popup_status_reflects_dues(): void
    {
        $due = User::factory()->approved()->create();
        $paid = User::factory()->approved()->create();
        $pending = User::factory()->approved()->create();
        $this->pay($paid, true);
        $this->pay($pending, false);

        $this->actingAs($due)->getJson('/api/payment-reminder/status', self::BN)->assertOk()
            ->assertJsonPath('data.show', true)
            ->assertJsonPath('data.total_due', 200)
            ->assertJsonPath('data.message', 'আপনার বকেয়া পেমেন্ট পরিশোধ করুন')
            ->assertJsonPath('data.pay_url', '/dashboard/payments');
        $this->actingAs($pending)->getJson('/api/payment-reminder/status', self::EN)->assertJsonPath('data.show', true)
            ->assertJsonPath('data.message', 'Please pay your outstanding dues');
        $this->actingAs($paid)->getJson('/api/payment-reminder/status', self::EN)->assertOk()
            ->assertJsonPath('data.show', false)->assertJsonPath('data.total_due', 0);

        $this->actingAs($this->admin)->putJson('/api/admin/payment-reminder', ['enabled' => false], self::EN)->assertOk();
        $this->actingAs($due)->getJson('/api/payment-reminder/status', self::EN)->assertJsonPath('data.show', false);
    }
}
