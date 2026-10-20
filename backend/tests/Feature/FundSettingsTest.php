<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicHomeController;
use App\Models\AuditLog;
use App\Models\Contribution;
use App\Models\ContributionRate;
use App\Models\Setting;
use App\Models\User;
use App\Support\Dues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Phase 7e: Super Admin Fund Settings — monthly amount history, receiving accounts, fund start month. */
class FundSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const EN = ['Accept' => 'application/json', 'Accept-Language' => 'en'];

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->travelTo('2026-12-15 12:00:00'); // start month 2026-10 → Oct, Nov, Dec owed
        $this->super = User::factory()->superAdmin()->create(['name' => 'Super']);
    }

    private function accounts(array $override = []): array
    {
        return array_replace_recursive([
            'bkash' => ['number' => '01712345678', 'type' => 'personal', 'visible' => true],
            'nagad' => ['number' => '01812345678', 'type' => 'agent', 'visible' => false],
            'rocket' => ['number' => null, 'type' => 'personal', 'visible' => false],
            'bank' => ['bank_name' => null, 'account_name' => null, 'account_number' => null, 'branch' => null, 'routing_number' => null, 'visible' => false],
            'instructions' => ['bn' => 'রেফারেন্সে আপনার নাম লিখুন।', 'en' => null, 'visible' => true],
        ], $override);
    }

    private function pay(User $member, array $months, string $txid, bool $approve): Contribution
    {
        $this->actingAs($member)->postJson('/api/contributions', [
            'method' => 'bkash', 'sender_number' => '01712345678', 'transaction_id' => $txid,
            'amount' => 200 * count($months), 'months' => $months,
        ], self::EN)->assertCreated();
        $c = Contribution::latest('id')->first();
        if ($approve) {
            $admin = User::factory()->admin()->create();
            $this->actingAs($admin)->postJson("/api/admin/contributions/{$c->id}/approve", [], self::EN)->assertOk();
        }

        return $c;
    }

    public function test_only_super_admin_can_use_the_settings_api(): void
    {
        $this->getJson('/api/admin/settings', self::EN)->assertUnauthorized();
        foreach (['admin', 'committee', 'approved'] as $state) {
            $user = User::factory()->{$state}()->create();
            $this->actingAs($user)->getJson('/api/admin/settings', self::EN)->assertForbidden();
            $this->actingAs($user)->postJson('/api/admin/settings/rates', ['amount' => 300, 'effective_from' => '2027-01'], self::EN)->assertForbidden();
            $this->actingAs($user)->putJson('/api/admin/settings/accounts', $this->accounts(), self::EN)->assertForbidden();
            $this->actingAs($user)->putJson('/api/admin/settings/start-month', ['start_month' => '2026-11', 'confirm' => true], self::EN)->assertForbidden();
        }

        $this->actingAs($this->super)->getJson('/api/admin/settings', self::EN)->assertOk()
            ->assertJsonPath('data.start_month', '2026-10')
            ->assertJsonPath('data.current_month', '2026-12')
            ->assertJsonPath('data.next_month', '2027-01')
            ->assertJsonPath('data.current_amount', 200)
            ->assertJsonPath('data.rates.0.effective_from', '2000-01')
            ->assertJsonPath('data.accounts.bkash.visible', false);
    }

    public function test_new_amount_applies_to_future_months_only(): void
    {
        $member = User::factory()->approved()->create();

        // Current month or earlier → refused.
        $this->actingAs($this->super)->postJson('/api/admin/settings/rates', ['amount' => 300, 'effective_from' => '2026-12'], self::EN)
            ->assertJsonValidationErrors('effective_from');
        $this->actingAs($this->super)->postJson('/api/admin/settings/rates', ['amount' => 200, 'effective_from' => '2027-01'], self::EN)
            ->assertJsonValidationErrors('amount'); // same as now
        $this->actingAs($this->super)->postJson('/api/admin/settings/rates', ['amount' => 12.5, 'effective_from' => '2027-01'], self::EN)
            ->assertJsonValidationErrors('amount');

        Cache::put(PublicHomeController::STATS_CACHE_KEY, ['x' => 1], 600);
        $this->actingAs($this->super)->postJson('/api/admin/settings/rates', ['amount' => 300, 'effective_from' => '2027-02'], self::EN)
            ->assertOk()
            ->assertJsonPath('data.rates.0.effective_from', '2027-02')
            ->assertJsonPath('data.rates.0.status', 'scheduled')
            ->assertJsonPath('data.rates.0.created_by.name', 'Super')
            ->assertJsonPath('data.current_amount', 200);
        $this->assertFalse(Cache::has(PublicHomeController::STATS_CACHE_KEY));
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.rate_scheduled', 'actor_id' => $this->super->id]);
        $log = AuditLog::where('action', 'settings.rate_scheduled')->first();
        $this->assertSame(['effective_from' => '2027-02', 'old_amount' => 200, 'new_amount' => 300, 'replaced_scheduled' => false], $log->details);

        // Past and current months keep 200.
        $this->actingAs($member)->getJson('/api/contributions/dues')->assertJsonPath('data.total_due', 600);

        // Saving the same month again replaces the planned amount.
        $this->actingAs($this->super)->postJson('/api/admin/settings/rates', ['amount' => 250, 'effective_from' => '2027-02'], self::EN)->assertOk();
        $this->assertSame(2, ContributionRate::count());
        $this->assertSame(250, ContributionRate::latest('effective_from')->first()->amount);

        // When the month arrives, it is charged with the new amount.
        $this->travelTo('2027-02-10 12:00:00');
        $this->actingAs($member)->getJson('/api/contributions/dues')
            ->assertJsonPath('data.months.4.month', '2027-02')
            ->assertJsonPath('data.months.4.amount', 250)
            ->assertJsonPath('data.total_due', 200 * 4 + 250);

        // A started amount cannot be removed.
        $rate = ContributionRate::latest('effective_from')->first();
        $this->actingAs($this->super)->deleteJson("/api/admin/settings/rates/{$rate->id}", [], self::EN)->assertStatus(422);
    }

    public function test_a_planned_amount_can_be_cancelled(): void
    {
        $this->actingAs($this->super)->postJson('/api/admin/settings/rates', ['amount' => 300, 'effective_from' => '2027-01'], self::EN)->assertOk();
        $rate = ContributionRate::latest('effective_from')->first();
        $this->actingAs($this->super)->deleteJson("/api/admin/settings/rates/{$rate->id}", [], self::EN)
            ->assertOk()->assertJsonCount(1, 'data.rates');
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.rate_cancelled']);

        // The default row (already started) cannot be removed.
        $default = ContributionRate::first();
        $this->actingAs($this->super)->deleteJson("/api/admin/settings/rates/{$default->id}", [], self::EN)->assertStatus(422);
    }

    public function test_receiving_accounts_are_saved_audited_and_only_shown_parts_reach_members(): void
    {
        $member = User::factory()->approved()->create();
        $this->actingAs($member)->getJson('/api/contributions/accounts')
            ->assertOk()->assertJsonPath('data.mobile', [])->assertJsonPath('data.bank', null)->assertJsonPath('data.instructions', null);

        $this->actingAs($this->super)->putJson('/api/admin/settings/accounts', $this->accounts([
            'bkash' => ['number' => '+880 1712-345678'],
            'rocket' => ['number' => '017123456789', 'visible' => true],
            'bank' => ['bank_name' => 'Dutch-Bangla Bank', 'account_name' => 'JPPHAF', 'account_number' => '123 456 789', 'branch' => ' Mirpur ', 'visible' => true],
        ]), self::EN)
            ->assertOk()
            ->assertJsonPath('data.accounts.bkash.number', '01712345678')
            ->assertJsonPath('data.accounts.bank.branch', 'Mirpur');

        $log = AuditLog::where('action', 'settings.accounts_changed')->firstOrFail();
        $this->assertSame(['old' => null, 'new' => '01712345678'], $log->details['changes']['bkash.number']);
        $this->assertArrayNotHasKey('nagad.visible', $log->details['changes']); // unchanged → not listed

        $this->actingAs($member)->getJson('/api/contributions/accounts')
            ->assertJsonPath('data.mobile', [
                ['method' => 'bkash', 'number' => '01712345678', 'type' => 'personal'],
                ['method' => 'rocket', 'number' => '017123456789', 'type' => 'personal'],
            ]) // Nagad hidden
            ->assertJsonPath('data.bank.bank_name', 'Dutch-Bangla Bank')
            ->assertJsonPath('data.instructions.bn', 'রেফারেন্সে আপনার নাম লিখুন।');

        // Saving the same values writes nothing.
        $this->actingAs($this->super)->putJson('/api/admin/settings/accounts', $this->accounts([
            'bkash' => ['number' => '01712345678'],
            'rocket' => ['number' => '017123456789', 'visible' => true],
            'bank' => ['bank_name' => 'Dutch-Bangla Bank', 'account_name' => 'JPPHAF', 'account_number' => '123 456 789', 'branch' => 'Mirpur', 'visible' => true],
        ]), self::EN)->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'settings.accounts_changed')->count());

        // Guests cannot see them.
        auth()->forgetGuards();
        $this->getJson('/api/contributions/accounts', self::EN)->assertUnauthorized();
    }

    public function test_receiving_accounts_validation(): void
    {
        $this->actingAs($this->super)->putJson('/api/admin/settings/accounts', $this->accounts([
            'bkash' => ['number' => null, 'visible' => true],          // shown but empty
            'nagad' => ['number' => '12345', 'type' => 'shop'],        // bad number + type
            'rocket' => ['number' => '0171234567890'],                 // 13 digits
            'bank' => ['visible' => true],                             // shown without details
            'instructions' => ['bn' => null, 'en' => 'Write your name'], // English without Bengali
        ]), self::EN)->assertJsonValidationErrors([
            'bkash.number', 'nagad.number', 'nagad.type', 'rocket.number',
            'bank.bank_name', 'bank.account_name', 'bank.account_number', 'instructions.bn',
        ]);
        $this->assertNull(Setting::valueOf(Setting::PAYMENT_ACCOUNTS));

        // Hidden parts may stay empty; instructions in Bengali only are fine.
        $this->actingAs($this->super)->putJson('/api/admin/settings/accounts', $this->accounts([
            'bkash' => ['number' => null, 'visible' => false],
        ]), self::EN)->assertOk();
    }

    public function test_start_month_change_needs_confirmation_and_changes_dues(): void
    {
        $member = User::factory()->approved()->create();

        $this->actingAs($this->super)->putJson('/api/admin/settings/start-month', ['start_month' => '2026-11'], self::EN)
            ->assertJsonValidationErrors('confirm');
        $this->actingAs($this->super)->putJson('/api/admin/settings/start-month', ['start_month' => '2026-10', 'confirm' => true], self::EN)
            ->assertJsonValidationErrors('start_month'); // unchanged
        $this->actingAs($this->super)->putJson('/api/admin/settings/start-month', ['start_month' => '2028-01', 'confirm' => true], self::EN)
            ->assertJsonValidationErrors('start_month'); // more than 12 months ahead
        $this->actingAs($this->super)->putJson('/api/admin/settings/start-month', ['start_month' => '2026-13', 'confirm' => true], self::EN)
            ->assertJsonValidationErrors('start_month');

        $this->actingAs($this->super)->putJson('/api/admin/settings/start-month', ['start_month' => '2026-08', 'confirm' => true], self::EN)
            ->assertOk()->assertJsonPath('data.start_month', '2026-08');
        $this->assertSame('2026-08', Dues::startMonth()->format('Y-m'));
        $this->actingAs($member)->getJson('/api/contributions/dues')->assertJsonPath('data.months_due', 5);

        $log = AuditLog::where('action', 'settings.start_month_changed')->firstOrFail();
        $this->assertSame(['old' => '2026-10', 'new' => '2026-08'], $log->details);
    }

    public function test_start_month_cannot_move_past_paid_or_waiting_months(): void
    {
        $member = User::factory()->approved()->create();
        $paid = $this->pay($member, ['2026-10'], 'PAID1', approve: true);

        $this->actingAs($this->super)->putJson('/api/admin/settings/start-month', ['start_month' => '2026-11', 'confirm' => true], self::EN)
            ->assertJsonValidationErrors(['start_month' => 'paid month']);
        $this->actingAs($this->super)->getJson('/api/admin/settings', self::EN)->assertJsonPath('data.earliest_paid_month', '2026-10');

        // A waiting payment blocks too (it would become paid before the start month).
        $other = User::factory()->approved()->create();
        $this->pay($other, ['2026-11'], 'WAIT1', approve: false);
        $this->actingAs($this->super)->putJson('/api/admin/settings/start-month', ['start_month' => '2026-10', 'confirm' => true], self::EN)
            ->assertJsonValidationErrors('start_month'); // unchanged
        $this->assertSame('2026-10', Setting::valueOf(Setting::FUND_START_MONTH));

        // Moving it earlier is always possible.
        $this->actingAs($this->super)->putJson('/api/admin/settings/start-month', ['start_month' => '2026-09', 'confirm' => true], self::EN)->assertOk();
        $this->assertNotNull($paid->fresh());
    }

    public function test_waiting_payment_blocks_a_later_start_month(): void
    {
        $member = User::factory()->approved()->create();
        $this->pay($member, ['2026-10'], 'WAIT2', approve: false);

        $this->actingAs($this->super)->putJson('/api/admin/settings/start-month', ['start_month' => '2026-11', 'confirm' => true], self::EN)
            ->assertJsonValidationErrors(['start_month' => 'waiting for check']);
        $this->assertSame('2026-10', Setting::valueOf(Setting::FUND_START_MONTH));
    }
}
