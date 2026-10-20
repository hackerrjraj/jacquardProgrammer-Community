<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WorkExperience;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Phase 4a-extra: employment status, designation, work history, email; admin name/email edit. */
class ProfileWorkAndAccountTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->member = User::factory()->approved()->create(['name' => 'Rahim Uddin', 'email' => 'rahim@example.com']);
    }

    private function history(int $count): array
    {
        return array_map(fn ($i) => [
            'company' => "Company {$i}",
            'position' => 'Programmer',
            'start_year' => 2000 + $i,
            'end_year' => 2001 + $i,
        ], range(1, $count));
    }

    // ---------- Employment status, designation ----------

    public function test_member_sets_employment_status_and_designation(): void
    {
        $this->actingAs($this->member)
            ->putJson('/api/profile', ['employment_status' => 'running_job', 'designation' => '  Senior Jacquard Programmer '])
            ->assertOk()
            ->assertJsonPath('data.employment_status', 'running_job')
            ->assertJsonPath('data.designation', 'Senior Jacquard Programmer');

        $this->putJson('/api/profile', ['employment_status' => 'jobless', 'designation' => ''])
            ->assertOk()
            ->assertJsonPath('data.employment_status', 'jobless')
            ->assertJsonPath('data.designation', null);

        $changes = AuditLog::where('action', 'profile.updated')->latest('id')->first()->details['changes'];
        $this->assertSame(['from' => 'running_job', 'to' => 'jobless'], $changes['employment_status']);
    }

    public function test_invalid_employment_status_and_long_designation_are_rejected(): void
    {
        $this->actingAs($this->member)
            ->putJson('/api/profile', ['employment_status' => 'retired', 'designation' => str_repeat('a', 101)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['employment_status', 'designation']);

        $this->putJson('/api/profile', ['employment_status' => ''])->assertJsonValidationErrors('employment_status');
        $this->putJson('/api/profile', ['designation' => str_repeat('a', 100)])->assertOk();
    }

    // ---------- Work history ----------

    public function test_member_saves_work_history_and_it_replaces_the_old_list(): void
    {
        $this->actingAs($this->member)
            ->putJson('/api/profile', ['work_history' => [
                ['company' => 'ABC Sweaters Ltd.', 'position' => 'Senior Knitting Programmer', 'start_year' => 2022, 'end_year' => 'present'],
                ['company' => 'XYZ Knitwear Ltd.', 'position' => 'Knitting Programmer', 'start_year' => 2018, 'end_year' => 2022],
            ]])
            ->assertOk()
            ->assertJsonCount(2, 'data.work_history')
            ->assertJsonPath('data.work_history.0.end_year', 'present')
            ->assertJsonPath('data.work_history.1', [
                'company' => 'XYZ Knitwear Ltd.', 'position' => 'Knitting Programmer', 'start_year' => 2018, 'end_year' => 2022,
            ]);

        $this->assertNull(WorkExperience::where('company', 'ABC Sweaters Ltd.')->sole()->end_year);

        // "Present" in any case is accepted; the list is replaced, not appended.
        $this->putJson('/api/profile', ['work_history' => [
            ['company' => 'New Co', 'position' => 'Head', 'start_year' => 2024, 'end_year' => 'Present'],
        ]])->assertOk()->assertJsonCount(1, 'data.work_history');
        $this->assertSame(1, WorkExperience::count());

        $log = AuditLog::where('action', 'profile.updated')->latest('id')->first();
        $this->assertCount(2, $log->details['changes']['work_history']['from']);
        $this->assertSame('New Co', $log->details['changes']['work_history']['to'][0]['company']);

        // An empty list removes all entries; other fields untouched when work_history is not sent.
        $this->putJson('/api/profile', ['work_history' => []])->assertOk()->assertJsonCount(0, 'data.work_history');
        $this->putJson('/api/profile', ['bio' => 'x'])->assertOk();
        $this->assertSame(0, WorkExperience::count());
    }

    public function test_work_history_is_limited_to_15_entries(): void
    {
        $this->actingAs($this->member)->putJson('/api/profile', ['work_history' => $this->history(15)])->assertOk();
        $this->assertSame(15, WorkExperience::count());

        $this->putJson('/api/profile', ['work_history' => $this->history(16)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('work_history');
        $this->assertSame(15, WorkExperience::count());
    }

    public function test_end_year_cannot_be_before_start_year_or_in_the_future(): void
    {
        $nextYear = now('Asia/Dhaka')->year + 1;
        $this->actingAs($this->member);

        $this->putJson('/api/profile', ['work_history' => [
            ['company' => 'A', 'position' => 'P', 'start_year' => 2020, 'end_year' => 2018],
        ]], ['Accept-Language' => 'en'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_history.0.end_year' => __('messages.end_year_before_start', [], 'en')]);

        $this->putJson('/api/profile', ['work_history' => [
            ['company' => 'A', 'position' => 'P', 'start_year' => 2020, 'end_year' => 2020], // same year is fine
        ]])->assertOk();

        foreach ([$nextYear, 'soon', '', null] as $bad) {
            $this->putJson('/api/profile', ['work_history' => [
                ['company' => 'A', 'position' => 'P', 'start_year' => 2020, 'end_year' => $bad],
            ]])->assertJsonValidationErrors('work_history.0.end_year');
        }

        $this->putJson('/api/profile', ['work_history' => [
            ['company' => 'A', 'position' => 'P', 'start_year' => $nextYear, 'end_year' => 'present'],
        ]])->assertJsonValidationErrors('work_history.0.start_year');
    }

    public function test_work_history_entry_fields_are_required_and_limited(): void
    {
        $this->actingAs($this->member)
            ->putJson('/api/profile', ['work_history' => [
                ['company' => '', 'position' => str_repeat('p', 101), 'start_year' => 'abc', 'end_year' => 'present'],
                ['company' => str_repeat('c', 151), 'position' => 'P', 'start_year' => 1949, 'end_year' => 'present'],
                'not-an-entry',
            ]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'work_history.0.company', 'work_history.0.position', 'work_history.0.start_year',
                'work_history.1.company', 'work_history.1.start_year', 'work_history.2',
            ]);

        $this->assertSame(0, WorkExperience::count());
    }

    // ---------- Name and email ----------

    public function test_member_cannot_change_name(): void
    {
        $this->actingAs($this->member)->putJson('/api/profile', ['name' => 'New Name'])->assertOk();

        $this->assertSame('Rahim Uddin', $this->member->refresh()->name);
    }

    public function test_member_can_change_and_clear_own_email(): void
    {
        $this->actingAs($this->member)
            ->putJson('/api/profile', ['email' => ' new@example.com '])
            ->assertOk()
            ->assertJsonPath('data.email', 'new@example.com');

        $log = AuditLog::where('action', 'profile.updated')->sole();
        $this->assertSame(['from' => 'rahim@example.com', 'to' => 'new@example.com'], $log->details['changes']['email']);

        // Keeping the same email is not a "duplicate".
        $this->putJson('/api/profile', ['email' => 'new@example.com'])->assertOk();

        $this->putJson('/api/profile', ['email' => ''])->assertOk()->assertJsonPath('data.email', null);
    }

    public function test_duplicate_or_invalid_email_is_rejected(): void
    {
        User::factory()->approved()->create(['email' => 'taken@example.com']);

        $this->actingAs($this->member)
            ->putJson('/api/profile', ['email' => 'taken@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->putJson('/api/profile', ['email' => 'not-an-email'])->assertJsonValidationErrors('email');

        $this->assertSame('rahim@example.com', $this->member->refresh()->email);
    }

    public function test_admin_can_change_member_name_and_email_only(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->patchJson("/api/admin/members/{$this->member->id}", [
                'name' => '  Md. Rahim Uddin ',
                'email' => 'rahim.uddin@example.com',
                'workplace' => 'Hacked Ltd',
                'bio' => 'hacked',
                'role' => 'super_admin',
            ], ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertJsonPath('message', __('messages.member_details_changed', [], 'en'))
            ->assertJsonPath('data.name', 'Md. Rahim Uddin')
            ->assertJsonPath('data.email', 'rahim.uddin@example.com');

        $this->member->refresh();
        $this->assertNotSame('Hacked Ltd', $this->member->workplace);
        $this->assertNull($this->member->bio);
        $this->assertSame('member', $this->member->role);

        $log = AuditLog::where('action', 'member.details_changed')->sole();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame($this->member->id, $log->target_id);
        $this->assertSame(['from' => 'Rahim Uddin', 'to' => 'Md. Rahim Uddin'], $log->details['changes']['name']);
        $this->assertSame(['from' => 'rahim@example.com', 'to' => 'rahim.uddin@example.com'], $log->details['changes']['email']);
    }

    public function test_super_admin_can_change_name_and_admin_rules_apply(): void
    {
        $super = User::factory()->superAdmin()->create();
        $admin = User::factory()->admin()->create();
        User::factory()->approved()->create(['email' => 'taken@example.com']);

        $this->actingAs($super)->patchJson("/api/admin/members/{$this->member->id}", ['name' => 'By Super'])->assertOk();
        $this->assertSame('By Super', $this->member->refresh()->name);

        // Duplicate email and empty name are rejected.
        $this->patchJson("/api/admin/members/{$this->member->id}", ['email' => 'taken@example.com'])->assertJsonValidationErrors('email');
        $this->patchJson("/api/admin/members/{$this->member->id}", ['name' => ''])->assertJsonValidationErrors('name');

        // Same rules as other admin actions: an admin cannot edit another admin or themselves.
        $this->actingAs($admin)->patchJson("/api/admin/members/{$super->id}", ['name' => 'X'])->assertForbidden();
        $this->actingAs($admin)->patchJson("/api/admin/members/{$admin->id}", ['name' => 'X'])->assertForbidden();
        $this->actingAs($super)->patchJson("/api/admin/members/{$admin->id}", ['name' => 'Renamed Admin'])->assertOk();
    }

    public function test_members_and_committee_cannot_use_admin_name_email_edit(): void
    {
        $other = User::factory()->approved()->create();

        foreach ([$this->member, User::factory()->committee()->create()] as $user) {
            $this->actingAs($user)->patchJson("/api/admin/members/{$other->id}", ['name' => 'X'])->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $this->patchJson("/api/admin/members/{$other->id}", ['name' => 'X'])->assertUnauthorized();
        $this->assertNotSame('X', $other->refresh()->name);
    }

    // ---------- Options ----------

    public function test_profile_options_list_bn_en_labels(): void
    {
        $this->getJson('/api/profile-options')
            ->assertOk()
            ->assertJsonCount(2, 'data.employment_statuses')
            ->assertJsonCount(4, 'data.document_types')
            ->assertJsonCount(8, 'data.portfolio_categories')
            ->assertJsonPath('data.document_types.0', ['key' => 'nid', 'name_bn' => 'জাতীয় পরিচয়পত্র (NID)', 'name_en' => 'National ID (NID)'])
            ->assertJsonPath('data.portfolio_categories.5.key', 'optimisation');
    }
}
