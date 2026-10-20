<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CommitteeMember;
use App\Models\CommitteePosition;
use App\Models\User;
use Database\Seeders\DemoProgrammersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Committee panel (Dashboard → Committee): permissions, add / change / order / deactivate / remove, audit, public list. */
class CommitteePanelTest extends TestCase
{
    use RefreshDatabase;

    private const EN = ['Accept' => 'application/json', 'Accept-Language' => 'en'];

    private const BN = ['Accept' => 'application/json', 'Accept-Language' => 'bn'];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', 'http://localhost:3000');
        $this->admin = User::factory()->admin()->create();
    }

    private function position(string $en): int
    {
        return CommitteePosition::where('name_en', $en)->value('id');
    }

    private function add(User $user, array $extra = []): int
    {
        return $this->actingAs($this->admin)->postJson('/api/admin/committee', ['user_id' => $user->id] + $extra, self::EN)
            ->assertCreated()->json('id');
    }

    /** @return list<array<string, mixed>> */
    private function publicList(array $headers = self::EN): array
    {
        auth()->forgetGuards();

        return $this->getJson('/api/public/committee', $headers)->assertOk()->json('data');
    }

    public function test_only_admin_and_super_admin_can_manage(): void
    {
        $member = User::factory()->approved()->create();
        $committee = User::factory()->committee()->create();
        $row = CommitteeMember::create(['user_id' => $member->id, 'display_order' => 1]);
        $pos = $this->position('President');

        $routes = [
            ['getJson', '/api/admin/committee', []],
            ['getJson', '/api/admin/committee/candidates', []],
            ['postJson', '/api/admin/committee', ['user_id' => $member->id]],
            ['patchJson', "/api/admin/committee/{$row->id}", ['is_active' => false]],
            ['putJson', '/api/admin/committee/order', ['ids' => [$row->id]]],
            ['deleteJson', "/api/admin/committee/{$row->id}", []],
            ['postJson', '/api/admin/committee/positions', ['name_en' => 'Auditor', 'name_bn' => 'অডিটর']],
            ['patchJson', "/api/admin/committee/positions/{$pos}", ['name_en' => 'Chair', 'name_bn' => 'চেয়ার']],
            ['deleteJson', "/api/admin/committee/positions/{$pos}", []],
        ];
        $call = fn (string $method, string $url, array $body) => $method === 'getJson'
            ? $this->getJson($url, self::EN)
            : $this->{$method}($url, $body, self::EN);
        foreach ($routes as [$method, $url, $body]) {
            auth()->forgetGuards();
            $call($method, $url, $body)->assertUnauthorized();
            foreach ([User::factory()->approved()->create(), $committee] as $user) {
                $this->actingAs($user);
                $call($method, $url, $body)->assertForbidden();
            }
        }

        // Nothing changed by the refused calls.
        $this->assertSame(1, CommitteeMember::count());
        $this->assertTrue($row->refresh()->is_active);
        $this->assertSame(0, AuditLog::where('action', 'like', 'committee%')->count());

        $this->actingAs($this->admin)->getJson('/api/admin/committee', self::EN)->assertOk();
        $this->actingAs(User::factory()->superAdmin()->create())->getJson('/api/admin/committee', self::EN)->assertOk();
    }

    public function test_adding_a_member_never_changes_the_login_role(): void
    {
        $member = User::factory()->approved()->create();
        $this->add($member, ['position_id' => $this->position('Treasurer')]);
        $this->assertSame(User::ROLE_MEMBER, $member->refresh()->role);

        // A committee-role account that is not on the panel is not listed (the role alone no longer lists anyone),
        // and removing a panel member leaves the role as it was.
        $other = User::factory()->committee()->create();
        $this->assertSame(['name' => [], 'count' => 1], ['name' => [], 'count' => count($this->publicList())]);
        $id = $this->add($other);
        $this->actingAs($this->admin)->deleteJson("/api/admin/committee/{$id}", [], self::EN)->assertOk();
        $this->assertSame(User::ROLE_COMMITTEE, $other->refresh()->role);
    }

    public function test_default_positions_exist_and_candidates_are_approved_members_not_yet_listed(): void
    {
        $names = collect($this->actingAs($this->admin)->getJson('/api/admin/committee', self::EN)->assertOk()->json('positions'))->pluck('name_en');
        foreach (['President', 'Secretary', 'Treasurer', 'Member'] as $n) {
            $this->assertTrue($names->contains($n));
        }

        $ok = User::factory()->approved()->create(['name' => 'Rahim Ok']);
        $listed = User::factory()->approved()->create(['name' => 'Rahim Listed']);
        User::factory()->create(['name' => 'Rahim Pending']);
        User::factory()->suspended()->create(['name' => 'Rahim Suspended']);
        User::factory()->approved()->create(['name' => 'Rahim Demo', 'email' => 'd1@'.DemoProgrammersSeeder::EMAIL_DOMAIN]);
        $this->add($listed);

        $found = $this->actingAs($this->admin)->getJson('/api/admin/committee/candidates?search=Rahim', self::EN)->assertOk()->json('data');
        $this->assertContains($ok->id, array_column($found, 'id'));
        $this->assertNotContains($listed->id, array_column($found, 'id'));
        $this->assertSame(1, count(array_filter($found, fn ($u) => str_starts_with($u['name'], 'Rahim'))));
    }

    public function test_add_validation(): void
    {
        $member = User::factory()->approved()->create();
        foreach ([
            User::factory()->create(),
            User::factory()->suspended()->create(),
            User::factory()->approved()->create(['email' => 'd2@'.DemoProgrammersSeeder::EMAIL_DOMAIN]),
        ] as $bad) {
            $this->actingAs($this->admin)->postJson('/api/admin/committee', ['user_id' => $bad->id], self::EN)
                ->assertUnprocessable()->assertJsonValidationErrors('user_id');
        }
        $this->actingAs($this->admin)->postJson('/api/admin/committee', ['user_id' => 99999], self::EN)->assertUnprocessable();
        $this->actingAs($this->admin)->postJson('/api/admin/committee', ['user_id' => $member->id, 'position_id' => 9999], self::EN)
            ->assertUnprocessable()->assertJsonValidationErrors('position_id');
        $this->actingAs($this->admin)->postJson('/api/admin/committee', ['user_id' => $member->id, 'term_start' => '2026-05-01', 'term_end' => '2026-01-01'], self::EN)
            ->assertUnprocessable()->assertJsonValidationErrors('term_end');
        $this->actingAs($this->admin)->postJson('/api/admin/committee', ['user_id' => $member->id, 'term_start' => 'soon'], self::EN)
            ->assertUnprocessable()->assertJsonValidationErrors('term_start');
        $this->assertSame(0, CommitteeMember::count());

        $this->add($member);
        $this->actingAs($this->admin)->postJson('/api/admin/committee', ['user_id' => $member->id], self::EN)
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->assertSame(1, CommitteeMember::count());
    }

    public function test_change_position_term_and_active_with_optional_reason_audited(): void
    {
        $user = User::factory()->approved()->create(['name' => 'Karim']);
        $id = $this->add($user, ['position_id' => $this->position('Member'), 'term_start' => '2026-01-01', 'reason' => 'Elected']);

        $added = AuditLog::where('action', 'committee.member_added')->sole();
        $this->assertSame($user->id, $added->target_id);
        $this->assertSame('Member', $added->details['position']);
        $this->assertSame('Elected', $added->details['reason']);
        $this->assertSame($this->admin->id, $added->actor_id);

        $this->actingAs($this->admin)->patchJson("/api/admin/committee/{$id}", [
            'position_id' => $this->position('President'), 'term_end' => '2028-12-31', 'reason' => 'Promoted',
        ], self::EN)->assertOk()->assertJsonPath('data.0.position.name_en', 'President')->assertJsonPath('data.0.term_end', '2028-12-31');
        $log = AuditLog::where('action', 'committee.member_updated')->sole();
        $this->assertSame(['old' => 'Member', 'new' => 'President'], $log->details['changes']['position']);
        $this->assertSame(['old' => null, 'new' => '2028-12-31'], $log->details['changes']['term_end']);
        $this->assertSame('Promoted', $log->details['reason']);

        // The reason is optional.
        $this->actingAs($this->admin)->patchJson("/api/admin/committee/{$id}", ['is_active' => false], self::EN)->assertOk();
        $this->assertArrayNotHasKey('reason', AuditLog::where('action', 'committee.member_updated')->orderByDesc('id')->first()->details);

        // Nothing changed → no new audit entry; bad term → refused.
        $this->actingAs($this->admin)->patchJson("/api/admin/committee/{$id}", ['is_active' => false], self::EN)->assertOk();
        $this->assertSame(2, AuditLog::where('action', 'committee.member_updated')->count());
        $this->actingAs($this->admin)->patchJson("/api/admin/committee/{$id}", ['term_end' => '2025-01-01'], self::EN)->assertUnprocessable();
        $this->actingAs($this->admin)->patchJson("/api/admin/committee/{$id}", ['reason' => str_repeat('x', 301), 'is_active' => true], self::EN)->assertUnprocessable();
    }

    public function test_order_and_remove_are_audited(): void
    {
        $a = $this->add(User::factory()->approved()->create(['name' => 'A']));
        $b = $this->add(User::factory()->approved()->create(['name' => 'B']));
        $c = $this->add(User::factory()->approved()->create(['name' => 'C']));

        $this->actingAs($this->admin)->putJson('/api/admin/committee/order', ['ids' => [$c, $a, $b], 'reason' => 'Seniority'], self::EN)
            ->assertOk()->assertJsonPath('data.0.id', $c);
        $log = AuditLog::where('action', 'committee.reordered')->sole();
        $this->assertSame([$c, $a, $b], $log->details['order']);
        $this->assertSame('Seniority', $log->details['reason']);
        $this->assertSame(['C', 'A', 'B'], array_column($this->publicList(), 'name'));

        foreach ([[$a, $b], [$a, $b, $c, 9999], [$a, $a, $b, $c]] as $bad) {
            $this->actingAs($this->admin)->putJson('/api/admin/committee/order', ['ids' => $bad], self::EN)->assertUnprocessable();
        }
        $this->assertSame(1, AuditLog::where('action', 'committee.reordered')->count());

        $this->actingAs($this->admin)->deleteJson("/api/admin/committee/{$a}", ['reason' => 'Resigned'], self::EN)->assertOk();
        $removed = AuditLog::where('action', 'committee.member_removed')->sole();
        $this->assertSame('Resigned', $removed->details['reason']);
        $this->assertSame(2, CommitteeMember::count());
        $this->actingAs($this->admin)->deleteJson("/api/admin/committee/{$a}", [], self::EN)->assertNotFound();
        $this->assertSame(['C', 'B'], array_column($this->publicList(), 'name'));
    }

    public function test_position_list_is_editable_and_audited(): void
    {
        $r = $this->actingAs($this->admin)->postJson('/api/admin/committee/positions', ['name_en' => ' Auditor ', 'name_bn' => 'অডিটর', 'reason' => 'New role'], self::EN)
            ->assertCreated();
        $id = $r->json('id');
        $this->assertSame('Auditor', CommitteePosition::findOrFail($id)->name_en);
        $this->assertSame('New role', AuditLog::where('action', 'committee_position.created')->sole()->details['reason']);

        // Duplicate / empty / too long names are refused.
        $this->actingAs($this->admin)->postJson('/api/admin/committee/positions', ['name_en' => 'Auditor', 'name_bn' => 'x'], self::EN)->assertUnprocessable()->assertJsonValidationErrors('name_en');
        $this->actingAs($this->admin)->postJson('/api/admin/committee/positions', ['name_en' => '', 'name_bn' => ''], self::EN)->assertUnprocessable();
        $this->actingAs($this->admin)->postJson('/api/admin/committee/positions', ['name_en' => str_repeat('a', 61), 'name_bn' => 'x'], self::EN)->assertUnprocessable();

        $this->actingAs($this->admin)->patchJson("/api/admin/committee/positions/{$id}", ['name_en' => 'Chief Auditor', 'name_bn' => 'প্রধান অডিটর'], self::EN)->assertOk();
        $this->assertSame(['old' => 'Auditor', 'new' => 'Chief Auditor'], AuditLog::where('action', 'committee_position.updated')->sole()->details['changes']['name_en']);

        // A position held by someone cannot be deleted; an unused one can.
        $user = User::factory()->approved()->create();
        $this->add($user, ['position_id' => $id]);
        $this->actingAs($this->admin)->deleteJson("/api/admin/committee/positions/{$id}", [], self::EN)->assertUnprocessable();
        $this->assertNotNull(CommitteePosition::find($id));
        $free = CommitteePosition::create(['name_en' => 'Spare', 'name_bn' => 'অতিরিক্ত', 'sort_order' => 99]);
        $this->actingAs($this->admin)->deleteJson("/api/admin/committee/positions/{$free->id}", [], self::EN)->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'committee_position.deleted')->count());
    }

    public function test_public_list_uses_the_panel_active_only_and_public_safe_fields(): void
    {
        $shown = User::factory()->approved()->create([
            'name' => 'Karim', 'phone' => '01755555555', 'email' => 'karim@example.com',
            'photo_path' => 'photos/karim.jpg', 'district' => 'dhaka', 'workplace' => 'Factory X',
        ]);
        $inactive = User::factory()->approved()->create(['name' => 'Inactive']);
        $suspended = User::factory()->approved()->create(['name' => 'Suspended']);
        $demo = User::factory()->approved()->create(['name' => 'Demo', 'email' => 'd3@'.DemoProgrammersSeeder::EMAIL_DOMAIN]);
        User::factory()->committee()->create(['name' => 'Role only']);   // committee role, not on the panel

        $this->add($shown, ['position_id' => $this->position('President')]);
        $inactiveRow = $this->add($inactive);
        $this->add($suspended);
        CommitteeMember::create(['user_id' => $demo->id, 'display_order' => 9]);
        $this->actingAs($this->admin)->patchJson("/api/admin/committee/{$inactiveRow}", ['is_active' => false], self::EN)->assertOk();
        $suspended->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $list = $this->publicList();
        $this->assertSame(['Karim'], array_column($list, 'name'));
        $this->assertSame(['name', 'photo_url', 'position', 'profile_id'], array_keys($list[0]));
        $this->assertSame('President', $list[0]['position']);
        $this->assertStringEndsWith('photos/karim.jpg', $list[0]['photo_url']);
        $text = json_encode($list);
        foreach (['01755555555', 'karim@example.com', 'Factory X', 'dhaka', '"id"', 'term_', 'is_active'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }

        // Bangla position name for Bangla visitors; reactivating shows the person again at once (cache cleared).
        $this->assertSame('সভাপতি', $this->publicList(self::BN)[0]['position']);
        $this->actingAs($this->admin)->patchJson("/api/admin/committee/{$inactiveRow}", ['is_active' => true], self::EN)->assertOk();
        $this->assertSame(['Karim', 'Inactive'], array_column($this->publicList(), 'name'));
    }

    public function test_existing_committee_login_permissions_are_unchanged(): void
    {
        $committee = User::factory()->committee()->create();
        $this->add($committee);

        // Still reads the Programmers List, still cannot use any admin-only area.
        $this->actingAs($committee)->getJson('/api/admin/members', self::EN)->assertOk();
        $this->actingAs($committee)->getJson('/api/admin/audit-log', self::EN)->assertForbidden();
        $this->actingAs($committee)->getJson('/api/admin/site-settings', self::EN)->assertForbidden();

        // A plain member on the panel gains no permission.
        $member = User::factory()->approved()->create();
        $this->add($member);
        $this->actingAs($member)->getJson('/api/admin/members', self::EN)->assertForbidden();
        $this->actingAs($member)->getJson('/api/admin/committee', self::EN)->assertForbidden();
    }
}
