<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Admin Contact Messages + Audit Log (Phase 11a). */
class AdminContactAuditTest extends TestCase
{
    use RefreshDatabase;

    private const EN = ['Accept' => 'application/json', 'Accept-Language' => 'en'];

    private function contact(array $data = []): ContactMessage
    {
        return ContactMessage::create([
            'name' => 'Visitor',
            'phone' => '01711111111',
            'subject' => 'Question',
            'message' => 'Secret message text',
            ...$data,
        ]);
    }

    private function log(?User $actor, string $action, array $extra = []): AuditLog
    {
        return AuditLog::create(['actor_id' => $actor?->id, 'action' => $action, 'ip_address' => '127.0.0.1', ...$extra]);
    }

    public function test_only_admins_reach_contact_messages_and_audit_log(): void
    {
        $m = $this->contact();
        $urls = ['/api/admin/contact-messages', "/api/admin/contact-messages/{$m->id}", '/api/admin/audit-log', '/api/admin/audit-log/actions'];
        foreach ($urls as $url) {
            $this->getJson($url, self::EN)->assertUnauthorized();
        }
        foreach ($urls as $url) {
            foreach ([User::factory()->approved()->create(), User::factory()->approved()->committee()->create()] as $user) {
                $this->actingAs($user)->getJson($url, self::EN)->assertForbidden();
            }
            $this->actingAs(User::factory()->approved()->admin()->create())->getJson($url, self::EN)->assertOk();
            $this->actingAs(User::factory()->approved()->superAdmin()->create())->getJson($url, self::EN)->assertOk();
        }

        $member = User::factory()->approved()->committee()->create();
        $this->actingAs($member)->postJson("/api/admin/contact-messages/{$m->id}/read", [], self::EN)->assertForbidden();
        $this->actingAs($member)->deleteJson("/api/admin/contact-messages/{$m->id}", [], self::EN)->assertForbidden();
        $this->assertFalse($m->fresh()->is_read);
    }

