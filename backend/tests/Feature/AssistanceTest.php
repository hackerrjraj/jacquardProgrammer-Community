<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicHomeController;
use App\Models\AssistanceRequest;
use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Models\Setting;
use App\Models\User;
use App\Support\FundFigures;
use Database\Seeders\DemoProgrammersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Aid requests — simple rules of Phase 8a (MASTER_SPEC §29–§32). */
class AssistanceTest extends TestCase
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
        Setting::put(Setting::FUND_START_MONTH, '2026-08'); // the member owes 3 months — must not matter
        $this->member = User::factory()->approved()->create(['name' => 'Rahim Uddin', 'phone' => '01711112222']);
        $this->admin = User::factory()->admin()->create();
    }

    private function apply(array $fields = [], ?User $user = null, array $headers = self::EN)
    {
        return $this->actingAs($user ?? $this->member)->post('/api/assistance', [
            'reason_type' => 'medical',
            'description' => 'Hospital bill after an operation.',
            'amount_requested' => 15000,
            ...$fields,
        ], $headers);
    }

    private function makeRequest(?User $user = null, array $fields = []): AssistanceRequest
    {
        $this->apply($fields, $user)->assertCreated();

        return AssistanceRequest::latest('id')->first();
    }

    private function approve(AssistanceRequest $r, int $amount = 10000, ?User $by = null, array $headers = self::EN)
    {
        return $this->actingAs($by ?? $this->admin)->postJson("/api/admin/assistance/{$r->id}/approve", ['amount_approved' => $amount], $headers);
    }

    private function pay(AssistanceRequest $r, array $fields = [], ?User $by = null, array $headers = self::EN)
    {
        return $this->actingAs($by ?? $this->admin)->post("/api/admin/assistance/{$r->id}/pay", [
            'method' => 'bkash',
            'payout_date' => '2026-10-15',
            ...$fields,
        ], $headers);
    }

    /** Money in the fund: an approved contribution ledger entry. */
    private function fund(int $amount, ?User $from = null): void
    {
        LedgerEntry::create([
            'type' => LedgerEntry::TYPE_CONTRIBUTION,
            'amount' => $amount,
            'user_id' => ($from ?? $this->member)->id,
            'entry_date' => '2026-10-01',
        ]);
    }

    private function demo(array $attributes = []): User
    {
        return User::factory()->approved()->create(['email' => 'demo'.fake()->unique()->numberBetween(1, 9999).'@'.DemoProgrammersSeeder::EMAIL_DOMAIN, ...$attributes]);
    }

    public function test_member_applies_with_documents(): void
    {
        $response = $this->apply([
            'amount_requested' => '১৫০০০', // Bengali digits
            'applying_for_name' => 'Karim Uddin',
            'applying_for_relation' => 'Father',
            'documents' => [
                UploadedFile::fake()->create('hospital bill.pdf', 900, 'application/pdf'),
                UploadedFile::fake()->image('prescription.jpg')->size(400),
            ],
        ])->assertCreated()
            ->assertJsonPath('message', __('messages.assistance_submitted', [], 'en'))
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.amount_requested', 15000)
            ->assertJsonPath('data.applying_for', ['name' => 'Karim Uddin', 'relation' => 'Father'])
            ->assertJsonPath('data.consent_public_name', false)
            ->assertJsonPath('data.votes', ['yes' => 0, 'no' => 0])
            ->assertJsonPath('data.payout', null)
            ->assertJsonCount(2, 'data.documents')
            ->assertJsonMissingPath('data.voters');

        $r = AssistanceRequest::sole();
        $this->assertSame(sprintf('JPPHAF-AR-2026-%04d', $r->id), $r->case_id);
        $this->assertSame($r->case_id, $response->json('data.case_id'));
        $this->assertSame($this->member->id, $r->open_key);
        $this->assertNull($r->reason_other);

        $doc = $r->documents()->first();
        $this->assertSame('hospital bill.pdf', $doc->original_name);
        $this->assertStringStartsWith('assistance/', $doc->path);
        $this->assertStringNotContainsString('hospital', $doc->path);
        Storage::disk('private')->assertExists($doc->path);
        $this->assertStringEndsWith("/api/assistance/{$r->id}/documents/{$doc->id}", $response->json('data.documents.0.url'));

        $log = AuditLog::where('action', 'assistance.submitted')->sole();
        $this->assertSame($this->member->id, $log->actor_id);
        $this->assertSame(['old' => null, 'new' => 'submitted'], $log->details['status']);
        $this->assertSame(2, $log->details['documents']);

        // Documents are optional.
        $other = User::factory()->committee()->create();
        $this->apply(['reason_type' => 'other', 'reason_other' => 'House fire', 'consent_public_name' => '1'], $other)
            ->assertCreated()
            ->assertJsonPath('data.reason_other', 'House fire')
            ->assertJsonPath('data.consent_public_name', true)
            ->assertJsonPath('data.documents', []);
    }

    public function test_application_validation_in_bengali(): void
    {
        $this->actingAs($this->member)->post('/api/assistance', [], self::BN)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason_type', 'description', 'amount_requested'])
            ->assertJsonPath('errors.description.0', 'বিবরণ দেওয়া আবশ্যক।');

        $this->apply(['reason_type' => 'other', 'applying_for_name' => 'Karim'], null, self::BN)
            ->assertUnprocessable()
            ->assertJsonPath('errors.reason_other.0', 'কারণ "অন্যান্য" হলে কারণটি লিখুন।')
            ->assertJsonPath('errors.applying_for_relation.0', 'যার জন্য আবেদন করছেন, তার সাথে আপনার সম্পর্ক লিখুন।');

        $this->apply(['amount_requested' => 0, 'reason_type' => 'lottery'], null, self::BN)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount_requested', 'reason_type']);

        $this->apply(['documents' => array_fill(0, 6, UploadedFile::fake()->image('a.jpg'))], null, self::BN)
            ->assertUnprocessable()
            ->assertJsonPath('errors.documents.0', 'সর্বোচ্চ ৫টি ডকুমেন্ট দেওয়া যাবে।');

        $errors = $this->apply(['documents' => [UploadedFile::fake()->create('big.pdf', 5121, 'application/pdf')]], null, self::BN)
            ->assertUnprocessable()
            ->json('errors');
        $this->assertSame('প্রতিটি ডকুমেন্ট সর্বোচ্চ ৫ এমবি হতে পারবে।', $errors['documents.0'][0]);

        $errors = $this->apply(['documents' => [UploadedFile::fake()->create('notes.docx', 10)]], null, self::BN)
            ->assertUnprocessable()
            ->json('errors');
        $this->assertSame('শুধু JPG, PNG, WebP বা PDF ফাইল দেওয়া যাবে।', $errors['documents.0'][0]);

        $this->assertSame(0, AssistanceRequest::count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_who_can_apply(): void
    {
        $this->postJson('/api/assistance', [], self::EN)->assertUnauthorized();

        $pending = User::factory()->create();
        $this->apply([], $pending)->assertForbidden();

        $this->apply([], $this->demo())
            ->assertForbidden()
            ->assertJsonPath('message', __('messages.assistance_not_allowed', [], 'en'));

        // Unpaid dues do not matter; any role may apply.
        $this->actingAs($this->member)->getJson('/api/contributions/dues')->assertJsonPath('data.months_due', 3);
        $this->apply()->assertCreated();
        $this->apply([], $this->admin)->assertCreated();
        $this->apply([], User::factory()->superAdmin()->create())->assertCreated();
        $this->assertSame(3, AssistanceRequest::count());
    }

    public function test_only_one_open_request_per_member(): void
    {
        $r = $this->makeRequest();
        $this->apply()->assertUnprocessable()->assertJsonPath('message', __('messages.assistance_open_exists', [], 'en'));

        // Approved but not paid yet is still open.
        $this->approve($r)->assertOk();
        $this->apply()->assertUnprocessable();

        $this->fund(20000);
        $this->pay($r)->assertOk();
        $second = $this->makeRequest();

        $this->actingAs($this->admin)->postJson("/api/admin/assistance/{$second->id}/reject", ['reason' => 'Not enough information'], self::EN)->assertOk();
        $third = $this->makeRequest();

        $this->actingAs($this->member)->postJson("/api/assistance/{$third->id}/cancel", [], self::EN)->assertOk();
        $this->apply()->assertCreated();
        $this->assertSame(4, AssistanceRequest::count());
    }

    public function test_applicant_lists_views_and_cancels(): void
    {
        $r = $this->makeRequest();
        $other = User::factory()->approved()->create();

        $this->actingAs($this->member)->getJson('/api/assistance')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.case_id', $r->case_id)
            ->assertJsonMissingPath('data.0.applicant');
        $this->actingAs($other)->getJson('/api/assistance')->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($this->member)->getJson("/api/assistance/{$r->id}")->assertOk()->assertJsonPath('data.id', $r->id);
        $this->actingAs($other)->getJson("/api/assistance/{$r->id}", self::EN)->assertForbidden();

        // Only the applicant can cancel.
        $this->actingAs($other)->postJson("/api/assistance/{$r->id}/cancel", [], self::EN)->assertForbidden();
        $this->actingAs($this->admin)->postJson("/api/assistance/{$r->id}/cancel", [], self::EN)->assertForbidden();

        $this->actingAs($this->member)->postJson("/api/assistance/{$r->id}/cancel", [], self::BN)
            ->assertOk()
            ->assertJsonPath('message', 'আপনার সহায়তার আবেদন প্রত্যাহার করা হয়েছে।')
            ->assertJsonPath('data.status', 'cancelled');

        $r->refresh();
        $this->assertNotNull($r->cancelled_at);
        $this->assertNull($r->open_key);
        $this->assertSame(['old' => 'submitted', 'new' => 'cancelled'], AuditLog::where('action', 'assistance.cancelled')->sole()->details['status']);

        // Twice, or after a decision → refused.
        $this->actingAs($this->member)->postJson("/api/assistance/{$r->id}/cancel", [], self::EN)->assertUnprocessable();
        $approved = $this->makeRequest();
        $this->approve($approved)->assertOk();
        $this->actingAs($this->member)->postJson("/api/assistance/{$approved->id}/cancel", [], self::EN)
            ->assertUnprocessable()
            ->assertJsonPath('message', __('messages.assistance_not_submitted', [], 'en'));
    }

    public function test_documents_are_private(): void
    {
        $r = $this->makeRequest(null, ['documents' => [UploadedFile::fake()->create('bill.pdf', 100, 'application/pdf')]]);
        $doc = $r->documents()->sole();
        $url = "/api/assistance/{$r->id}/documents/{$doc->id}";

        $this->app['auth']->forgetGuards();
        $this->getJson($url)->assertUnauthorized();

        $this->actingAs($this->member)->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($this->admin)->get($url)->assertOk();
        $this->actingAs(User::factory()->superAdmin()->create())->get($url)->assertOk();
        $this->actingAs(User::factory()->committee()->create())->getJson($url)->assertForbidden();
        $this->actingAs(User::factory()->approved()->create())->getJson($url)->assertForbidden();

        // A document id of another request → 404.
        $other = $this->makeRequest(User::factory()->approved()->create(), ['documents' => [UploadedFile::fake()->image('x.png')]]);
        $this->actingAs($this->member)->getJson("/api/assistance/{$r->id}/documents/{$other->documents()->sole()->id}")->assertNotFound();
    }

    public function test_advisory_voting(): void
    {
        $r = $this->makeRequest();
        $member2 = User::factory()->approved()->create();
        $committee = User::factory()->committee()->create();
        $super = User::factory()->superAdmin()->create();
        $vote = fn (User $u, string $v, array $h = self::EN) => $this->actingAs($u)->postJson("/api/assistance/{$r->id}/vote", ['vote' => $v], $h);

        $vote($member2, 'yes')->assertOk()->assertJsonPath('data.votes', ['yes' => 1, 'no' => 0])->assertJsonPath('data.my_vote', 'yes');
        $vote($committee, 'no')->assertOk();
        $vote($super, 'yes', self::BN)->assertOk()->assertJsonPath('message', 'আপনার ভোট সংরক্ষিত হয়েছে।');
        // Role admin votes too (Phase 8b).
        $vote($this->admin, 'no')->assertOk()->assertJsonPath('data.votes', ['yes' => 2, 'no' => 2]);

        // Changeable until the decision; the same vote again writes no new audit row.
        $vote($committee, 'yes')->assertOk()->assertJsonPath('data.votes', ['yes' => 3, 'no' => 1]);
        $vote($committee, 'yes')->assertOk();
        $this->assertSame(1, $r->votes()->where('user_id', $committee->id)->count());
        $this->assertSame(5, AuditLog::where('action', 'assistance.voted')->count());
        $this->assertSame(['old' => 'no', 'new' => 'yes'], AuditLog::where('action', 'assistance.voted')->latest('id')->first()->details['vote']);

        // Not allowed: the applicant, demo, pending.
        $vote($this->member, 'yes')->assertForbidden()->assertJsonPath('message', __('messages.assistance_vote_own', [], 'en'));
        $vote($this->demo(), 'yes')->assertForbidden()->assertJsonPath('message', __('messages.assistance_vote_not_allowed', [], 'en'));
        $vote(User::factory()->create(), 'yes')->assertForbidden();
        $vote($member2, 'maybe')->assertUnprocessable()->assertJsonValidationErrors('vote');

        // Members see the counts and their own vote — never who voted.
        $this->actingAs($member2)->getJson('/api/assistance/open')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.votes', ['yes' => 3, 'no' => 1])
            ->assertJsonPath('data.0.my_vote', 'yes')
            ->assertJsonPath('data.0.can_vote', true)
            ->assertJsonPath('data.0.applicant.name', 'Rahim Uddin')
            ->assertJsonMissingPath('data.0.voters')
            ->assertJsonMissingPath('data.0.documents')
            ->assertJsonMissingPath('data.0.applicant.phone');
        $this->actingAs($this->member)->getJson('/api/assistance/open')
            ->assertJsonPath('data.0.my_vote', null)
            ->assertJsonPath('data.0.can_vote', false);
        $this->actingAs($this->member)->getJson("/api/assistance/{$r->id}")
            ->assertJsonPath('data.votes', ['yes' => 3, 'no' => 1])
            ->assertJsonMissingPath('data.voters');

        // Admin sees who voted how.
        $voters = $this->actingAs($this->admin)->getJson("/api/admin/assistance/{$r->id}")
            ->assertOk()
            ->assertJsonCount(4, 'data.voters')
            ->json('data.voters');
        $this->assertEqualsCanonicalizing([$member2->id, $committee->id, $super->id, $this->admin->id], array_column(array_column($voters, 'user'), 'id'));

        // Votes are advisory: 3 × yes changes nothing. After the decision voting is closed.
        $this->assertSame('submitted', $r->fresh()->status);
        $this->actingAs($this->admin)->postJson("/api/admin/assistance/{$r->id}/reject", ['reason' => 'Covered by insurance'], self::EN)->assertOk();
        $vote($member2, 'no')->assertUnprocessable()->assertJsonPath('message', __('messages.assistance_voting_closed', [], 'en'));
        $this->actingAs($member2)->getJson('/api/assistance/open')->assertJsonCount(0, 'data');
    }

    public function test_admin_routes_are_for_admin_and_super_admin_only(): void
    {
        $this->getJson('/api/admin/assistance')->assertUnauthorized();
        $r = $this->makeRequest();

        foreach ([User::factory()->committee()->create(), User::factory()->approved()->create()] as $user) {
            $this->actingAs($user)->getJson('/api/admin/assistance')->assertForbidden();
            $this->actingAs($user)->getJson("/api/admin/assistance/{$r->id}")->assertForbidden();
            $this->approve($r, 100, $user)->assertForbidden();
            $this->actingAs($user)->postJson("/api/admin/assistance/{$r->id}/reject", ['reason' => 'x'])->assertForbidden();
            $this->pay($r, [], $user)->assertForbidden();
        }

        $this->approve($r, 100, User::factory()->superAdmin()->create())->assertOk();
    }

    public function test_approve_sets_any_amount_and_is_audited(): void
    {
        $r = $this->makeRequest();
        $this->actingAs(User::factory()->approved()->create())->postJson("/api/assistance/{$r->id}/vote", ['vote' => 'yes']);

        $this->actingAs($this->admin)->postJson("/api/admin/assistance/{$r->id}/approve", ['amount_approved' => 0], self::BN)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount_approved');

        // More than requested is allowed (owner, Phase 8a); Bengali digits accepted.
        $this->actingAs($this->admin)->postJson("/api/admin/assistance/{$r->id}/approve", ['amount_approved' => '২০০০০', 'note' => 'Urgent case'], self::BN)
            ->assertOk()
            ->assertJsonPath('message', 'সহায়তার আবেদন অনুমোদন করা হয়েছে।')
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.amount_approved', 20000)
            ->assertJsonPath('data.decision_note', 'Urgent case')
            ->assertJsonPath('data.decided_by.id', $this->admin->id);

        $r->refresh();
        $this->assertNotNull($r->decided_at);
        $this->assertSame($this->member->id, $r->open_key); // open until paid
        $this->assertSame(0, LedgerEntry::count());        // approval moves no money

        $log = AuditLog::where('action', 'assistance.approved')->sole();
        $this->assertSame($this->admin->id, $log->actor_id);
        $this->assertSame(['old' => 'submitted', 'new' => 'approved'], $log->details['status']);
        $this->assertSame(20000, $log->details['amount_approved']);
        $this->assertSame(['yes' => 1, 'no' => 0], $log->details['votes']);

        // Already decided → 422 for both actions.
        $this->approve($r)->assertUnprocessable()->assertJsonPath('message', __('messages.assistance_not_submitted', [], 'en'));
        $this->actingAs($this->admin)->postJson("/api/admin/assistance/{$r->id}/reject", ['reason' => 'x'], self::EN)->assertUnprocessable();
    }

    public function test_reject_needs_a_reason(): void
    {
        $r = $this->makeRequest();

        $this->actingAs($this->admin)->postJson("/api/admin/assistance/{$r->id}/reject", ['reason' => ' '], self::BN)
            ->assertUnprocessable()
            ->assertJsonPath('errors.reason.0', 'কারণ দেওয়া আবশ্যক।');

        $this->actingAs($this->admin)->postJson("/api/admin/assistance/{$r->id}/reject", ['reason' => 'Already helped this year'], self::EN)
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Already helped this year');

        $this->assertNull($r->fresh()->open_key);
        $this->assertSame('Already helped this year', AuditLog::where('action', 'assistance.rejected')->sole()->details['reason']);

        // The applicant sees the reason.
        $this->actingAs($this->member)->getJson("/api/assistance/{$r->id}")->assertJsonPath('data.rejection_reason', 'Already helped this year');

        // Paying a rejected request is impossible.
        $this->fund(50000);
        $this->pay($r)->assertUnprocessable()->assertJsonPath('message', __('messages.assistance_not_approved', [], 'en'));
    }

    public function test_nobody_decides_or_pays_their_own_request(): void
    {
        $r = $this->makeRequest($this->admin);
        $this->fund(50000);

        $this->approve($r)->assertForbidden()->assertJsonPath('message', __('messages.assistance_own', [], 'en'));
        $this->actingAs($this->admin)->postJson("/api/admin/assistance/{$r->id}/reject", ['reason' => 'x'], self::EN)->assertForbidden();

        $super = User::factory()->superAdmin()->create();
        $this->approve($r, 5000, $super)->assertOk();
        $this->pay($r)->assertForbidden();
        $this->assertSame(0, LedgerEntry::where('type', LedgerEntry::TYPE_DISBURSEMENT)->count());

        $this->pay($r, [], $super)->assertOk();
    }

    public function test_payout_writes_a_ledger_debit_and_updates_the_figures(): void
    {
        $this->fund(30000);
        $r = $this->makeRequest();

        // Not approved yet → cannot pay.
        $this->pay($r)->assertUnprocessable()->assertJsonPath('message', __('messages.assistance_not_approved', [], 'en'));

        $this->approve($r, 12000)->assertOk();

        $this->pay($r, ['payout_date' => '2026-10-16'], null, self::BN)
            ->assertUnprocessable()
            ->assertJsonPath('errors.payout_date.0', 'প্রদানের তারিখ ভবিষ্যতের হতে পারবে না।');
        $this->pay($r, ['method' => 'cheque', 'payout_date' => ''])->assertUnprocessable()->assertJsonValidationErrors(['method', 'payout_date']);

        // The same admin may approve and pay. TxID optional, proof optional.
        $response = $this->pay($r, [
            'transaction_id' => ' bk 778 ',
            'note' => 'Sent to his father',
            'payout_proof' => UploadedFile::fake()->image('sent.png')->size(300),
        ], null, self::BN)
            ->assertOk()
            ->assertJsonPath('message', 'টাকা প্রদান রেকর্ড করা হয়েছে এবং ফান্ড থেকে বাদ দেওয়া হয়েছে।')
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payout.method', 'bkash')
            ->assertJsonPath('data.payout.transaction_id', 'BK778')
            ->assertJsonPath('data.payout.date', '2026-10-15')
            ->assertJsonPath('data.paid_by.id', $this->admin->id)
            ->assertJsonPath('meta.fund_balance', 18000);

        $entry = LedgerEntry::where('type', LedgerEntry::TYPE_DISBURSEMENT)->sole();
        $this->assertSame(12000, $entry->amount);
        $this->assertSame($r->id, $entry->assistance_request_id);
        $this->assertSame($this->member->id, $entry->user_id);
        $this->assertSame('2026-10-15', $entry->entry_date->format('Y-m-d'));

        $r->refresh();
        $this->assertNotNull($r->paid_at);
        $this->assertNull($r->open_key);
        Storage::disk('private')->assertExists($r->payout_proof_path);
        $this->assertStringEndsWith("/api/assistance/{$r->id}/payout-proof", $response->json('data.payout.proof_url'));
        $this->actingAs($this->member)->get("/api/assistance/{$r->id}/payout-proof")->assertOk();
        $this->actingAs(User::factory()->committee()->create())->getJson("/api/assistance/{$r->id}/payout-proof")->assertForbidden();

        $log = AuditLog::where('action', 'assistance.paid')->sole();
        $this->assertSame(['old' => 'approved', 'new' => 'paid'], $log->details['status']);
        $this->assertSame(12000, $log->details['amount']);
        $this->assertSame(30000, $log->details['balance_before']);

        // Only once.
        $this->pay($r)->assertUnprocessable();
        $this->assertSame(1, LedgerEntry::where('type', LedgerEntry::TYPE_DISBURSEMENT)->count());

        // Fund figures: public stats (cache cleared by the payout) and the dashboard.
        $this->actingAs($this->admin)->getJson('/api/fund-report')
            ->assertJsonPath('data.total_collected', 30000)
            ->assertJsonPath('data.total_disbursed', 12000)
            ->assertJsonPath('data.fund_balance', 18000)
            ->assertJsonPath('data.cases_paid', 1);
        $this->actingAs($this->admin)->getJson('/api/dashboard/summary')
            ->assertJsonPath('data.total_disbursed', 12000)
            ->assertJsonPath('data.fund_balance', 18000);
    }

    public function test_payout_cannot_exceed_the_fund_balance(): void
    {
        $this->fund(5000);
        $r = $this->makeRequest();
        $this->approve($r, 8000)->assertOk();

        $this->pay($r, ['payout_proof' => UploadedFile::fake()->image('sent.png')], null, self::BN)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'ফান্ডে যথেষ্ট টাকা নেই: বর্তমান ব্যালেন্স 5,000 টাকা, প্রদানের পরিমাণ 8,000 টাকা।');
        $this->assertSame('approved', $r->fresh()->status);
        $this->assertSame(0, LedgerEntry::where('type', LedgerEntry::TYPE_DISBURSEMENT)->count());
        $this->assertSame([], Storage::disk('private')->allFiles()); // the uploaded proof was removed again

        // Exactly the balance is fine; afterwards the fund is empty and the next payout is blocked.
        $this->fund(3000);
        $this->pay($r)->assertOk()->assertJsonPath('meta.fund_balance', 0);

        $next = $this->makeRequest(User::factory()->approved()->create());
        $this->approve($next, 1)->assertOk();
        $this->pay($next)->assertUnprocessable();
    }

    public function test_payout_balance_uses_the_full_ledger_but_public_totals_leave_demo_out(): void
    {
        // Demo money is in the ledger: it counts for the payout check, not for the public / dashboard totals.
        $this->fund(4000, $this->demo());
        $this->fund(1000);
        $r = $this->makeRequest();
        $this->approve($r, 4500)->assertOk();

        $this->actingAs($this->admin)->getJson("/api/admin/assistance/{$r->id}")->assertJsonPath('meta.fund_balance', 5000);
        $this->pay($r)->assertOk()->assertJsonPath('meta.fund_balance', 500);

        $this->actingAs($this->admin)->getJson('/api/fund-report')
            ->assertJsonPath('data.total_collected', 1000)
            ->assertJsonPath('data.total_disbursed', 4500)
            ->assertJsonPath('data.fund_balance', -3500);
    }

    public function test_ledger_balance_counts_adjustments_and_reversals(): void
    {
        $this->fund(10000);
        $wrong = LedgerEntry::create(['type' => LedgerEntry::TYPE_CONTRIBUTION, 'amount' => 3000, 'user_id' => $this->member->id, 'entry_date' => '2026-10-02']);
        LedgerEntry::create(['type' => LedgerEntry::TYPE_REVERSAL, 'amount' => 3000, 'reverses_id' => $wrong->id, 'reason' => 'Entered twice', 'entry_date' => '2026-10-03']);
        LedgerEntry::create(['type' => LedgerEntry::TYPE_ADJUSTMENT, 'amount' => 500, 'reason' => 'Bank interest', 'entry_date' => '2026-10-04']);
        $paid = LedgerEntry::create(['type' => LedgerEntry::TYPE_DISBURSEMENT, 'amount' => 2000, 'user_id' => $this->member->id, 'entry_date' => '2026-10-05']);
        LedgerEntry::create(['type' => LedgerEntry::TYPE_REVERSAL, 'amount' => 2000, 'reverses_id' => $paid->id, 'reason' => 'Payout returned', 'entry_date' => '2026-10-06']);

        // 10000 + 3000 − 3000 + 500 − 2000 + 2000
        $this->assertSame(10500, FundFigures::ledgerBalance());
    }

    public function test_admin_list_filters_and_bengali_digit_search(): void
    {
        $a = $this->makeRequest();
        $karim = User::factory()->approved()->create(['name' => 'Karim Mia', 'phone' => '01899990000']);
        $b = $this->makeRequest($karim, ['reason_type' => 'death', 'applying_for_name' => 'Late Abdul', 'applying_for_relation' => 'Brother']);
        $this->approve($b)->assertOk();

        $this->actingAs($this->admin)->getJson('/api/admin/assistance')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $b->id) // newest first
            ->assertJsonPath('data.0.applicant.phone', '01899990000')
            ->assertJsonStructure(['meta' => ['fund_balance']]);

        $ids = fn (string $query) => array_column($this->actingAs($this->admin)->getJson('/api/admin/assistance?'.$query)->assertOk()->json('data'), 'id');

        $this->assertSame([$a->id], $ids('status=submitted'));
        $this->assertSame([$b->id], $ids('reason_type=death'));
        $this->assertSame([$b->id], $ids('search='.urlencode('০১৮৯৯৯৯'))); // Bengali digits → phone
        $this->assertSame([$a->id], $ids('search='.urlencode($a->case_id)));
        $this->assertSame([$b->id], $ids('search=Abdul'));
        $this->assertSame([$a->id], $ids('member='.$this->member->id));
        $this->assertSame([$a->id, $b->id], $ids('sort=oldest'));
        $this->assertSame([], $ids('from=2026-10-16'));
        $this->actingAs($this->admin)->getJson('/api/admin/assistance?from=2026-10-16&to=2026-10-01')->assertUnprocessable();
    }
}
