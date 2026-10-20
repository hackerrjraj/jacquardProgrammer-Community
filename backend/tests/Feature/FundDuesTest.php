<?php

namespace Tests\Feature;

use App\Models\Contribution;
use App\Models\ContributionRate;
use App\Models\Setting;
use App\Models\User;
use App\Support\Dues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Phase 7d: fund start month dues rule, dashboard money, Programmers List payment columns, histories. */
class FundDuesTest extends TestCase
{
    use RefreshDatabase;

    private const EN = ['Accept' => 'application/json', 'Accept-Language' => 'en'];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->travelTo('2026-12-15 12:00:00'); // start month 2026-10 (default) → Oct, Nov, Dec owed
        $this->admin = User::factory()->admin()->create(['name' => 'Admin']);
    }

    private function pay(User $member, array $months, string $txid, bool $approve = true, ?int $amount = null): Contribution
    {
        $this->actingAs($member)->postJson('/api/contributions', [
            'method' => 'bkash',
            'sender_number' => '01712345678',
            'transaction_id' => $txid,
            'amount' => $amount ?? 200 * count($months),
            'months' => $months,
        ], self::EN)->assertCreated();
        $c = Contribution::latest('id')->first();
        if ($approve) {
            $this->actingAs($this->admin)->postJson("/api/admin/contributions/{$c->id}/approve", [], self::EN)->assertOk();
        }

        return $c;
    }

    public function test_default_start_month_is_october_2026_and_stored_as_a_setting(): void
    {
        $this->assertSame('2026-10', Setting::valueOf(Setting::FUND_START_MONTH));
        $this->assertSame('2026-10-01', Dues::startMonth()->toDateString());

        // Before the start month nobody owes anything and nothing can be paid.
        $this->travelTo('2026-09-20 12:00:00');
        $member = User::factory()->approved()->create(['approved_at' => '2026-09-01']);
        $this->actingAs($member)->getJson('/api/contributions/dues')
            ->assertJsonCount(0, 'data.months')->assertJsonPath('data.total_due', 0);
        $this->actingAs($member)->postJson('/api/contributions', [
            'method' => 'cash', 'amount' => 200, 'months' => ['2026-09'],
        ], self::EN)->assertJsonValidationErrors('months');
    }

    public function test_dues_run_from_the_start_month_whenever_the_member_joined(): void
    {
        $this->travelTo('2027-03-15 12:00:00');
        $member = User::factory()->approved()->create(['approved_at' => '2027-03-02 10:00:00']);

        $this->actingAs($member)->getJson('/api/contributions/dues')
            ->assertJsonCount(6, 'data.months') // Oct 2026 … Mar 2027
            ->assertJsonPath('data.months.0.month', '2026-10')
            ->assertJsonPath('data.months.5.month', '2027-03')
            ->assertJsonPath('data.total_due', 1200);

        // Months from the start month can be paid; before it / in the future cannot (no advance payments).
        $this->pay($member, ['2026-10', '2026-11'], 'OLD1', approve: false);
        $this->actingAs($member)->postJson('/api/contributions', [
            'method' => 'cash', 'amount' => 200, 'months' => ['2026-09'],
        ], self::EN)->assertJsonValidationErrors('months');
        $this->actingAs($member)->postJson('/api/contributions', [
            'method' => 'cash', 'amount' => 200, 'months' => ['2027-04'],
        ], self::EN)->assertJsonValidationErrors('months');
    }

    public function test_suspended_months_are_charged_and_re_approval_changes_nothing(): void
    {
        $member = User::factory()->approved()->create(['approved_at' => '2026-10-05']);
        $this->pay($member, ['2026-10'], 'TX1');

        $this->actingAs($this->admin)->postJson("/api/admin/members/{$member->id}/suspend", ['reason' => 'test'], self::EN)->assertOk();
        $this->travelTo('2027-02-10 12:00:00');
        $this->actingAs($this->admin)->postJson("/api/admin/members/{$member->id}/approve", [], self::EN)->assertOk();
        $this->assertSame('2027-02', $member->fresh()->approved_at->format('Y-m'));

        // Nov–Feb (suspended time included) are due; October stays paid.
        $this->actingAs($member->fresh())->getJson('/api/contributions/dues')
            ->assertJsonCount(5, 'data.months')
            ->assertJsonPath('data.months.0.status', 'paid')
            ->assertJsonPath('data.months_due', 4)
            ->assertJsonPath('data.total_due', 800);
    }

    public function test_a_month_paid_before_a_later_start_month_still_shows_but_never_becomes_due(): void
    {
        $member = User::factory()->approved()->create();
        $this->pay($member, ['2026-10'], 'TX1');
        Setting::put(Setting::FUND_START_MONTH, '2026-12');

        $this->actingAs($member)->getJson('/api/contributions/dues')
            ->assertJsonCount(2, 'data.months') // Oct (paid) + Dec (due) — November never existed for dues
            ->assertJsonPath('data.months.0.month', '2026-10')
            ->assertJsonPath('data.months.0.status', 'paid')
            ->assertJsonPath('data.months.1.month', '2026-12')
            ->assertJsonPath('data.total_due', 200);
    }

    public function test_dashboard_money_figures(): void
    {
        ContributionRate::create(['amount' => 300, 'effective_from' => '2026-12-01']); // Oct 200, Nov 200, Dec 300
        $a = User::factory()->approved()->create();
        $b = User::factory()->suspended()->create();
        User::factory()->create(); // pending registration owes nothing
        $this->pay($a, ['2026-10', '2026-11'], 'TX1');             // 400 approved this month
        $this->pay($a, ['2026-12'], 'TX2', approve: false, amount: 300); // waiting → not due, not collected
        $this->travelTo('2027-01-03 12:00:00');                    // January: Jan (300) owed too

        $this->actingAs($this->admin)->getJson('/api/dashboard/summary')
            ->assertJsonPath('data.total_collected', 400)
            ->assertJsonPath('data.fund_balance', 400)
            ->assertJsonPath('data.collected_this_month', 0)   // approved in December
            ->assertJsonPath('data.pending_payments', 1)
            // a: Jan 300; b (suspended): 1000; admin owes nothing → 1300
            ->assertJsonPath('data.outstanding_dues', 1300)
            ->assertJsonPath('data.members_with_dues', 2);

        $this->actingAs($a)->getJson('/api/dashboard/summary')
            ->assertJsonPath('data.scope', 'own')
            ->assertJsonPath('data.total_paid', 400)
            ->assertJsonPath('data.total_pending', 300)
            ->assertJsonPath('data.total_due', 300)
            ->assertJsonPath('data.months_due', 1);

        $this->travelTo('2026-12-20 12:00:00');
        $this->actingAs($this->admin)->getJson('/api/dashboard/summary')->assertJsonPath('data.collected_this_month', 400);
    }

    public function test_programmers_list_payment_columns_filter_and_sort(): void
    {
        $paidUp = User::factory()->approved()->create(['name' => 'Paid Up']);
        $some = User::factory()->approved()->create(['name' => 'Some Paid']);
        User::factory()->create(['name' => 'Pending Reg']);
        $this->pay($paidUp, ['2026-10', '2026-11', '2026-12'], 'TX1');
        $this->pay($some, ['2026-10'], 'TX2');

        $list = $this->actingAs($this->admin)->getJson('/api/admin/members?per_page=50')->assertOk()->json('data');
        $row = fn (string $name) => collect($list)->firstWhere('name', $name);
        $this->assertSame(['total_paid' => 600, 'total_due' => 0, 'months_due' => 0, 'last_paid_month' => '2026-12'], $row('Paid Up')['payments']);
        $this->assertSame(['total_paid' => 200, 'total_due' => 400, 'months_due' => 2, 'last_paid_month' => '2026-10'], $row('Some Paid')['payments']);
        $this->assertNull($row('Admin')['payments']); // management-only: no dues
        $this->assertNull($row('Pending Reg')['payments']);

        $names = fn (string $q) => collect($this->actingAs($this->admin)->getJson("/api/admin/members?{$q}")->json('data'))->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['Some Paid'], $names('dues=with'));
        $this->assertSame(['Paid Up'], $names('dues=without'));
        $this->assertSame(['Admin', 'Some Paid', 'Paid Up', 'Pending Reg'], $names('sort=due'));
        // The filter also works with pagination (count query).
        $this->actingAs($this->admin)->getJson('/api/admin/members?dues=with&per_page=1')
            ->assertJsonPath('meta.total', 1)->assertJsonPath('meta.last_page', 1);
        $this->actingAs($this->admin)->getJson('/api/admin/members?dues=maybe', self::EN)->assertJsonValidationErrors('dues');

        // Committee reads the same columns (read-only, §10).
        $this->actingAs(User::factory()->committee()->create())->getJson('/api/admin/members?dues=with')->assertOk();
    }

    public function test_programmers_list_month_filter_and_counts(): void
    {
        // Now Dec 2026, start month Oct 2026. Who owes: 3 members (admin owes nothing); one pending registration owes nothing.
        $paid = User::factory()->approved()->create(['name' => 'Paid Nov']);
        $waiting = User::factory()->approved()->create(['name' => 'Waiting Nov']);
        $rejected = User::factory()->approved()->create(['name' => 'Rejected Nov']);
        User::factory()->create(['name' => 'Pending Reg']);
        $this->pay($paid, ['2026-10', '2026-11'], 'MF1');
        $this->pay($waiting, ['2026-11'], 'MF2', approve: false);
        $bad = $this->pay($rejected, ['2026-11'], 'MF3', approve: false);
        $this->actingAs($this->admin)->postJson("/api/admin/contributions/{$bad->id}/reject", ['reason' => 'No'], self::EN)->assertOk();

        $res = $this->actingAs($this->admin)->getJson('/api/admin/members?month=2026-11&per_page=50')
            ->assertOk()
            ->assertJsonPath('month_counts', ['month' => '2026-11', 'paid' => 1, 'due' => 1, 'pending' => 1]);
        $status = collect($res->json('data'))->pluck('month_status', 'name');
        $this->assertEquals( // key order = list order, not checked here
            ['Paid Nov' => 'paid', 'Waiting Nov' => 'pending', 'Rejected Nov' => 'due', 'Admin' => null, 'Pending Reg' => null],
            $status->only(['Paid Nov', 'Waiting Nov', 'Rejected Nov', 'Admin', 'Pending Reg'])->all(),
        );

        $names = fn (string $q) => collect($this->actingAs($this->admin)->getJson("/api/admin/members?{$q}")->json('data'))->pluck('name')->all();
        $this->assertSame(['Paid Nov'], $names('month=2026-11&month_status=paid'));
        $this->assertSame(['Waiting Nov'], $names('month=2026-11&month_status=pending'));
        $this->assertEqualsCanonicalizing(['Rejected Nov'], $names('month=2026-11&month_status=due'));
        // Counts follow the other filters too (search).
        $this->actingAs($this->admin)->getJson('/api/admin/members?month=2026-11&search=Nov')
            ->assertJsonPath('month_counts.due', 1)->assertJsonPath('month_counts.paid', 1);

        // Before the start month / in the future nobody is due; an earlier month paid still counts.
        $this->actingAs($this->admin)->getJson('/api/admin/members?month=2026-09')
            ->assertJsonPath('month_counts', ['month' => '2026-09', 'paid' => 0, 'due' => 0, 'pending' => 0]);
        $this->actingAs($this->admin)->getJson('/api/admin/members?month=2027-01')->assertJsonPath('month_counts.due', 0);

        // Without month: no counts, no month_status (unchanged 7d answer); bad values → 422.
        $this->actingAs($this->admin)->getJson('/api/admin/members')
            ->assertJsonPath('month_counts', null)->assertJsonMissingPath('data.0.month_status');
        $this->actingAs($this->admin)->getJson('/api/admin/members?month=2026-13&month_status=late', self::EN)
            ->assertJsonValidationErrors(['month', 'month_status']);

        // Committee may use it (read-only).
        $this->actingAs(User::factory()->committee()->create())->getJson('/api/admin/members?month=2026-11&month_status=due')->assertOk();
    }

    public function test_member_payment_history_and_monthly_summary(): void
    {
        $member = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();
        $c = $this->pay($member, ['2026-10', '2026-11'], 'HIST1');
        $this->pay($member, ['2026-12'], 'HIST2', approve: false);
        $this->pay($other, ['2026-10'], 'HIST3');

        $res = $this->actingAs($this->admin)->getJson("/api/admin/members/{$member->id}/payments")
            ->assertOk()
            ->assertJsonPath('data.owes_dues', true)
            ->assertJsonPath('data.start_month', '2026-10')
            ->assertJsonCount(3, 'data.months')
            ->assertJsonPath('data.months.0.month', '2026-12')      // newest first
            ->assertJsonPath('data.months.0.status', 'pending')
            ->assertJsonPath('data.months.0.payment.approved_at', null)
            ->assertJsonPath('data.months.2.status', 'paid')
            ->assertJsonPath('data.months.2.payment.id', $c->id)
            ->assertJsonPath('data.months.2.payment.method', 'bkash')
            ->assertJsonPath('data.months.2.payment.transaction_id', 'HIST1')
            ->assertJsonPath('data.total_paid', 400)
            ->assertJsonPath('data.total_pending', 200)
            ->assertJsonPath('data.total_due', 0);
        $this->assertNotNull($res->json('data.months.2.payment.approved_at'));

        // An admin account is management-only: nothing owed, no months.
        $this->actingAs($this->admin)->getJson("/api/admin/members/{$this->admin->id}/payments")
            ->assertJsonPath('data.owes_dues', false)->assertJsonCount(0, 'data.months');

        // Pending registration: nothing owed.
        $reg = User::factory()->create();
        $this->actingAs($this->admin)->getJson("/api/admin/members/{$reg->id}/payments")
            ->assertJsonPath('data.owes_dues', false)->assertJsonCount(0, 'data.months');

        // Monthly summary: 2 who owe (member, other), newest first.
        $this->actingAs($this->admin)->getJson('/api/admin/fund/monthly')
            ->assertOk()
            ->assertJsonPath('data.members', 2)
            ->assertJsonCount(3, 'data.months')
            ->assertJsonPath('data.months.0', [
                'month' => '2026-12', 'amount' => 200, 'members' => 2,
                'paid_members' => 0, 'paid_amount' => 0, 'pending_members' => 1, 'pending_amount' => 200,
                'due_members' => 1, 'due_amount' => 200,
            ])
            ->assertJsonPath('data.months.2.month', '2026-10')
            ->assertJsonPath('data.months.2.paid_members', 2)
            ->assertJsonPath('data.months.2.paid_amount', 400)
            ->assertJsonPath('data.months.2.due_members', 0)
            ->assertJsonPath('data.totals.paid_amount', 600);

        // Committee may read both; members and guests may not.
        $committee = User::factory()->committee()->create();
        $this->actingAs($committee)->getJson('/api/admin/fund/monthly')->assertOk();
        $this->actingAs($committee)->getJson("/api/admin/members/{$member->id}/payments")->assertOk();
        $this->actingAs($member)->getJson('/api/admin/fund/monthly', self::EN)->assertForbidden();
        $this->actingAs($member)->getJson("/api/admin/members/{$other->id}/payments", self::EN)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/fund/monthly', self::EN)->assertUnauthorized();
    }

    public function test_history_lists_rejected_attempts_and_screenshot_only_for_admins(): void
    {
        $member = User::factory()->approved()->create();
        $this->actingAs($member)->post('/api/contributions', [
            'method' => 'bkash', 'sender_number' => '01712345678', 'transaction_id' => 'SHOT1',
            'amount' => 200, 'months' => ['2026-10'],
            'proof' => UploadedFile::fake()->image('p.png'),
        ], self::EN)->assertCreated();
        $paid = Contribution::latest('id')->first();
        $this->actingAs($this->admin)->postJson("/api/admin/contributions/{$paid->id}/approve", [], self::EN)->assertOk();
        $bad = $this->pay($member, ['2026-11'], 'BAD1', approve: false);
        $this->actingAs($this->admin)->postJson("/api/admin/contributions/{$bad->id}/reject", ['reason' => 'Wrong TxID'], self::EN)->assertOk();

        $this->actingAs($this->admin)->getJson("/api/admin/members/{$member->id}/payments")
            ->assertOk()
            ->assertJsonPath('data.months.1.month', '2026-11')
            ->assertJsonPath('data.months.1.status', 'due')        // rejected → still due
            ->assertJsonPath('data.months.1.rejected.0.id', $bad->id)
            ->assertJsonPath('data.months.1.rejected.0.transaction_id', 'BAD1')
            ->assertJsonPath('data.months.1.rejected.0.reason', 'Wrong TxID')
            ->assertJsonPath('data.months.2.status', 'paid')
            ->assertJsonPath('data.months.2.rejected', [])
            ->assertJsonPath('data.months.2.payment.proof_url', url("/api/contributions/{$paid->id}/proof"))
            ->assertJsonPath('data.months.0.rejected', [])
            ->assertJsonPath('data.total_due', 400)
            ->assertJsonPath('data.months_due', 2);

        // Committee: same months and rejected note, but no screenshot link and no reason.
        $this->actingAs(User::factory()->committee()->create())->getJson("/api/admin/members/{$member->id}/payments")
            ->assertOk()
            ->assertJsonPath('data.months.1.rejected.0.id', $bad->id)
            ->assertJsonPath('data.months.1.rejected.0.reason', null)
            ->assertJsonPath('data.months.2.payment.proof_url', null);
    }
}
