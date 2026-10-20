<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardSummaryTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/dashboard/summary';

    protected function setUp(): void
    {
        parent::setUp();
        // Fund start month = 2026-10 (default) → one month (200) owed by everyone who owes dues.
        $this->travelTo('2026-10-15 12:00:00');
    }

    /** 2 pending, 1 rejected, 1 suspended, 3 approved members (+ whoever the test logs in as). */
    private function seedMembers(): void
    {
        User::factory()->count(2)->create();
        User::factory()->rejected()->create();
        User::factory()->suspended()->create();
        User::factory()->count(3)->approved()->create();
    }

    public function test_guests_get_401(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function test_admin_and_super_admin_see_active_members_and_pending_registrations(): void
    {
        $this->seedMembers();
        $admin = User::factory()->admin()->create();
        $super = User::factory()->superAdmin()->create();

        foreach ([$admin, $super] as $user) {
            $this->actingAs($user)->getJson(self::URL)
                ->assertOk()
                ->assertJsonPath('data.scope', 'all')
                ->assertJsonPath('data.active_members', 5) // 3 approved + admin + super_admin
                ->assertJsonPath('data.pending_registrations', 2)
                ->assertJsonPath('data.pending_payments', 0)
                ->assertJsonPath('data.total_collected', 0)
                ->assertJsonPath('data.collected_this_month', 0)
                // October only (fund start month) × 200 × 4 who owe: 3 approved + 1 suspended (Admin / Super Admin owe nothing).
                ->assertJsonPath('data.outstanding_dues', 800)
                ->assertJsonPath('data.members_with_dues', 4)
                ->assertJsonPath('data.total_disbursed', 0)
                ->assertJsonPath('data.fund_balance', 0)
                ->assertJsonPath('data.start_month', '2026-10');
        }
    }

    public function test_committee_sees_active_members_but_not_pending_registrations(): void
    {
        $this->seedMembers();
        $committee = User::factory()->committee()->create();

        $this->actingAs($committee)->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.scope', 'all')
            ->assertJsonPath('data.active_members', 4)
            ->assertJsonPath('data.pending_registrations', null)
            ->assertJsonPath('data.pending_payments', null)
            ->assertJsonPath('data.outstanding_dues', 1000);
    }

    public function test_member_sees_only_own_summary(): void
    {
        $this->seedMembers();
        $member = User::factory()->approved()->create();

        $response = $this->actingAs($member)->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.scope', 'own')
            ->assertJsonPath('data.total_paid', 0)
            ->assertJsonPath('data.total_due', 200)
            ->assertJsonPath('data.months_due', 1);

        $this->assertArrayNotHasKey('active_members', $response->json('data'));
        $this->assertArrayNotHasKey('pending_registrations', $response->json('data'));
    }

    public function test_not_approved_accounts_and_forced_password_change_are_blocked(): void
    {
        foreach ([User::factory()->create(), User::factory()->suspended()->create(), User::factory()->rejected()->create()] as $user) {
            $this->app['auth']->forgetGuards();
            $this->actingAs($user)->getJson(self::URL)->assertForbidden();
        }

        $mustChange = User::factory()->admin()->create(['must_change_password' => true]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($mustChange)->getJson(self::URL)->assertForbidden();
    }
}
