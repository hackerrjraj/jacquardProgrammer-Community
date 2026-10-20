<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicHomeController;
use App\Models\AssistanceRequest;
use App\Models\AuditLog;
use App\Models\Contribution;
use App\Models\LedgerEntry;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/** Ledger corrections, paid-status integrity and the integrity check (MASTER_SPEC §34, Phase 12b). */
class LedgerCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private const EN = ['Accept' => 'application/json', 'Accept-Language' => 'en'];

    private User $super;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-15 12:00:00');
        Setting::put(Setting::FUND_START_MONTH, '2026-10');
        $this->super = User::factory()->superAdmin()->create();
        $this->member = User::factory()->approved()->create();
    }

    /** A real approved payment, the way the admin approval creates it (entry first, then status). */
    private function payment(int $amount): LedgerEntry
    {
        return DB::transaction(function () use ($amount) {
            $c = Contribution::create(['user_id' => $this->member->id, 'method' => 'cash', 'amount' => $amount]);
            $entry = LedgerEntry::create([
                'type' => LedgerEntry::TYPE_CONTRIBUTION, 'amount' => $amount, 'user_id' => $this->member->id,
                'contribution_id' => $c->id, 'entry_date' => '2026-10-01',
            ]);
            $c->status = Contribution::STATUS_APPROVED;
            $c->save();

            return $entry;
        });
    }

    private function adjust(string $direction, int $amount, ?string $reason = 'Bank charge', ?User $by = null)
    {
        return $this->actingAs($by ?? $this->super)->postJson('/api/admin/ledger/adjustments', array_filter([
            'direction' => $direction, 'amount' => $amount, 'reason' => $reason,
        ], fn ($v) => $v !== null), self::EN);
    }

    private function reverse(int $entryId, ?string $reason = 'Entered twice', ?User $by = null)
    {
        return $this->actingAs($by ?? $this->super)->postJson("/api/admin/ledger/{$entryId}/reverse", array_filter(['reason' => $reason]), self::EN);
    }

    public function test_only_super_admin_can_see_and_correct_the_ledger(): void
    {
        $entry = $this->payment(1000);
        $others = [
            User::factory()->admin()->create(),
            User::factory()->committee()->create(),
            $this->member, // a programmer (plain member)
        ];

        foreach ($others as $user) {
            $this->actingAs($user)->getJson('/api/admin/ledger', self::EN)->assertForbidden();
            $this->actingAs($user)->getJson('/api/admin/ledger/integrity', self::EN)->assertForbidden();
            $this->adjust('in', 100, 'Test', $user)->assertForbidden();
            $this->reverse($entry->id, 'Test', $user)->assertForbidden();
        }
        $this->assertSame(1, LedgerEntry::count());

        $this->actingAs($this->super)->getJson('/api/admin/ledger', self::EN)
            ->assertOk()
            ->assertJsonPath('meta.balance', 1000)
            ->assertJsonPath('data.0.can_reverse', true);
    }

    public function test_reason_is_required(): void
    {
        $entry = $this->payment(1000);

        $this->adjust('in', 100, null)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->adjust('in', 100, '  ')->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->adjust('sideways', 100)->assertUnprocessable()->assertJsonValidationErrors('direction');
        $this->adjust('in', 0)->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->reverse($entry->id, null)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame(1, LedgerEntry::count());

        // The model refuses it too (no other code path can skip the reason).
        $this->expectException(LogicException::class);
        LedgerEntry::create(['type' => LedgerEntry::TYPE_ADJUSTMENT, 'amount' => 5, 'entry_date' => '2026-10-15']);
    }

    public function test_adjustments_and_reversals_change_the_balance_everywhere(): void
    {
        $entry = $this->payment(5000);

        $this->adjust('in', 700, 'Bank interest')->assertCreated()
            ->assertJsonPath('meta.balance', 5700)
            ->assertJsonPath('data.effect', 700)
            ->assertJsonPath('message', 'Adjustment saved. New fund balance: 5,700 taka.');
        $this->adjust('out', 200, 'Bank charge')->assertCreated()->assertJsonPath('meta.balance', 5500);
        $this->reverse($entry->id)->assertCreated()
            ->assertJsonPath('data.effect', -5000)
            ->assertJsonPath('data.reverses.id', $entry->id)
            ->assertJsonPath('meta.balance', 500)
            ->assertJsonPath('meta.corrections', -4500);

        // Existing entry untouched; corrections are new rows.
        $this->assertSame(5000, $entry->fresh()->amount);
        $this->assertSame(4, LedgerEntry::count());

        $this->actingAs($this->super)->getJson('/api/fund-report')
            ->assertJsonPath('data.total_collected', 5000)
            ->assertJsonPath('data.corrections', -4500)
            ->assertJsonPath('data.fund_balance', 500);
        $this->actingAs($this->super)->getJson('/api/dashboard/summary')->assertJsonPath('data.fund_balance', 500);

        // The public report shows only totals — never the reasons or who made the correction.
        $report = $this->actingAs($this->super)->getJson('/api/fund-report')->getContent();
        foreach (['Bank interest', 'Bank charge', 'Entered twice', $this->super->name] as $secret) {
            $this->assertStringNotContainsString($secret, $report);
        }

        // Every correction is in the audit log with old / new balance.
        $log = AuditLog::where('action', 'ledger.reversal')->firstOrFail();
        $this->assertSame($this->super->id, $log->actor_id);
        $this->assertSame(['old' => 5500, 'new' => 500], $log->details['balance']);
        $this->assertSame('Entered twice', $log->details['reason']);
        $this->assertSame(2, AuditLog::where('action', 'ledger.adjustment')->count());
    }

    public function test_an_entry_is_reversed_only_once_and_a_reversal_never(): void
    {
        $entry = $this->payment(1000);
        $reversalId = $this->reverse($entry->id)->assertCreated()->json('data.id');

        $this->reverse($entry->id)->assertUnprocessable()->assertJsonPath('message', 'This entry has already been reversed.');
        $this->reverse($reversalId)->assertUnprocessable()->assertJsonPath('message', 'A reversal cannot be reversed.');
        $this->assertSame(2, LedgerEntry::count());

        $this->actingAs($this->super)->getJson('/api/admin/ledger', self::EN)
            ->assertJsonPath('data.0.can_reverse', false)  // the reversal
            ->assertJsonPath('data.1.can_reverse', false)  // the reversed payment
            ->assertJsonPath('data.1.reversed_by', $reversalId);

        // The model refuses a second reversal even without the controller.
        $this->expectException(LogicException::class);
        LedgerEntry::create(['type' => LedgerEntry::TYPE_REVERSAL, 'amount' => 1000, 'reverses_id' => $entry->id, 'reason' => 'x', 'entry_date' => '2026-10-15']);
    }

    public function test_a_correction_cannot_take_the_fund_below_zero(): void
    {
        $this->payment(300);

        $this->adjust('out', 301)->assertUnprocessable()->assertJsonPath('message', 'Not allowed: the fund balance would become -1 taka (below zero).');
        $this->adjust('out', 300)->assertCreated()->assertJsonPath('meta.balance', 0);
    }

    public function test_paid_status_is_impossible_without_a_payout(): void
    {
        $r = AssistanceRequest::create([
            'user_id' => $this->member->id, 'reason_type' => 'medical', 'description' => 'Bill',
            'amount_requested' => 500, 'amount_approved' => 500, 'status' => AssistanceRequest::STATUS_APPROVED,
        ]);

        try {
            DB::transaction(fn () => $r->forceFill(['status' => AssistanceRequest::STATUS_PAID])->save());
            $this->fail('A case became paid without its ledger payout.');
        } catch (LogicException) {
        }
        $this->assertSame('approved', $r->fresh()->status);

        // Creating a case directly as "paid" is refused as well.
        $this->expectException(LogicException::class);
        AssistanceRequest::create([
            'user_id' => User::factory()->approved()->create()->id, 'reason_type' => 'medical', 'description' => 'Bill',
            'amount_requested' => 500, 'status' => AssistanceRequest::STATUS_PAID,
        ]);
    }

    public function test_approved_payment_is_impossible_without_its_ledger_entry(): void
    {
        $c = Contribution::create(['user_id' => $this->member->id, 'method' => 'cash', 'amount' => 200]);

        $this->expectException(LogicException::class);
        DB::transaction(fn () => $c->forceFill(['status' => Contribution::STATUS_APPROVED])->save());
    }

    public function test_integrity_check_lists_mismatches_without_fixing_them(): void
    {
        $this->payment(20000);
        $this->actingAs($this->super)->getJson('/api/admin/ledger/integrity')
            ->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.issues', []);

        // Seeded mismatches: a case set to "paid" by hand (like test case JPPHAF-AR-2026-0001) and a payout without a case.
        $r = AssistanceRequest::create([
            'user_id' => $this->member->id, 'reason_type' => 'medical', 'description' => 'Bill',
            'amount_requested' => 15000, 'amount_approved' => 15000, 'case_id' => 'JPPHAF-AR-2026-0001',
        ]);
        DB::table('assistance_requests')->where('id', $r->id)->update(['status' => AssistanceRequest::STATUS_PAID]);
        $orphan = LedgerEntry::create(['type' => LedgerEntry::TYPE_DISBURSEMENT, 'amount' => 900, 'user_id' => $this->member->id, 'entry_date' => '2026-10-10']);
        $before = [LedgerEntry::count(), AssistanceRequest::where('status', 'paid')->count()];

        $response = $this->actingAs($this->super)->getJson('/api/admin/ledger/integrity')
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.balance', 19100);
        $codes = collect($response->json('data.issues'))->keyBy('code');
        $this->assertSame('JPPHAF-AR-2026-0001', $codes['paid_case_without_payout']['case_id']);
        $this->assertSame($orphan->id, $codes['payout_without_case']['entry_id']);
        $this->assertArrayNotHasKey('balance_mismatch', $codes->all());

        // Nothing was changed by the check.
        $this->assertSame($before, [LedgerEntry::count(), AssistanceRequest::where('status', 'paid')->count()]);
        $this->artisan('fund:check')->assertFailed();
    }
}