    public function test_contact_list_filters_search_and_unread_count(): void
    {
        $admin = User::factory()->approved()->admin()->create();
        $a = $this->contact(['name' => 'Rahim', 'phone' => '01712345678', 'subject' => 'Membership']);
        $b = $this->contact(['name' => 'Karim', 'phone' => '01898765432', 'subject' => 'Aid help']);
        $b->forceFill(['is_read' => true])->save();

        $this->actingAs($admin)->getJson('/api/admin/contact-messages', self::EN)->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.unread_count', 1)
            ->assertJsonPath('data.0.id', $b->id) // newest first
            ->assertJsonPath('data.1.message', 'Secret message text');

        $this->actingAs($admin)->getJson('/api/admin/contact-messages?status=unread', self::EN)
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $a->id);
        $this->actingAs($admin)->getJson('/api/admin/contact-messages?status=read', self::EN)
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $b->id);
        $this->actingAs($admin)->getJson('/api/admin/contact-messages?search=aid', self::EN)
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $b->id);
        $this->actingAs($admin)->getJson('/api/admin/contact-messages?search=Rah', self::EN)
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $a->id);
        // Mobile typed with Bengali digits
        $this->actingAs($admin)->getJson('/api/admin/contact-messages?search='.urlencode('০১৭১২৩'), self::EN)
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $a->id);
        $this->actingAs($admin)->getJson('/api/admin/contact-messages?status=bad', self::EN)->assertUnprocessable();
        $this->actingAs($admin)->getJson('/api/admin/contact-messages/999999', self::EN)->assertNotFound();
    }

    public function test_mark_read_and_unread(): void
    {
        $admin = User::factory()->approved()->admin()->create();
        $m = $this->contact();

        $this->actingAs($admin)->postJson("/api/admin/contact-messages/{$m->id}/read", [], self::EN)->assertOk()
            ->assertJsonPath('data.is_read', true)->assertJsonPath('meta.unread_count', 0);
        $this->assertTrue($m->fresh()->is_read);

        $this->actingAs($admin)->postJson("/api/admin/contact-messages/{$m->id}/unread", [], self::EN)->assertOk()
            ->assertJsonPath('data.is_read', false)->assertJsonPath('meta.unread_count', 1);
        $this->assertFalse($m->fresh()->is_read);
    }

    public function test_only_super_admin_deletes_and_audit_has_no_message_text(): void
    {
        $m = $this->contact();

        $this->actingAs(User::factory()->approved()->admin()->create())
            ->deleteJson("/api/admin/contact-messages/{$m->id}", [], self::EN)->assertForbidden();
        $this->assertNotNull($m->fresh());

        $super = User::factory()->approved()->superAdmin()->create();
        $this->actingAs($super)->deleteJson("/api/admin/contact-messages/{$m->id}", [], self::EN)->assertOk()
            ->assertJsonPath('message', 'Message deleted.')->assertJsonPath('meta.unread_count', 0);
        $this->assertNull($m->fresh());

        $log = AuditLog::where('action', 'contact_message.deleted')->sole();
        $this->assertSame($super->id, $log->actor_id);
        $this->assertSame('Visitor', $log->details['name']);
        $this->assertStringNotContainsString('Secret message text', json_encode($log->details));
    }

    public function test_audit_log_filters_and_is_read_only(): void
    {
        $admin = User::factory()->approved()->admin()->create(['name' => 'Admin Ali', 'phone' => '01710000001']);
        $other = User::factory()->approved()->superAdmin()->create(['name' => 'Boss Bari', 'phone' => '01710000002']);
        $member = User::factory()->approved()->create(['name' => 'Member Mina']);

        $old = $this->log($admin, 'member.approved', ['target_type' => 'User', 'target_id' => $member->id]);
        DB::table('audit_logs')->where('id', $old->id)->update(['created_at' => Carbon::parse('2026-01-05 10:00')]);
        $this->log($other, 'notice.created', ['details' => ['title' => 'Hello', 'changes' => ['password' => ['from' => 'a', 'to' => 'b']]]]);
        $this->log(null, 'member.approved');

        $this->actingAs($admin)->getJson('/api/admin/audit-log', self::EN)->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.actor', null) // newest first; no actor
            ->assertJsonPath('data.1.actor.name', 'Boss Bari')
            ->assertJsonPath('data.1.details.title', 'Hello')
            ->assertJsonPath('data.1.details.changes.password', '••••')
            ->assertJsonPath('data.2.target_name', 'Member Mina')
            ->assertJsonPath('data.2.ip_address', '127.0.0.1');

        $this->actingAs($admin)->getJson('/api/admin/audit-log?actor=Boss', self::EN)
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.action', 'notice.created');
        $this->actingAs($admin)->getJson('/api/admin/audit-log?actor=0001', self::EN)
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.action', 'member.approved');
        $this->actingAs($admin)->getJson('/api/admin/audit-log?action=member.approved', self::EN)->assertJsonPath('meta.total', 2);
        $this->actingAs($admin)->getJson('/api/admin/audit-log?from=2026-01-05&to=2026-01-05', self::EN)
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $old->id);
        $this->actingAs($admin)->getJson('/api/admin/audit-log?from=2026-01-06', self::EN)->assertJsonPath('meta.total', 2);
        $this->actingAs($admin)->getJson('/api/admin/audit-log?from=2026-02-01&to=2026-01-01', self::EN)->assertUnprocessable();
        $this->actingAs($admin)->getJson('/api/admin/audit-log?from=05-01-2026', self::EN)->assertUnprocessable();

        $this->actingAs($admin)->getJson('/api/admin/audit-log/actions', self::EN)->assertOk()
            ->assertExactJson(['data' => ['member.approved', 'notice.created']]);

        // No way to change or delete entries.
        $this->actingAs($other)->deleteJson("/api/admin/audit-log/{$old->id}", [], self::EN)->assertNotFound();
        $this->actingAs($other)->patchJson("/api/admin/audit-log/{$old->id}", ['action' => 'x'], self::EN)->assertNotFound();
        $this->assertSame(3, AuditLog::count());
    }
}
