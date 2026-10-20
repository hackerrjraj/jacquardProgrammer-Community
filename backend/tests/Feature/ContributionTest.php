<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicHomeController;
use App\Models\AuditLog;
use App\Models\Contribution;
use App\Models\ContributionRate;
use App\Models\LedgerEntry;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DemoProgrammersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

class ContributionTest extends TestCase
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
        $this->travelTo('2026-10-15 12:00:00');
        // Fund start month August (Phase 7d rule) → every member owes Aug, Sep, Oct (200 each).
        Setting::put(Setting::FUND_START_MONTH, '2026-08');
        $this->member = User::factory()->approved()->create(['approved_at' => '2026-08-10 09:00:00']);
        $this->admin = User::factory()->admin()->create();
    }

    private function submit(array $fields = [], ?User $user = null, array $headers = self::EN)
    {
        return $this->actingAs($user ?? $this->member)->post('/api/contributions', [
            'method' => 'bkash',
            'sender_number' => '01712345678',
            'transaction_id' => 'ABC123XYZ',
            'amount' => 400,
            'months' => ['2026-08', '2026-09'],
            ...$fields,
        ], $headers);
    }

    private function approve(Contribution|int $c, ?User $admin = null)
    {
        $id = $c instanceof Contribution ? $c->id : $c;

        return $this->actingAs($admin ?? $this->admin)->postJson("/api/admin/contributions/{$id}/approve", [], self::EN);
    }

    private function reject(Contribution|int $c, array $body = ['reason' => 'TxID not found in bKash statement'], ?User $admin = null)
    {
        $id = $c instanceof Contribution ? $c->id : $c;

        return $this->actingAs($admin ?? $this->admin)->postJson("/api/admin/contributions/{$id}/reject", $body, self::EN);
    }

    public function test_member_sees_dues_from_the_fund_start_month(): void
    {
        // Super Admin raised the amount from October (future months only, §25).
        ContributionRate::create(['amount' => 300, 'effective_from' => '2026-10-01']);
        // Approved in October — still owes from the fund start month (August).
        $this->member->forceFill(['approved_at' => '2026-10-02 09:00:00'])->save();

        $this->actingAs($this->member)->getJson('/api/contributions/dues')
            ->assertOk()
            ->assertJsonCount(3, 'data.months')
            ->assertJsonPath('data.months.0', ['month' => '2026-08', 'amount' => 200, 'status' => 'due', 'contribution_id' => null])
            ->assertJsonPath('data.months.2.amount', 300)
            ->assertJsonPath('data.total_due', 700)
            ->assertJsonPath('data.months_due', 3)
            ->assertJsonPath('data.total_paid', 0);
    }

    public function test_member_submits_a_payment_with_screenshot(): void
    {
        $response = $this->submit([
            'sender_number' => '+880 1712-345678',
            'transaction_id' => ' abc 123xyz ',
            'proof' => UploadedFile::fake()->image('bkash.png', 400, 800)->size(300),
        ])->assertCreated()
            ->assertJsonPath('message', __('messages.contribution_submitted', [], 'en'))
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount', 400)
            ->assertJsonPath('data.sender_number', '01712345678')
            ->assertJsonPath('data.transaction_id', 'ABC123XYZ')
            ->assertJsonPath('data.months', [['month' => '2026-08', 'amount' => 200], ['month' => '2026-09', 'amount' => 200]]);

        $contribution = Contribution::sole();
        $this->assertSame($this->member->id, $contribution->user_id);
        $this->assertStringStartsWith('contributions/', $contribution->proof_path);
        $this->assertStringNotContainsString('bkash', $contribution->proof_path);
        Storage::disk('private')->assertExists($contribution->proof_path);
        $this->assertStringEndsWith("/api/contributions/{$contribution->id}/proof", $response->json('data.proof_url'));

        // Pending payments are not money in the fund yet.
        $this->assertSame(0, LedgerEntry::count());

        $this->actingAs($this->member)->getJson('/api/contributions/dues')
            ->assertJsonPath('data.months.0.status', 'pending')
            ->assertJsonPath('data.months.1.status', 'pending')
            ->assertJsonPath('data.months.2.status', 'due')
            ->assertJsonPath('data.total_pending', 400)
            ->assertJsonPath('data.total_due', 200);

        $this->actingAs($this->member)->getJson('/api/contributions')
            ->assertOk()
            ->assertJsonPath('data.0.id', $contribution->id)
            ->assertJsonMissingPath('data.0.member');

        $log = AuditLog::where('action', 'contribution.submitted')->sole();
        $this->assertSame($this->member->id, $log->actor_id);
        $this->assertSame(['2026-08', '2026-09'], $log->details['months']);
    }

    public function test_cash_needs_no_transaction_id_or_sender_number(): void
    {
        $this->submit(['method' => 'cash', 'sender_number' => '', 'transaction_id' => '', 'amount' => 200, 'months' => ['2026-10']])
            ->assertCreated()
            ->assertJsonPath('data.transaction_id', null)
            ->assertJsonPath('data.sender_number', null);

        // Bank: TxID needed, sender number not.
        $this->submit(['method' => 'bank', 'sender_number' => '', 'transaction_id' => 'DBBL-77/1', 'amount' => 200, 'months' => ['2026-09']])
            ->assertCreated();
    }

    public function test_submission_validation(): void
    {
        $this->actingAs($this->member)->post('/api/contributions', [], self::EN)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['method', 'amount', 'months', 'transaction_id']);

        $this->submit(['method' => 'paypal'])->assertJsonValidationErrors('method');
        $this->submit(['sender_number' => ''])->assertJsonValidationErrors('sender_number');
        $this->submit(['sender_number' => '12345'])->assertJsonValidationErrors('sender_number');
        $this->submit(['method' => 'nagad', 'transaction_id' => ''])->assertJsonValidationErrors('transaction_id');
        $this->submit(['transaction_id' => 'ABC;DROP'])->assertJsonValidationErrors('transaction_id');
        $this->submit(['amount' => 'abc'])->assertJsonValidationErrors('amount');
        $this->submit(['amount' => 200.5])->assertJsonValidationErrors('amount');
        $this->submit(['amount' => 0])->assertJsonValidationErrors('amount');
        $this->submit(['months' => ['10-2026']])->assertJsonValidationErrors('months.0');
        $this->submit(['months' => ['2026-08', '2026-08']])->assertJsonValidationErrors('months.0');
        $this->submit(['proof' => UploadedFile::fake()->image('big.jpg')->size(2049)])->assertJsonValidationErrors('proof');
        $this->submit(['proof' => UploadedFile::fake()->create('slip.pdf', 100, 'application/pdf')])->assertJsonValidationErrors('proof');

        // Amount must equal the dues of the chosen months exactly (§26 default).
        $this->submit(['amount' => 300])
            ->assertJsonValidationErrors(['amount' => __('messages.contribution_amount_mismatch', ['total' => 400], 'en')]);

        // Only unpaid months between the fund start month and now.
        $this->submit(['amount' => 200, 'months' => ['2026-07']])->assertJsonValidationErrors('months');  // before the start month
        $this->submit(['amount' => 200, 'months' => ['2026-11']])->assertJsonValidationErrors('months');  // future

        // Bengali message.
        $this->submit(['amount' => 300], null, self::BN)
            ->assertJsonValidationErrors(['amount' => __('messages.contribution_amount_mismatch', ['total' => 400], 'bn')]);

        $this->assertSame(0, Contribution::count());
        Storage::disk('private')->assertDirectoryEmpty('/');
    }

    public function test_a_month_cannot_be_submitted_twice(): void
    {
        $this->submit()->assertCreated();

        $this->submit(['transaction_id' => 'OTHER1', 'amount' => 200, 'months' => ['2026-09']])
            ->assertJsonValidationErrors(['months' => __('messages.contribution_month_not_payable', ['months' => '2026-09'], 'en')]);
    }

    public function test_duplicate_transaction_id_is_refused(): void
    {
        $this->submit()->assertCreated();
        $other = User::factory()->approved()->create(['approved_at' => '2026-10-01']);

        // Same method + TxID (other case / spaces, other member) → refused.
        $this->submit(['transaction_id' => 'abc123 xyz', 'amount' => 200, 'months' => ['2026-10']], $other)
            ->assertJsonValidationErrors(['transaction_id' => __('messages.contribution_duplicate_txid', [], 'en')]);
        // Same TxID on another method is a different transaction (§26: unique per method).
        $this->submit(['method' => 'nagad', 'amount' => 200, 'months' => ['2026-10']], $other)->assertCreated();

        // A rejected payment frees its TxID and months, so the member can send it again correctly.
        $this->reject(Contribution::where('method', 'bkash')->sole())->assertOk();
        $this->submit()->assertCreated();
        $this->assertSame(3, Contribution::count());
    }

    public function test_only_approved_logged_in_accounts_can_submit(): void
    {
        $this->postJson('/api/contributions', [], self::EN)->assertUnauthorized();
        $this->getJson('/api/contributions/dues', self::EN)->assertUnauthorized();

        $pending = User::factory()->create();
        $this->submit([], $pending)->assertForbidden();

        // Committee / admin accounts are members too (§10).
        $committee = User::factory()->committee()->create(['approved_at' => '2026-10-01']);
        $this->submit(['amount' => 200, 'months' => ['2026-10']], $committee)->assertCreated();
    }

    public function test_members_and_committee_cannot_review_payments(): void
    {
        $this->submit()->assertCreated();
        $c = Contribution::sole();
        $committee = User::factory()->committee()->create();

        foreach ([$this->member, $committee] as $user) {
            $this->actingAs($user)->getJson('/api/admin/contributions', self::EN)->assertForbidden();
            $this->actingAs($user)->getJson("/api/admin/contributions/{$c->id}", self::EN)->assertForbidden();
            $this->approve($c, $user)->assertForbidden();
            $this->reject($c, ['reason' => 'x'], $user)->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/admin/contributions/{$c->id}/approve", [], self::EN)->assertUnauthorized();

        $this->assertSame(Contribution::STATUS_PENDING, $c->fresh()->status);
        $this->assertSame(0, LedgerEntry::count());
        $this->assertSame(0, AuditLog::where('action', 'like', 'contribution.%')->where('action', '!=', 'contribution.submitted')->count());
    }

    public function test_admin_approves_a_payment(): void
    {
        $this->submit()->assertCreated();
        $c = Contribution::sole();

        $this->approve($c)
            ->assertOk()
            ->assertJsonPath('message', __('messages.contribution_approved', [], 'en'))
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.member.id', $this->member->id)
            ->assertJsonPath('data.reviewed_by.id', $this->admin->id);

        $c->refresh();
        $this->assertSame($this->admin->id, $c->reviewed_by);
        $this->assertNotNull($c->reviewed_at);

        // Only a verified payment creates a ledger entry (§27).
        $entry = LedgerEntry::sole();
        $this->assertSame(LedgerEntry::TYPE_CONTRIBUTION, $entry->type);
        $this->assertSame(400, $entry->amount);
        $this->assertSame($c->id, $entry->contribution_id);
        $this->assertSame($this->member->id, $entry->user_id);

        // Months are now paid.
        $this->actingAs($this->member)->getJson('/api/contributions/dues')
            ->assertJsonPath('data.months.0.status', 'paid')
            ->assertJsonPath('data.months.1.status', 'paid')
            ->assertJsonPath('data.total_paid', 400)
            ->assertJsonPath('data.total_due', 200);

        $log = AuditLog::where('action', 'contribution.approved')->sole();
        $this->assertSame($this->admin->id, $log->actor_id);
        $this->assertSame('Contribution', $log->target_type);
        $this->assertSame($c->id, $log->target_id);
        $this->assertSame(400, $log->details['amount']);
        $this->assertSame('ABC123XYZ', $log->details['transaction_id']);
        $this->assertSame(['2026-08', '2026-09'], $log->details['months']);

        // A decided payment cannot be decided again.
        $this->approve($c)->assertUnprocessable()->assertJsonPath('message', __('messages.contribution_not_pending', [], 'en'));
        $this->reject($c)->assertUnprocessable();
        $this->assertSame(1, LedgerEntry::count());
        $this->assertSame(1, AuditLog::where('action', 'contribution.approved')->count());

        // The ledger is append-only (§34).
        $this->expectException(LogicException::class);
        $entry->update(['amount' => 1]);
    }

    public function test_admin_rejects_a_payment_with_a_reason(): void
    {
        $this->submit()->assertCreated();
        $c = Contribution::sole();

        $this->reject($c, ['reason' => ''])->assertJsonValidationErrors('reason');
        $this->reject($c, ['reason' => str_repeat('x', 501)])->assertJsonValidationErrors('reason');

        $this->reject($c, ['reason' => '  Amount not received  '])
            ->assertOk()
            ->assertJsonPath('message', __('messages.contribution_rejected', [], 'en'))
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Amount not received');

        $this->assertSame(0, LedgerEntry::count());
        // Rejected payments stay in history (§27) and the months are owed again.
        $this->actingAs($this->member)->getJson('/api/contributions')->assertJsonPath('data.0.status', 'rejected');
        $this->actingAs($this->member)->getJson('/api/contributions/dues')
            ->assertJsonPath('data.months.0.status', 'due')
            ->assertJsonPath('data.total_due', 600);

        $log = AuditLog::where('action', 'contribution.rejected')->sole();
        $this->assertSame($this->admin->id, $log->actor_id);
        $this->assertSame('Amount not received', $log->details['reason']);
        $this->assertSame($c->id, $log->details['contribution_id']);

        $this->approve($c)->assertUnprocessable();
    }

    public function test_admin_and_super_admin_have_no_member_payment_pages_or_api(): void
    {
        // Owner decision 2026-10-20: management-only accounts never pay dues (they use a separate member account).
        foreach ([$this->admin, User::factory()->superAdmin()->create()] as $manager) {
            $this->actingAs($manager)->getJson('/api/contributions', ['Accept-Language' => 'en'])->assertForbidden();
            $this->actingAs($manager)->getJson('/api/contributions/dues')->assertForbidden();
            $this->actingAs($manager)->getJson('/api/contributions/accounts')->assertForbidden();
            $this->submit(['amount' => 200, 'months' => ['2026-10']], $manager)->assertForbidden();
        }
        $this->assertSame(0, Contribution::count());

        // Members and committee still can; the admin verification queue is unchanged.
        $this->actingAs($this->member)->getJson('/api/contributions/dues')->assertOk();
        $this->actingAs($this->admin)->getJson('/api/admin/contributions')->assertOk();
    }

    public function test_admin_lists_and_filters_payments(): void
    {
        $this->submit()->assertCreated();                                                   // #1 pending
        $this->submit(['transaction_id' => 'T2', 'amount' => 200, 'months' => ['2026-10']])->assertCreated(); // #2 pending
        $other = User::factory()->approved()->create(['name' => 'Karim Uddin', 'approved_at' => '2026-10-01']);
        $this->submit(['method' => 'nagad', 'transaction_id' => 'NG9', 'amount' => 200, 'months' => ['2026-10']], $other)->assertCreated(); // #3
        [$first, $second, $third] = Contribution::orderBy('id')->get()->all();
        $this->reject($third)->assertOk();

        // Pending queue: oldest first, with the member shown.
        $this->actingAs($this->admin)->getJson('/api/admin/contributions?status=pending')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.1.id', $second->id)
            ->assertJsonPath('data.0.member.name', $this->member->name);

        // All: newest first.
        $this->actingAs($this->admin)->getJson('/api/admin/contributions')
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $third->id);

        $this->actingAs($this->admin)->getJson('/api/admin/contributions?status=rejected')->assertJsonCount(1, 'data');
        $this->actingAs($this->admin)->getJson('/api/admin/contributions?method=nagad')->assertJsonPath('data.0.id', $third->id);
        $this->actingAs($this->admin)->getJson('/api/admin/contributions?search=karim')->assertJsonCount(1, 'data');
        $this->actingAs($this->admin)->getJson('/api/admin/contributions?search=abc123')->assertJsonPath('data.0.id', $first->id);
        $this->actingAs($this->admin)->getJson("/api/admin/contributions?member={$other->id}")->assertJsonCount(1, 'data');
        $this->actingAs($this->admin)->getJson('/api/admin/contributions?status=paid', self::EN)->assertJsonValidationErrors('status');

        $this->actingAs($this->admin)->getJson("/api/admin/contributions/{$first->id}")->assertOk()->assertJsonPath('data.amount', 400);
        $this->actingAs($this->admin)->getJson('/api/admin/contributions/9999')->assertNotFound();
    }

    public function test_admin_list_date_range_sort_and_member_photo(): void
    {
        $this->member->forceFill(['photo_path' => 'photos/m.jpg'])->save();
        $this->travelTo('2026-10-01 10:00:00');
        $this->submit()->assertCreated();                                                                     // #1
        $this->travelTo('2026-10-15 12:00:00');
        $this->submit(['transaction_id' => 'T2', 'amount' => 200, 'months' => ['2026-10']])->assertCreated(); // #2
        [$first, $second] = Contribution::orderBy('id')->get()->all();

        // Pending newest first on request (default stays oldest first).
        $this->actingAs($this->admin)->getJson('/api/admin/contributions?status=pending&sort=newest')
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.0.member.photo_url', Storage::disk('public')->url('photos/m.jpg'));
        $this->actingAs($this->admin)->getJson('/api/admin/contributions?status=pending')->assertJsonPath('data.0.id', $first->id);

        // Date range: both days included.
        $this->actingAs($this->admin)->getJson('/api/admin/contributions?from=2026-10-01&to=2026-10-01')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->id);
        $this->actingAs($this->admin)->getJson('/api/admin/contributions?from=2026-10-02')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second->id);
        $this->actingAs($this->admin)->getJson('/api/admin/contributions?to=2026-10-15')->assertJsonCount(2, 'data');
        $this->actingAs($this->admin)->getJson('/api/admin/contributions?from=2026-10-10&to=2026-10-01', self::EN)
            ->assertJsonValidationErrors('to');
        $this->actingAs($this->admin)->getJson('/api/admin/contributions?from=01-10-2026&sort=x', self::EN)
            ->assertJsonValidationErrors(['from', 'sort']);
    }

    public function test_screenshot_is_private(): void
    {
        $this->submit(['proof' => UploadedFile::fake()->image('slip.jpg')])->assertCreated();
        $c = Contribution::sole();
        $url = "/api/contributions/{$c->id}/proof";

        $this->actingAs($this->member)->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($this->admin)->get($url)->assertOk();
        $this->actingAs(User::factory()->approved()->create())->getJson($url)->assertForbidden();
        $this->actingAs(User::factory()->committee()->create())->getJson($url)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson($url)->assertUnauthorized();
    }

    public function test_fund_report_counts_only_approved_payments(): void
    {
        $viewer = User::factory()->approved()->create();
        $this->actingAs($viewer)->getJson('/api/fund-report')->assertJsonPath('data.total_collected', 0);

        $other = User::factory()->approved()->create(['approved_at' => '2026-10-01']);
        $demo = User::factory()->approved()->create(['approved_at' => '2026-10-01', 'email' => 'demo9@'.DemoProgrammersSeeder::EMAIL_DOMAIN]);
        $toSuspend = User::factory()->approved()->create(['approved_at' => '2026-10-01']);

        $this->submit()->assertCreated();                                                                   // 400 → approved
        $this->submit(['transaction_id' => 'P1', 'amount' => 200, 'months' => ['2026-10']])->assertCreated(); // 200 → stays pending
        $this->submit(['transaction_id' => 'R1', 'amount' => 200, 'months' => ['2026-10']], $other)->assertCreated(); // 200 → rejected
        $this->submit(['transaction_id' => 'D1', 'amount' => 200, 'months' => ['2026-10']], $demo)->assertCreated();  // demo → approved
        $this->submit(['transaction_id' => 'S1', 'amount' => 200, 'months' => ['2026-10']], $toSuspend)->assertCreated(); // → approved, then suspended

        $byTx = fn (string $tx) => Contribution::where('transaction_id', $tx)->sole();
        $this->approve($byTx('ABC123XYZ'))->assertOk();
        $this->reject($byTx('R1'))->assertOk();
        $this->approve($byTx('D1'))->assertOk();
        $this->approve($byTx('S1'))->assertOk();
        $toSuspend->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        // Counted: 400 (member) + 200 (member suspended later — the money is in the fund). Not: pending, rejected, demo.
        $this->actingAs($viewer)->getJson('/api/fund-report')
            ->assertJsonPath('data.total_collected', 600)
            ->assertJsonPath('data.fund_balance', 600)
            ->assertJsonPath('data.total_disbursed', 0);

        // Report total = the fund ledger total without the demo programmers.
        $ledgerWithoutDemo = LedgerEntry::where('type', LedgerEntry::TYPE_CONTRIBUTION)->where('user_id', '!=', $demo->id)->sum('amount');
        $this->assertSame(600, (int) $ledgerWithoutDemo);
        $this->assertSame(800, (int) LedgerEntry::sum('amount'));

        // The report is live (no cache): approving shows at once.
        $this->approve($byTx('P1'))->assertOk();
        $this->actingAs($viewer)->getJson('/api/fund-report')->assertJsonPath('data.total_collected', 800);
    }
}
