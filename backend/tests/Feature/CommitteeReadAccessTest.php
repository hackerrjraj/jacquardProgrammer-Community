<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Phase 4b-2: Programmers List + member profiles are read-only for committee. */
class CommitteeReadAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_committee_admin_and_super_admin_can_list_members(): void
    {
        User::factory()->count(2)->approved()->create();

        foreach ([User::factory()->committee()->create(), User::factory()->admin()->create(), User::factory()->superAdmin()->create()] as $reader) {
            $this->actingAs($reader)->getJson('/api/admin/members?status=approved')
                ->assertOk()
                ->assertJsonStructure(['data' => [['id', 'name', 'phone', 'district', 'status', 'photo_url', 'employment_status', 'designation']], 'meta' => ['total']]);
        }
    }

    public function test_members_and_guests_cannot_list_members(): void
    {
        $this->getJson('/api/admin/members')->assertUnauthorized();

        $member = User::factory()->approved()->create();
        $this->actingAs($member)->getJson('/api/admin/members')->assertForbidden();
    }

    public function test_list_filters_by_employment_status_and_district(): void
    {
        $dhakaJob = User::factory()->approved()->create(['district' => 'dhaka', 'employment_status' => 'running_job']);
        User::factory()->approved()->create(['district' => 'dhaka', 'employment_status' => 'jobless']);
        User::factory()->approved()->create(['district' => 'gazipur', 'employment_status' => 'running_job']);
        $committee = User::factory()->committee()->create(['district' => 'khulna']);

        $this->actingAs($committee)
            ->getJson('/api/admin/members?district=dhaka&employment_status=running_job')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $dhakaJob->id);

        $this->actingAs($committee)
            ->getJson('/api/admin/members?district=nowhere&employment_status=retired')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['district', 'employment_status']);
    }

    public function test_committee_cannot_change_anything(): void
    {
        $committee = User::factory()->committee()->create();
        $target = User::factory()->create(['name' => 'Old Name']);
        $approved = User::factory()->approved()->create();

        $this->actingAs($committee)->patchJson("/api/admin/members/{$target->id}", ['name' => 'New'])->assertForbidden();
        $this->actingAs($committee)->postJson("/api/admin/members/{$target->id}/approve")->assertForbidden();
        $this->actingAs($committee)->postJson("/api/admin/members/{$target->id}/reject", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($committee)->postJson("/api/admin/members/{$approved->id}/suspend")->assertForbidden();
        $this->actingAs($committee)->postJson("/api/admin/members/{$approved->id}/reset-password")->assertForbidden();

        $this->assertSame('Old Name', $target->refresh()->name);
        $this->assertSame('pending', $target->status);
        $this->assertSame(0, AuditLog::count());
    }
}
