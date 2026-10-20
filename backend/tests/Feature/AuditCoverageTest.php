<?php

namespace Tests\Feature;

use App\Models\AssistanceRequest;
use App\Models\AuditLog;
use App\Models\Contribution;
use App\Models\ContributionRate;
use App\Models\LedgerEntry;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Audit-log coverage of MASTER_SPEC §34 (Phase 12b): every financial and approval action listed there is done once
 * through the real API, and each must leave its audit entry (who, action, target, IP). Fails when an action stops
 * being logged, or when an action has no readable name on the Audit Log page (bn + en).
 */
class AuditCoverageTest extends TestCase
{
    use RefreshDatabase;

    private const EN = ['Accept' => 'application/json', 'Accept-Language' => 'en'];

    /** §34 list → the audit action that records it. */
    private const SECTION_34 = [
        'registration approve' => 'member.approved',
        'registration reject' => 'member.rejected',
        'role change' => 'member.role_changed',
        'status change (suspend)' => 'member.suspended',
        'payment verify' => 'contribution.approved',
        'payment reject' => 'contribution.rejected',
        'assistance submitted' => 'assistance.submitted',
        'assistance cancelled' => 'assistance.cancelled',
        'assistance approved (+ approved amount)' => 'assistance.approved',
        'assistance rejected' => 'assistance.rejected',
        'assistance paid (disbursement)' => 'assistance.paid',
        'each vote' => 'assistance.voted',
        'adjustment' => 'ledger.adjustment',
        'reversal' => 'ledger.reversal',
        'settings change: monthly amount' => 'settings.rate_scheduled',
        'settings change: monthly amount cancelled' => 'settings.rate_cancelled',
        'settings change: receiving accounts' => 'settings.accounts_changed',
        'settings change: fund start month' => 'settings.start_month_changed',
        'settings change: site settings' => 'site_settings.updated',
    ];

