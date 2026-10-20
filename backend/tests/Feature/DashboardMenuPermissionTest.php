<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 11d-2 panel review: every dashboard page's API follows the sidebar menu per role
 * (frontend components/dashboard/dashboard-nav.ts). A page hidden from a role must answer 403.
 */
class DashboardMenuPermissionTest extends TestCase
{
    use RefreshDatabase;

    private const EN = ['Accept' => 'application/json', 'Accept-Language' => 'en'];

    private const ALL = ['member', 'committee', 'admin', 'super_admin'];

    private const READERS = ['committee', 'admin', 'super_admin'];

    private const ADMINS = ['admin', 'super_admin'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', 'http://localhost:3000');
    }

    /** @return array<string, User> */
    private function users(): array
    {
        return [
            'member' => User::factory()->approved()->create(),
            'committee' => User::factory()->committee()->create(),
            'admin' => User::factory()->admin()->create(),
            'super_admin' => User::factory()->superAdmin()->create(),
        ];
    }

    public function test_each_dashboard_page_api_matches_the_menu_roles(): void
    {
        $users = $this->users();
        $memberId = $users['member']->id;

        // [dashboard page, API read used by that page, roles that see the page in the menu]
        $pages = [
            ['/dashboard', '/api/dashboard/summary', self::ALL],
            ['/dashboard/programmers', '/api/admin/members', self::READERS],
            ['/dashboard/programmers/{id}', "/api/admin/members/{$memberId}/payments", self::READERS],
            ['/dashboard (monthly collection)', '/api/admin/fund/monthly', self::READERS],
            ['/dashboard/payments', '/api/contributions/dues', ['member', 'committee']],
            ['/dashboard/payment-review', '/api/admin/contributions', self::ADMINS],
            ['/dashboard/assistance', '/api/assistance', self::ALL],
            ['/dashboard/aid', '/api/admin/assistance', self::ADMINS],
            ['/dashboard/chat', '/api/chat/conversations', self::ALL],
            ['/dashboard/chat/blocked', '/api/chat/blocks', self::ALL],
            ['/dashboard/chat-reports', '/api/admin/chat-reports', self::ADMINS],
            ['/dashboard/hero-slides', '/api/admin/hero-slides', self::ADMINS],
            ['/dashboard/featured-programmers', '/api/admin/featured-programmers', self::ADMINS],
            ['/dashboard/expertise-cards', '/api/admin/expertise-cards', self::ADMINS],
            ['/dashboard/gallery', '/api/admin/gallery/images', self::ADMINS],
            ['/dashboard/notices', '/api/admin/notices', self::ADMINS],
            ['/dashboard/site-content', '/api/admin/site-content', self::ADMINS],
            ['/dashboard/site-settings', '/api/admin/site-settings', self::ADMINS],
            ['/dashboard/committee', '/api/admin/committee', self::ADMINS],
            ['/dashboard/lists', '/api/admin/profile-lists', self::ADMINS],
            ['/dashboard/messages', '/api/admin/contact-messages', self::ADMINS],
            ['/dashboard/audit-log', '/api/admin/audit-log', self::ADMINS],
            ['/dashboard/settings', '/api/admin/settings', ['super_admin']],
            ['/dashboard/profile', '/api/profile', self::ALL],
            ['/dashboard/notifications', '/api/notifications', self::ALL],
        ];

        // Guests first (actingAs below keeps the login for the rest of the test).
        foreach ($pages as [, $url]) {
            $this->getJson($url, self::EN)->assertUnauthorized();
        }
        foreach ($pages as [$page, $url, $roles]) {
            foreach ($users as $role => $user) {
                $status = $this->actingAs($user)->getJson($url, self::EN)->status();
                $expected = in_array($role, $roles, true) ? 200 : 403;
                $this->assertSame($expected, $status, "{$page} ({$url}) as {$role}");
            }
        }
    }

    public function test_write_actions_hidden_from_a_role_are_refused(): void
    {
        $users = $this->users();
        $target = User::factory()->create(); // pending registration

        // Member Approvals actions: admins only (committee reads the list for the Programmers List page).
        foreach (['member', 'committee'] as $role) {
            $this->actingAs($users[$role])->postJson("/api/admin/members/{$target->id}/approve", [], self::EN)->assertForbidden();
        }
        // Super Admin only: role change, deleting contact messages and list items.
        foreach (['member', 'committee', 'admin'] as $role) {
            $this->actingAs($users[$role])->postJson("/api/admin/members/{$target->id}/role", ['role' => 'committee'], self::EN)->assertForbidden();
            $this->actingAs($users[$role])->deleteJson('/api/admin/contact-messages/999999', [], self::EN)->assertForbidden();
            $this->actingAs($users[$role])->deleteJson('/api/admin/profile-lists/999999', [], self::EN)->assertForbidden();
        }
        $this->assertSame('pending', $target->fresh()->status);
        $this->assertSame('member', $target->fresh()->role);
    }
}
