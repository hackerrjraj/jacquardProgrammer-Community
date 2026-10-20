<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Tests\TestCase;

class AdminMembersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['name' => 'Admin Person']);
    }

    private function audit(string $action, User $target): ?AuditLog
    {
        return AuditLog::where('action', $action)->where('target_type', 'User')->where('target_id', $target->id)->first();
    }

    // ---------- Access control ----------

    public function test_guests_get_401_and_non_admins_get_403_on_every_admin_action(): void
    {
        $target = User::factory()->create();
        // The read-only list/profile are open to committee — see CommitteeReadAccessTest.
        $calls = [
            ['PATCH', "/api/admin/members/{$target->id}"],
            ['POST', "/api/admin/members/{$target->id}/approve"],
            ['POST', "/api/admin/members/{$target->id}/reject"],
            ['POST', "/api/admin/members/{$target->id}/suspend"],
            ['POST', "/api/admin/members/{$target->id}/reset-password"],
            ['POST', "/api/admin/members/{$target->id}/role"],
        ];

        foreach ($calls as [$method, $url]) {
            $this->app['auth']->forgetGuards();
            $this->json($method, $url, ['reason' => 'x', 'role' => 'committee'])->assertUnauthorized();
        }

        foreach ([User::factory()->approved()->create(), User::factory()->committee()->create()] as $user) {
            foreach ($calls as [$method, $url]) {
                $this->actingAs($user)
                    ->json($method, $url, ['reason' => 'x', 'role' => 'committee'], ['Accept-Language' => 'en'])
                    ->assertForbidden()
                    ->assertJsonPath('message', __('messages.forbidden', [], 'en'));
            }
        }

        $this->assertSame('pending', $target->refresh()->status);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_admin_cannot_act_on_self_or_on_other_admins(): void
    {
        $otherAdmin = User::factory()->admin()->create();
        $super = User::factory()->superAdmin()->create();

        $this->actingAs($this->admin)
            ->postJson("/api/admin/members/{$this->admin->id}/reset-password", [], ['Accept-Language' => 'en'])
            ->assertForbidden()
            ->assertJsonPath('message', __('messages.cannot_manage_self', [], 'en'));

        foreach ([$otherAdmin, $super] as $target) {
            $this->postJson("/api/admin/members/{$target->id}/suspend", [], ['Accept-Language' => 'en'])
                ->assertForbidden()
                ->assertJsonPath('message', __('messages.cannot_manage_user', [], 'en'));
            $this->postJson("/api/admin/members/{$target->id}/reset-password")->assertForbidden();
        }

        $this->assertSame('approved', $otherAdmin->refresh()->status);
        $this->assertSame(0, AuditLog::count());

        // A Super Admin can act on an admin.
        $this->actingAs($super)->postJson("/api/admin/members/{$otherAdmin->id}/suspend")->assertOk();
    }

    // ---------- List ----------

    public function test_list_filters_searches_and_paginates(): void
    {
        // Fixed names: a random fake name could contain "Rahim" / "Uddin" and break the search checks below.
        foreach (['Sample One', 'Sample Two', 'Sample Three'] as $name) {
            User::factory()->create(['name' => $name]);
        }
        User::factory()->create(['name' => 'Rahim Uddin', 'phone' => '01911111111']);
        foreach (['Sample Four', 'Sample Five'] as $name) {
            User::factory()->approved()->create(['name' => $name]);
        }
        User::factory()->rejected()->create(['name' => 'Sample Six']);

        $this->actingAs($this->admin)
            ->getJson('/api/admin/members?status=pending&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('data.0.status', 'pending');

        $this->getJson('/api/admin/members?status=pending&search=rahim+uddin')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Rahim Uddin');
        $this->getJson('/api/admin/members?search=0191111')->assertJsonCount(1, 'data');

        $this->getJson('/api/admin/members')->assertJsonPath('meta.total', 8); // includes the admin
        $this->getJson('/api/admin/members?status=nonsense')->assertJsonValidationErrors(['status']);
    }

    // ---------- Approve ----------

    public function test_approve_pending_member(): void
    {
        $member = User::factory()->create();

        $this->actingAs($this->admin)
            ->postJson("/api/admin/members/{$member->id}/approve", [], ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertJsonPath('message', __('messages.member_approved', [], 'en'))
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approved_by', $this->admin->id);

        $member->refresh();
        $this->assertSame('approved', $member->status);
        $this->assertNotNull($member->approved_at);

        $log = $this->audit('member.approved', $member);
        $this->assertNotNull($log);
        $this->assertSame($this->admin->id, $log->actor_id);
        $this->assertSame(['from_status' => 'pending'], $log->details);
        $this->assertSame('127.0.0.1', $log->ip_address);
        $this->assertNotNull($log->created_at);

        // Approving again is refused.
        $this->postJson("/api/admin/members/{$member->id}/approve")->assertUnprocessable();
        $this->assertSame(1, AuditLog::count());
    }

    public function test_approve_reactivates_rejected_or_suspended_member(): void
    {
        $rejected = User::factory()->rejected()->create();
        $suspended = User::factory()->suspended()->create();

        $this->actingAs($this->admin)->postJson("/api/admin/members/{$rejected->id}/approve")->assertOk();
        $this->postJson("/api/admin/members/{$suspended->id}/approve")->assertOk();

        $this->assertNull($rejected->refresh()->rejection_reason);
        $this->assertSame('approved', $suspended->refresh()->status);
        $this->assertSame(['from_status' => 'suspended'], $this->audit('member.approved', $suspended)->details);
    }

    // ---------- Reject ----------

    public function test_reject_requires_reason(): void
    {
        $member = User::factory()->create();

        $this->actingAs($this->admin)
            ->postJson("/api/admin/members/{$member->id}/reject", ['reason' => '  '], ['Accept-Language' => 'bn'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reason.0', 'কারণ দেওয়া আবশ্যক।');

        $this->assertSame('pending', $member->refresh()->status);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_reject_pending_member_with_reason(): void
    {
        $member = User::factory()->create();

        $this->actingAs($this->admin)
            ->postJson("/api/admin/members/{$member->id}/reject", ['reason' => 'তথ্য অসম্পূর্ণ'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'তথ্য অসম্পূর্ণ');

        $this->assertSame(
            ['from_status' => 'pending', 'reason' => 'তথ্য অসম্পূর্ণ'],
            $this->audit('member.rejected', $member)->details,
        );

        // Only pending registrations can be rejected.
        $approved = User::factory()->approved()->create();
        $this->postJson("/api/admin/members/{$approved->id}/reject", ['reason' => 'x'])->assertUnprocessable();
    }

    // ---------- Suspend ----------

    public function test_suspend_approved_member(): void
    {
        $member = User::factory()->approved()->create();

        $this->actingAs($this->admin)
            ->postJson("/api/admin/members/{$member->id}/suspend", ['reason' => 'Fake payment'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        $this->assertSame(
            ['from_status' => 'approved', 'reason' => 'Fake payment'],
            $this->audit('member.suspended', $member)->details,
        );

        // Suspending a pending member is refused.
        $pending = User::factory()->create();
        $this->postJson("/api/admin/members/{$pending->id}/suspend")->assertUnprocessable();
    }

    // ---------- Reset password ----------

    public function test_reset_password_returns_temporary_password_once_and_logs_without_it(): void
    {
        $member = User::factory()->approved()->create();

        $response = $this->actingAs($this->admin)
            ->postJson("/api/admin/members/{$member->id}/reset-password", [], ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertJsonPath('message', __('messages.password_reset_done', [], 'en'))
            ->assertJsonPath('data.must_change_password', true);

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $temporary = $response->json('temporary_password');
        $this->assertSame(10, strlen($temporary));

        $member->refresh();
        $this->assertTrue($member->must_change_password);
        $this->assertTrue(Hash::check($temporary, $member->password));

        $log = $this->audit('member.password_reset', $member);
        $this->assertNotNull($log);
        $this->assertStringNotContainsString($temporary, json_encode($log->getAttributes()));

        // The member list never shows it again.
        $this->assertStringNotContainsString($temporary, $this->getJson('/api/admin/members')->getContent());
    }

    // ---------- Role ----------

    public function test_only_super_admin_can_change_roles(): void
    {
        $member = User::factory()->approved()->create();

        $this->actingAs($this->admin)
            ->postJson("/api/admin/members/{$member->id}/role", ['role' => 'committee'])
            ->assertForbidden();
        $this->assertSame('member', $member->refresh()->role);

        $super = User::factory()->superAdmin()->create();
        $this->actingAs($super)
            ->postJson("/api/admin/members/{$member->id}/role", ['role' => 'committee'], ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertJsonPath('message', __('messages.role_changed', [], 'en'))
            ->assertJsonPath('data.role', 'committee');

        $log = $this->audit('member.role_changed', $member);
        $this->assertSame($super->id, $log->actor_id);
        $this->assertSame(['from_role' => 'member', 'to_role' => 'committee'], $log->details);
    }

    public function test_role_change_rules(): void
    {
        $super = User::factory()->superAdmin()->create();
        $pending = User::factory()->create();
        $member = User::factory()->approved()->create();

        $this->actingAs($super)->postJson("/api/admin/members/{$member->id}/role", ['role' => 'king'])
            ->assertJsonValidationErrors(['role']);
        $this->postJson("/api/admin/members/{$pending->id}/role", ['role' => 'committee'])->assertUnprocessable();
        $this->postJson("/api/admin/members/{$member->id}/role", ['role' => 'member'])->assertUnprocessable();
        $this->postJson("/api/admin/members/{$super->id}/role", ['role' => 'member'])->assertForbidden();

        $this->assertSame('super_admin', $super->refresh()->role);
        $this->assertSame(0, AuditLog::count());
    }

    // ---------- Audit log ----------

    public function test_audit_log_is_append_only(): void
    {
        $member = User::factory()->create();
        $this->actingAs($this->admin)->postJson("/api/admin/members/{$member->id}/approve")->assertOk();
        $log = AuditLog::sole();

        try {
            $log->update(['action' => 'changed']);
            $this->fail('Audit log update should throw.');
        } catch (LogicException) {
        }

        try {
            $log->delete();
            $this->fail('Audit log delete should throw.');
        } catch (LogicException) {
        }

        $this->assertSame('member.approved', AuditLog::sole()->action);
    }
}