    public function test_every_section_34_action_is_audit_logged(): void
    {
        Storage::fake('private');
        $this->travelTo('2026-10-15 12:00:00');
        Setting::put(Setting::FUND_START_MONTH, '2026-10');
        $super = User::factory()->superAdmin()->create();
        $admin = User::factory()->admin()->create();
        $member = User::factory()->approved()->create();
        $voter = User::factory()->approved()->create();

        // Registration approve / reject, role change, status change.
        $this->actingAs($admin)->postJson('/api/admin/members/'.User::factory()->create()->id.'/approve', [], self::EN)->assertOk();
        $this->actingAs($admin)->postJson('/api/admin/members/'.User::factory()->create()->id.'/reject', ['reason' => 'Unknown person'], self::EN)->assertOk();
        $this->actingAs($super)->postJson("/api/admin/members/{$voter->id}/role", ['role' => 'committee'], self::EN)->assertOk();
        $this->actingAs($admin)->postJson('/api/admin/members/'.User::factory()->approved()->create()->id.'/suspend', [], self::EN)->assertOk();

        // Payment verify / reject.
        $pay = function (string $txid) use ($member) {
            $this->actingAs($member)->postJson('/api/contributions', [
                'method' => 'bkash', 'sender_number' => '01712345678', 'transaction_id' => $txid, 'amount' => 200, 'months' => ['2026-10'],
            ], self::EN)->assertCreated();

            return Contribution::latest('id')->first();
        };
        $wrong = $pay('TX1');
        $this->actingAs($admin)->postJson("/api/admin/contributions/{$wrong->id}/reject", ['reason' => 'Wrong TxID'], self::EN)->assertOk();
        $right = $pay('TX2');
        $this->actingAs($admin)->postJson("/api/admin/contributions/{$right->id}/approve", [], self::EN)->assertOk();

        // Assistance: submitted, vote, cancelled, rejected, approved, paid.
        $apply = function (User $who) {
            $this->actingAs($who)->post('/api/assistance', ['reason_type' => 'medical', 'description' => 'Bill', 'amount_requested' => 100], self::EN)->assertCreated();

            return AssistanceRequest::latest('id')->first();
        };
        $a = $apply($member);
        $this->actingAs($voter)->postJson("/api/assistance/{$a->id}/vote", ['vote' => 'yes'], self::EN)->assertOk();
        $this->actingAs($member)->postJson("/api/assistance/{$a->id}/cancel", [], self::EN)->assertOk();
        $b = $apply($member);
        $this->actingAs($admin)->postJson("/api/admin/assistance/{$b->id}/reject", ['reason' => 'No proof'], self::EN)->assertOk();
        $c = $apply($member);
        $this->actingAs($admin)->postJson("/api/admin/assistance/{$c->id}/approve", ['amount_approved' => 150], self::EN)->assertOk();
        $this->actingAs($admin)->post("/api/admin/assistance/{$c->id}/pay", ['method' => 'cash', 'payout_date' => '2026-10-15'], self::EN)->assertOk();

        // Adjustment + reversal.
        $this->actingAs($super)->postJson('/api/admin/ledger/adjustments', ['direction' => 'in', 'amount' => 50, 'reason' => 'Bank interest'], self::EN)->assertCreated();
        $payout = LedgerEntry::where('type', LedgerEntry::TYPE_DISBURSEMENT)->firstOrFail();
        $this->actingAs($super)->postJson("/api/admin/ledger/{$payout->id}/reverse", ['reason' => 'Payout returned'], self::EN)->assertCreated();

        // Settings changes.
        $this->actingAs($super)->postJson('/api/admin/settings/rates', ['amount' => 250, 'effective_from' => '2026-12'], self::EN)->assertOk();
        $rate = ContributionRate::where('amount', 250)->firstOrFail();
        $this->actingAs($super)->deleteJson("/api/admin/settings/rates/{$rate->id}", [], self::EN)->assertOk();
        $this->actingAs($super)->putJson('/api/admin/settings/accounts', [
            'bkash' => ['number' => '01712345678', 'type' => 'personal', 'visible' => true],
            'nagad' => ['number' => null, 'type' => 'personal', 'visible' => false],
            'rocket' => ['number' => null, 'type' => 'personal', 'visible' => false],
            'bank' => ['bank_name' => null, 'account_name' => null, 'account_number' => null, 'branch' => null, 'routing_number' => null, 'visible' => false],
            'instructions' => ['bn' => null, 'en' => null, 'visible' => false],
        ], self::EN)->assertOk();
        $this->actingAs($super)->putJson('/api/admin/settings/start-month', ['start_month' => '2026-09', 'confirm' => true], self::EN)->assertOk();
        $this->actingAs($admin)->putJson('/api/admin/site-settings', ['phone_1' => '01712345678'], self::EN)->assertOk();

        $missing = [];
        foreach (self::SECTION_34 as $what => $action) {
            $log = AuditLog::where('action', $action)->latest('id')->first();
            if (! $log) {
                $missing[] = "$what ($action)";

                continue;
            }
            $this->assertNotNull($log->actor_id, "$action has no actor");
            $this->assertNotNull($log->ip_address, "$action has no IP");
            $this->assertNotNull($log->created_at, "$action has no time");
        }
        $this->assertSame([], $missing, 'These MASTER_SPEC §34 actions were not written to the audit log.');

        // Old / new values for the money actions.
        $this->assertSame(150, AuditLog::where('action', 'assistance.approved')->first()->details['amount_approved']);
        $this->assertSame(150, AuditLog::where('action', 'assistance.paid')->first()->details['amount']);
        $this->assertSame(['old' => null, 'new' => 'yes'], AuditLog::where('action', 'assistance.voted')->first()->details['vote']);
    }

    public function test_every_section_34_action_has_a_readable_name_on_the_audit_log_page(): void
    {
        foreach (['en', 'bn'] as $locale) {
            $path = base_path("../frontend/src/i18n/{$locale}.json");
            $this->assertFileExists($path);
            $names = json_decode(file_get_contents($path), true)['auditLog']['actions'] ?? [];
            foreach (self::SECTION_34 as $action) {
                $this->assertNotEmpty($names[$action] ?? null, "No readable {$locale} name for audit action {$action}");
            }
        }
    }
}
