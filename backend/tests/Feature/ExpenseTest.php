<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Contribution;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\LedgerEntry;
use App\Models\Setting;
use App\Models\User;
use App\Support\FundFigures;
use App\Support\FundIntegrity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Expenses module: balance = collected − aid paid − expenses; permissions; audit log; integrity check. */
class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    private const EN = ['Accept' => 'application/json', 'Accept-Language' => 'en'];

    private User $super;

    private User $admin;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-15 12:00:00');
        Setting::put(Setting::FUND_START_MONTH, '2026-10');
        $this->super = User::factory()->superAdmin()->create();
        $this->admin = User::factory()->admin()->create();
        $this->member = User::factory()->approved()->create();
    }

    private function collect(int $amount): void
    {
        DB::transaction(function () use ($amount) {
            $c = Contribution::create(['user_id' => $this->member->id, 'method' => 'cash', 'amount' => $amount]);
            LedgerEntry::create([
                'type' => LedgerEntry::TYPE_CONTRIBUTION, 'amount' => $amount, 'user_id' => $this->member->id,
                'contribution_id' => $c->id, 'entry_date' => '2026-10-01',
            ]);
            $c->status = Contribution::STATUS_APPROVED;
            $c->save();
        });
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'expense_date' => '2026-10-10',
            'amount' => 300,
            'category_id' => ExpenseCategory::where('name_en', 'Office')->value('id'),
            'purpose' => 'Paper and pens',
            'paid_to' => 'Stationery shop',
        ], $over);
    }

    private function add(array $over = [], ?User $by = null)
    {
        return $this->actingAs($by ?? $this->admin)->postJson('/api/admin/expenses', $this->payload($over), self::EN);
    }

    public function test_logged_out_is_refused(): void
    {
        $this->getJson('/api/admin/expenses', self::EN)->assertUnauthorized();
        $this->getJson('/api/fund-report/expenses', self::EN)->assertUnauthorized();
    }

    public function test_default_categories_exist(): void
    {
        $this->assertEqualsCanonicalizing(
            ['Office', 'Event', 'Printing', 'Transport', 'Bank charge', 'Other'],
            ExpenseCategory::pluck('name_en')->all(),
        );
    }

    public function test_balance_is_collected_minus_expenses_and_deleted_expenses_do_not_count(): void
    {
        $this->collect(1000);
        $this->assertSame(1000, FundFigures::balance());

        $id = $this->add(['amount' => 300])->assertCreated()->json('data.id');
        $this->add(['amount' => 200])->assertCreated();

        $this->assertSame(500, FundFigures::expenses());
        $this->assertSame(500, FundFigures::balance());
        $this->assertSame(500, FundFigures::ledgerBalance());

        $this->actingAs($this->super)->deleteJson("/api/admin/expenses/$id", [], self::EN)->assertOk();
        $this->assertSame(200, FundFigures::expenses());
        $this->assertSame(800, FundFigures::balance());
        $this->assertSoftDeleted('expenses', ['id' => $id]);
    }

    public function test_list_summary_and_filters(): void
    {
        $this->collect(1000);
        $transport = ExpenseCategory::where('name_en', 'Transport')->value('id');
        $this->add(['expense_date' => '2026-10-02', 'amount' => 100, 'category_id' => $transport])->assertCreated();
        $this->add(['expense_date' => '2026-10-09', 'amount' => 50])->assertCreated();

        $all = $this->actingAs($this->admin)->getJson('/api/admin/expenses', self::EN)->assertOk();
        $all->assertJsonPath('summary.total_collected', 1000)
            ->assertJsonPath('summary.total_expenses', 150)
            ->assertJsonPath('summary.balance', 850)
            ->assertJsonPath('meta.total', 2);

        $this->actingAs($this->admin)->getJson("/api/admin/expenses?category_id=$transport", self::EN)
            ->assertJsonPath('meta.total', 1)->assertJsonPath('meta.filtered_total', 100);
        $this->actingAs($this->admin)->getJson('/api/admin/expenses?from=2026-10-05&to=2026-10-31', self::EN)
            ->assertJsonPath('meta.total', 1)->assertJsonPath('meta.filtered_total', 50);
    }

    public function test_only_admins_can_use_the_module_and_only_super_admin_can_delete(): void
    {
        $this->collect(1000);
        $committee = User::factory()->approved()->create(['role' => User::ROLE_COMMITTEE]);
        foreach ([$this->member, $committee] as $user) {
            $this->actingAs($user)->getJson('/api/admin/expenses', self::EN)->assertForbidden();
            $this->add([], $user)->assertForbidden();
            $this->actingAs($user)->getJson('/api/admin/expense-categories', self::EN)->assertForbidden();
        }

        $id = $this->add()->assertCreated()->json('data.id');
        $this->actingAs($this->admin)->putJson("/api/admin/expenses/$id", $this->payload(['amount' => 250]), self::EN)->assertOk();
        $this->actingAs($this->admin)->deleteJson("/api/admin/expenses/$id", [], self::EN)->assertForbidden();
        $this->assertNull(Expense::find($id)->deleted_at);
        $this->actingAs($this->super)->deleteJson("/api/admin/expenses/$id", [], self::EN)->assertOk();
    }

    public function test_validation_and_balance_cannot_go_below_zero(): void
    {
        $this->collect(500);
        $this->add(['purpose' => '   '])->assertUnprocessable()->assertJsonValidationErrors('purpose');
        $this->add(['amount' => 0])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->add(['expense_date' => '2026-12-01'])->assertUnprocessable()->assertJsonValidationErrors('expense_date');
        $this->add(['category_id' => 9999])->assertUnprocessable()->assertJsonValidationErrors('category_id');

        $this->add(['amount' => 501])->assertUnprocessable();
        $this->assertSame(0, Expense::count());
        $id = $this->add(['amount' => 500])->assertCreated()->json('data.id');
        $this->actingAs($this->admin)->putJson("/api/admin/expenses/$id", $this->payload(['amount' => 600]), self::EN)->assertUnprocessable();
        $this->assertSame(500, Expense::find($id)->amount);
    }

    public function test_every_change_is_audit_logged(): void
    {
        $this->collect(1000);
        $id = $this->add()->assertCreated()->json('data.id');
        $this->actingAs($this->admin)->putJson("/api/admin/expenses/$id", $this->payload(['amount' => 400]), self::EN)->assertOk();
        $this->actingAs($this->super)->deleteJson("/api/admin/expenses/$id", [], self::EN)->assertOk();

        foreach (['expense.created', 'expense.updated', 'expense.deleted'] as $action) {
            $this->assertSame(1, AuditLog::where('action', $action)->where('target_id', $id)->count(), $action);
        }
        $this->assertSame($this->admin->id, Expense::withTrashed()->find($id)->created_by);
        $this->assertSame($this->super->id, Expense::withTrashed()->find($id)->updated_by);
    }

    public function test_receipt_is_private_and_replaceable(): void
    {
        Storage::fake(Expense::DISK);
        $this->collect(1000);
        $res = $this->actingAs($this->admin)->post('/api/admin/expenses', $this->payload() + ['receipt' => UploadedFile::fake()->image('r.jpg')], self::EN)->assertCreated();
        $id = $res->json('data.id');
        $path = Expense::find($id)->receipt_image;
        Storage::disk(Expense::DISK)->assertExists($path);

        $this->actingAs($this->admin)->get("/api/admin/expenses/$id/receipt", self::EN)->assertOk();
        $this->actingAs($this->member)->get("/api/admin/expenses/$id/receipt", self::EN)->assertForbidden();

        $this->actingAs($this->admin)->post("/api/admin/expenses/$id", $this->payload() + ['remove_receipt' => 1], self::EN)->assertOk();
        Storage::disk(Expense::DISK)->assertMissing($path);
        $this->assertNull(Expense::find($id)->receipt_image);
    }

    public function test_categories_are_managed_by_admin_and_protected_while_used(): void
    {
        $new = $this->actingAs($this->admin)->postJson('/api/admin/expense-categories', ['name_bn' => 'খাবার', 'name_en' => 'Food'], self::EN)->assertCreated()->json('data.id');
        $this->actingAs($this->admin)->postJson('/api/admin/expense-categories', ['name_bn' => 'অন্য', 'name_en' => 'food'], self::EN)->assertUnprocessable();
        $this->actingAs($this->admin)->patchJson("/api/admin/expense-categories/$new", ['is_active' => false], self::EN)->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'expense_category.updated')->count());

        $this->collect(1000);
        $this->add(['category_id' => $new])->assertCreated();
        $this->actingAs($this->super)->deleteJson("/api/admin/expense-categories/$new", [], self::EN)->assertUnprocessable();
        $this->actingAs($this->admin)->deleteJson("/api/admin/expense-categories/$new", [], self::EN)->assertForbidden();
    }

    public function test_aid_payout_balance_check_uses_balance_after_expenses(): void
    {
        $this->collect(1000);
        $this->add(['amount' => 900])->assertCreated();
        $this->assertSame(100, FundFigures::ledgerBalance());
    }

    public function test_dashboard_and_fund_report_include_expenses(): void
    {
        $this->collect(1000);
        $this->add(['amount' => 250])->assertCreated();

        $this->actingAs($this->admin)->getJson('/api/dashboard/summary', self::EN)
            ->assertJsonPath('data.total_expenses', 250)->assertJsonPath('data.fund_balance', 750);

        $this->actingAs($this->member)->getJson('/api/fund-report', self::EN)
            ->assertJsonPath('data.total_expenses', 250)->assertJsonPath('data.fund_balance', 750);

        $list = $this->actingAs($this->member)->getJson('/api/fund-report/expenses', self::EN)->assertOk();
        $list->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.amount', 250)->assertJsonPath('data.0.purpose', 'Paper and pens');
        $this->assertArrayNotHasKey('paid_to', $list->json('data.0'));
        $this->assertArrayNotHasKey('receipt_url', $list->json('data.0'));
    }

    public function test_integrity_check_counts_expenses_and_flags_bad_ones(): void
    {
        $this->collect(1000);
        $this->add(['amount' => 400])->assertCreated();

        $result = FundIntegrity::run();
        $this->assertTrue($result['ok']);
        $this->assertSame(600, $result['balance']);

        // A hand-made bad row (blank purpose) and one that overspends the fund.
        $bad = Expense::create(['expense_date' => '2026-10-11', 'amount' => 800, 'category_id' => ExpenseCategory::first()->id, 'purpose' => ' ']);
        $result = FundIntegrity::run();
        $codes = array_column($result['issues'], 'code');
        $this->assertContains('expense_invalid', $codes);
        $this->assertContains('balance_negative', $codes);
        $this->assertFalse($result['ok']);
        $this->assertSame(-200, $result['balance']);

        $bad->delete();
        $this->assertTrue(FundIntegrity::run()['ok']);

        $this->actingAs($this->super)->getJson('/api/admin/ledger/integrity', self::EN)->assertOk()->assertJsonPath('data.balance', 600);
    }
}
