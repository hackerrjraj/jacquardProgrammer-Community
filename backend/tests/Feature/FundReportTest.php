<?php

namespace Tests\Feature;

use App\Models\AssistanceRequest;
use App\Models\LedgerEntry;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DemoProgrammersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Fund Report — members only (MASTER_SPEC §33). */
class FundReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-15 12:00:00');
        Setting::put(Setting::FUND_START_MONTH, '2026-08');
        $this->viewer = User::factory()->approved()->create();
    }

    private User $viewer;

    private function demo(): User
    {
        return User::factory()->approved()->create(['email' => 'demo'.fake()->unique()->numberBetween(1, 9999).'@'.DemoProgrammersSeeder::EMAIL_DOMAIN]);
    }

    private function contribution(User $from, int $amount, string $date): void
    {
        LedgerEntry::create(['type' => LedgerEntry::TYPE_CONTRIBUTION, 'amount' => $amount, 'user_id' => $from->id, 'entry_date' => $date]);
    }

    /** A paid aid case with its ledger debit (as the admin payout creates it). */
    private function paidCase(User $member, int $amount, string $date, array $fields = [], string $status = AssistanceRequest::STATUS_PAID): AssistanceRequest
    {
        $r = AssistanceRequest::create([
            'user_id' => $member->id,
            'reason_type' => 'medical',
            'reason_other' => null,
            'description' => 'Private description — never public.',
            'amount_requested' => $amount + 500,
            'amount_approved' => $amount,
            'status' => AssistanceRequest::STATUS_APPROVED,
            'payout_proof_path' => 'assistance/proof-secret.pdf',
            ...$fields,
        ]);
        $r->forceFill(['case_id' => AssistanceRequest::makeCaseId($r->id, 2026)])->save();
        if ($status === AssistanceRequest::STATUS_PAID) {
            // Ledger debit first, then "paid" — the only order the model allows (Phase 12b).
            LedgerEntry::create([
                'type' => LedgerEntry::TYPE_DISBURSEMENT, 'amount' => $amount, 'user_id' => $member->id,
                'assistance_request_id' => $r->id, 'reason' => $r->case_id, 'entry_date' => $date,
            ]);
            $r->forceFill(['status' => AssistanceRequest::STATUS_PAID, 'payout_date' => $date])->save();
        }

        return $r;
    }

    public function test_summary_shows_totals_and_monthly_breakdown_without_demo(): void
    {
        $a = User::factory()->approved()->create();
        $b = User::factory()->suspended()->create(); // money already in the fund still counts
        $this->contribution($a, 600, '2026-08-20');
        $this->contribution($b, 400, '2026-10-02');
        $this->contribution($a, 200, '2026-10-03');
        $this->contribution($this->demo(), 9999, '2026-10-04');
        $this->paidCase($a, 300, '2026-10-10');
        $this->paidCase($this->demo(), 5000, '2026-10-11');

        $this->actingAs($this->viewer)->getJson('/api/fund-report')
            ->assertOk()
            ->assertJsonPath('data.total_collected', 1200)
            ->assertJsonPath('data.total_disbursed', 300)
            ->assertJsonPath('data.fund_balance', 900)
            ->assertJsonPath('data.cases_paid', 1)
            ->assertJsonPath('data.monthly', [
                ['month' => '2026-10', 'collected' => 600, 'disbursed' => 300],
                ['month' => '2026-09', 'collected' => 0, 'disbursed' => 0],
                ['month' => '2026-08', 'collected' => 600, 'disbursed' => 0],
            ]);
    }

    public function test_months_reach_back_to_an_older_ledger_entry(): void
    {
        $this->contribution(User::factory()->approved()->create(), 200, '2026-07-31');

        $months = collect($this->actingAs($this->viewer)->getJson('/api/fund-report')->json('data.monthly'))->pluck('month')->all();
        $this->assertSame(['2026-10', '2026-09', '2026-08', '2026-07'], $months);
    }

    public function test_cases_list_shows_only_paid_cases_and_names_only_with_consent(): void
    {
        $hidden = $this->paidCase(User::factory()->approved()->create(['name' => 'Hidden Person']), 1000, '2026-09-05');
        $named = $this->paidCase(User::factory()->approved()->create(['name' => 'Shown Member']), 2000, '2026-10-01', ['consent_public_name' => true]);
        $family = $this->paidCase(User::factory()->approved()->create(['name' => 'Applicant Name']), 3000, '2026-10-12', [
            'consent_public_name' => true, 'reason_type' => 'other', 'reason_other' => 'Secret typed reason',
            'applying_for_name' => 'Karim Father', 'applying_for_relation' => 'Father',
        ]);
        $this->paidCase(User::factory()->approved()->create(), 700, '2026-10-13', [], AssistanceRequest::STATUS_APPROVED);
        $this->paidCase(User::factory()->approved()->create(), 700, '2026-10-13', [], AssistanceRequest::STATUS_SUBMITTED);
        $this->paidCase($this->demo(), 900, '2026-10-14');

        $response = $this->actingAs($this->viewer)->getJson('/api/fund-report/cases')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data', [
                ['case_id' => $family->case_id, 'reason' => 'other', 'amount' => 3000, 'date' => '2026-10-12', 'recipient_name' => 'Karim Father'],
                ['case_id' => $named->case_id, 'reason' => 'medical', 'amount' => 2000, 'date' => '2026-10-01', 'recipient_name' => 'Shown Member'],
                ['case_id' => $hidden->case_id, 'reason' => 'medical', 'amount' => 1000, 'date' => '2026-09-05', 'recipient_name' => null],
            ]);

        // Nothing private leaks: hidden names, applicant behind a family case, descriptions, typed reasons, files.
        foreach (['Hidden Person', 'Applicant Name', 'Private description', 'Secret typed reason', 'proof-secret', 'user_id', 'phone'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_paid_status_without_a_ledger_payout_is_not_listed(): void
    {
        $member = User::factory()->approved()->create();
        $real = $this->paidCase($member, 1000, '2026-10-01');
        $byHand = $this->paidCase(User::factory()->approved()->create(), 15000, '2026-10-02', [], AssistanceRequest::STATUS_APPROVED);
        // The app refuses "paid" without a payout (Phase 12b) — old data set by hand is simulated with a raw query.
        DB::table('assistance_requests')->where('id', $byHand->id)->update(['status' => AssistanceRequest::STATUS_PAID, 'payout_date' => '2026-10-02']);

        $this->actingAs($this->viewer)->getJson('/api/fund-report/cases')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.case_id', $real->case_id);
        $this->actingAs($this->viewer)->getJson('/api/fund-report')->assertJsonPath('data.total_disbursed', 1000);
    }

    public function test_cases_are_paginated(): void
    {
        $member = User::factory()->approved()->create();
        for ($i = 1; $i <= 21; $i++) {
            $this->paidCase($member, 100, '2026-10-01');
        }

        $this->actingAs($this->viewer)->getJson('/api/fund-report/cases?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 21);
    }

    public function test_report_requires_login_and_an_approved_account(): void
    {
        $this->getJson('/api/fund-report')->assertUnauthorized();
        $this->getJson('/api/fund-report/cases')->assertUnauthorized();

        foreach (['unapproved', 'suspended', 'rejected'] as $state) {
            $user = $state === 'unapproved' ? User::factory()->create() : User::factory()->{$state}()->create();
            $this->actingAs($user)->getJson('/api/fund-report')->assertForbidden();
            $this->actingAs($user)->getJson('/api/fund-report/cases')->assertForbidden();
        }

        foreach ([User::ROLE_MEMBER, User::ROLE_COMMITTEE, User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN] as $role) {
            $user = User::factory()->approved()->create(['role' => $role]);
            $this->actingAs($user)->getJson('/api/fund-report')->assertOk();
        }
    }

    public function test_old_public_endpoints_and_public_stats_have_no_fund_money(): void
    {
        $this->getJson('/api/public/fund-report')->assertNotFound();
        $this->getJson('/api/public/fund-report/cases')->assertNotFound();
        $this->getJson('/api/public/stats')->assertOk()->assertJsonMissingPath('data.total_collected');
        $this->getJson('/api/site-stats')->assertOk()->assertJsonMissingPath('data.fund_balance');
    }

    public function test_report_is_rate_limited(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->actingAs($this->viewer)->getJson('/api/fund-report/cases');
        }
        $this->actingAs($this->viewer)->getJson('/api/fund-report/cases')->assertStatus(429);
    }
}
