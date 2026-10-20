<?php

namespace Tests\Feature;

use App\Events\CommitteeChatMessageSent;
use App\Models\CommitteeChatMessage;
use App\Models\CommitteeMember;
use App\Models\User;
use App\Notifications\CommitteeChatPushAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Private Committee Chat: access (403 for everyone else), panel sync, attachments, unread badge, live + push. */
class CommitteeChatTest extends TestCase
{
    use RefreshDatabase;

    private const EN = ['Accept' => 'application/json', 'Accept-Language' => 'en'];

    private User $admin;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', 'http://localhost:3000');
        Storage::fake('private');
        Event::fake([CommitteeChatMessageSent::class]);
        $this->admin = User::factory()->admin()->create(['name' => 'Admin']);
        $this->super = User::factory()->superAdmin()->create(['name' => 'Boss']);
    }

    /** A panel member (active unless said otherwise); $role is their login role (committee by default). */
    private function panelMember(string $name = 'Committee One', bool $active = true, string $role = User::ROLE_COMMITTEE): User
    {
        $user = User::factory()->approved()->create(['name' => $name, 'role' => $role]);
        CommitteeMember::create(['user_id' => $user->id, 'display_order' => 1, 'is_active' => $active]);

        return $user;
    }

    private function say(User $user, string $text = 'Hello committee')
    {
        return $this->actingAs($user)->postJson('/api/committee-chat/messages', ['body' => $text], self::EN);
    }

    public function test_only_active_panel_members_and_admins_can_use_every_route(): void
    {
        $in = $this->panelMember();
        $msg = CommitteeChatMessage::create(['sender_id' => $in->id, 'body' => 'x', 'attachment_path' => 'committee-chat/a.png', 'attachment_name' => 'a.png', 'attachment_mime' => 'image/png', 'attachment_size' => 1]);
        Storage::disk('private')->put('committee-chat/a.png', 'png');

        $routes = [
            ['getJson', '/api/committee-chat/messages'],
            ['postJson', '/api/committee-chat/messages'],
            ['postJson', '/api/committee-chat/read'],
            ['getJson', '/api/committee-chat/unread'],
            ['getJson', "/api/committee-chat/messages/{$msg->id}/attachment"],
        ];
        $outsiders = [
            'plain member' => User::factory()->approved()->create(),
            'committee role, not on the panel' => User::factory()->committee()->create(),
            'inactive panel member' => $this->panelMember('Inactive', false),
            'suspended panel member' => tap($this->panelMember('Susp'), fn (User $u) => $u->forceFill(['status' => User::STATUS_SUSPENDED])->save()),
            'pending admin-role account' => User::factory()->create(['role' => User::ROLE_ADMIN]),
        ];
        $call = fn (string $method, string $url) => $method === 'getJson'
            ? $this->getJson($url, self::EN)
            : $this->postJson($url, ['body' => 'hi'], self::EN);
        foreach ($routes as [$method, $url]) {
            auth()->forgetGuards();
            $call($method, $url)->assertUnauthorized();
            foreach ($outsiders as $user) {
                $this->actingAs($user);
                $call($method, $url)->assertForbidden();
            }
        }
        $this->assertSame(1, CommitteeChatMessage::count(), 'refused users must not have written anything');

        $this->actingAs($in)->getJson('/api/committee-chat/messages', self::EN)->assertOk();
        $this->actingAs($this->admin)->getJson('/api/committee-chat/messages', self::EN)->assertOk();
        $this->actingAs($this->super)->getJson('/api/committee-chat/messages', self::EN)->assertOk();
        // A plain "member" login that sits on the panel is in too (the panel decides, not the role).
        $this->actingAs($this->panelMember('Plain On Panel', true, User::ROLE_MEMBER))->getJson('/api/committee-chat/messages', self::EN)->assertOk();
    }

    public function test_panel_changes_apply_immediately_including_history(): void
    {
        $member = $this->panelMember('Karim');
        $this->say($this->admin, 'secret plans')->assertCreated();
        $this->actingAs($member)->getJson('/api/committee-chat/messages', self::EN)->assertOk()->assertJsonPath('data.0.body', 'secret plans');
        $row = CommitteeMember::where('user_id', $member->id)->first();

        // Deactivated in the panel → out at once, history included; back when reactivated.
        $this->actingAs($this->admin)->patchJson("/api/admin/committee/{$row->id}", ['is_active' => false], self::EN)->assertOk();
        $this->actingAs($member)->getJson('/api/committee-chat/messages', self::EN)->assertForbidden();
        $this->say($member)->assertForbidden();
        $this->actingAs($this->admin)->patchJson("/api/admin/committee/{$row->id}", ['is_active' => true], self::EN)->assertOk();
        $this->actingAs($member)->getJson('/api/committee-chat/messages', self::EN)->assertOk();

        // Removed from the panel → out; a newly added approved member → in.
        $this->actingAs($this->admin)->deleteJson("/api/admin/committee/{$row->id}", [], self::EN)->assertOk();
        $this->actingAs($member)->getJson('/api/committee-chat/messages', self::EN)->assertForbidden();
        $newcomer = User::factory()->approved()->create();
        $this->actingAs($newcomer)->getJson('/api/committee-chat/messages', self::EN)->assertForbidden();
        $this->actingAs($this->admin)->postJson('/api/admin/committee', ['user_id' => $newcomer->id], self::EN)->assertCreated();
        $this->actingAs($newcomer)->getJson('/api/committee-chat/messages', self::EN)->assertOk();
    }

    public function test_text_messages_with_sender_and_validation(): void
    {
        $member = $this->panelMember('Karim');

        $this->say($member, '  Salam  ')->assertCreated()
            ->assertJsonPath('data.body', 'Salam')->assertJsonPath('data.mine', true)->assertJsonPath('data.sender.name', 'Karim')
            ->assertJsonPath('data.attachment', null);
        $this->actingAs($this->admin)->getJson('/api/committee-chat/messages', self::EN)->assertOk()
            ->assertJsonPath('data.0.mine', false)->assertJsonPath('data.0.sender.name', 'Karim')->assertJsonPath('meta.members', 3);

        $this->actingAs($member)->postJson('/api/committee-chat/messages', ['body' => '   '], self::EN)->assertUnprocessable();
        $this->actingAs($member)->postJson('/api/committee-chat/messages', [], self::EN)->assertUnprocessable();
        $this->actingAs($member)->postJson('/api/committee-chat/messages', ['body' => str_repeat('a', 2001)], self::EN)->assertUnprocessable();
        $this->assertSame(1, CommitteeChatMessage::count());
    }

    public function test_attachments_use_the_same_upload_checks_and_are_members_only(): void
    {
        $member = $this->panelMember();
        $png = UploadedFile::fake()->image('photo.png', 40, 40);

        $id = $this->actingAs($member)->post('/api/committee-chat/messages', ['attachment' => $png, 'body' => 'look'], self::EN)
            ->assertCreated()->assertJsonPath('data.attachment.is_image', true)->assertJsonPath('data.attachment.name', 'photo.png')->json('data.id');
        $this->actingAs($member)->post('/api/committee-chat/messages', ['attachment' => UploadedFile::fake()->create('minutes.pdf', 200, 'application/pdf')], self::EN)
            ->assertCreated()->assertJsonPath('data.attachment.is_image', false)->assertJsonPath('data.body', null);

        // Wrong type / disguised file / too big are refused and nothing is stored.
        foreach ([
            UploadedFile::fake()->create('run.exe', 10, 'application/x-msdownload'),
            UploadedFile::fake()->create('shell.php', 10, 'text/x-php'),
            UploadedFile::fake()->create('photo.png.php', 10, 'image/png'),
            UploadedFile::fake()->create('big.pdf', 5121, 'application/pdf'),
        ] as $bad) {
            $this->actingAs($member)->post('/api/committee-chat/messages', ['attachment' => $bad], self::EN)->assertUnprocessable();
        }
        $this->assertSame(2, CommitteeChatMessage::count());
        $this->assertCount(2, Storage::disk('private')->allFiles('committee-chat'));

        // Stored privately; only chat members can open it.
        $this->actingAs($this->admin)->get("/api/committee-chat/messages/{$id}/attachment")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        auth()->forgetGuards();
        $this->get("/api/committee-chat/messages/{$id}/attachment", self::EN)->assertUnauthorized();
        $this->actingAs(User::factory()->approved()->create())->get("/api/committee-chat/messages/{$id}/attachment", self::EN)->assertForbidden();
        $this->actingAs(User::factory()->committee()->create())->get("/api/committee-chat/messages/{$id}/attachment", self::EN)->assertForbidden();
    }

    public function test_unread_badge_and_badge_counts_endpoint(): void
    {
        $member = $this->panelMember();
        $this->actingAs($this->admin)->getJson('/api/committee-chat/unread', self::EN)->assertOk()->assertJsonPath('data.unread_total', 0);
        $this->actingAs($member)->getJson('/api/committee-chat/unread', self::EN)->assertOk()->assertJsonPath('data.unread_total', 0);

        $this->say($member, 'one')->assertCreated();
        $this->say($member, 'two')->assertCreated();

        // Own messages never count; others' do (also in the admin badge-counts).
        $this->actingAs($member)->getJson('/api/committee-chat/unread', self::EN)->assertJsonPath('data.unread_total', 0);
        $this->actingAs($this->admin)->getJson('/api/committee-chat/unread', self::EN)->assertJsonPath('data.unread_total', 2);
        $this->actingAs($this->admin)->getJson('/api/admin/badge-counts', self::EN)->assertOk()->assertJsonPath('data.committee_chat_unread', 2);

        // Answering counts as having read everything before it.
        $this->say($this->admin, 'three')->assertCreated();
        $this->actingAs($this->admin)->getJson('/api/admin/badge-counts', self::EN)->assertJsonPath('data.committee_chat_unread', 0);
        $this->actingAs($member)->getJson('/api/committee-chat/unread', self::EN)->assertJsonPath('data.unread_total', 1);
        $this->actingAs($this->super)->getJson('/api/admin/badge-counts', self::EN)->assertJsonPath('data.committee_chat_unread', 3);

        $this->actingAs($this->super)->postJson('/api/committee-chat/read', [], self::EN)->assertOk()->assertJsonPath('data.unread_total', 0);
        $this->actingAs($this->super)->getJson('/api/admin/badge-counts', self::EN)->assertJsonPath('data.committee_chat_unread', 0);

        // The own-account flag decides the menu item.
        $this->actingAs($member)->getJson('/api/me', self::EN)->assertJsonPath('data.committee_chat', true);
        $this->actingAs(User::factory()->committee()->create())->getJson('/api/me', self::EN)->assertJsonPath('data.committee_chat', false);
        $this->actingAs(User::factory()->approved()->create())->getJson('/api/me', self::EN)->assertJsonPath('data.committee_chat', false);
    }

    public function test_paging(): void
    {
        for ($i = 1; $i <= 35; $i++) {
            CommitteeChatMessage::create(['sender_id' => $this->admin->id, 'body' => "m{$i}"]);
        }
        $first = $this->actingAs($this->super)->getJson('/api/committee-chat/messages', self::EN)->assertOk();
        $this->assertCount(30, $first->json('data'));
        $first->assertJsonPath('meta.has_more', true)->assertJsonPath('data.29.body', 'm35');
        $before = $first->json('data.0.id');
        $this->actingAs($this->super)->getJson("/api/committee-chat/messages?before={$before}", self::EN)->assertOk()
            ->assertJsonCount(5, 'data')->assertJsonPath('meta.has_more', false);
    }

    public function test_live_delivery_goes_only_to_current_members(): void
    {
        $in = $this->panelMember('In');
        $out = $this->panelMember('Out', false);
        $outsider = User::factory()->committee()->create();

        $this->say($in)->assertCreated();

        Event::assertDispatched(CommitteeChatMessageSent::class, function (CommitteeChatMessageSent $e) use ($in, $out, $outsider) {
            $channels = collect($e->broadcastOn())->map(fn ($c) => $c->name)->all();

            return in_array('private-App.Models.User.'.$in->id, $channels, true)
                && in_array('private-App.Models.User.'.$this->admin->id, $channels, true)
                && in_array('private-App.Models.User.'.$this->super->id, $channels, true)
                && ! in_array('private-App.Models.User.'.$out->id, $channels, true)
                && ! in_array('private-App.Models.User.'.$outsider->id, $channels, true)
                && $e->broadcastAs() === 'committee_chat.message';
        });
    }

    public function test_push_goes_to_other_current_members_with_push_on(): void
    {
        Notification::fake();
        $sender = $this->panelMember('Sender');
        $other = $this->panelMember('Other');
        $inactive = $this->panelMember('Inactive', false);
        $outsider = User::factory()->committee()->create();
        foreach ([$sender, $other, $inactive, $outsider, $this->admin] as $u) {
            $u->updatePushSubscription('https://push.example/'.$u->id, 'BPublicKeyValue', 'AuthSecret');
            $u->forceFill(['push_enabled' => true, 'push_locale' => 'en'])->save();
        }

        $this->say($sender, 'secret text')->assertCreated();

        Notification::assertSentTo([$other, $this->admin], CommitteeChatPushAlert::class);
        Notification::assertNotSentTo([$sender, $inactive, $outsider], CommitteeChatPushAlert::class);
        $alert = new CommitteeChatPushAlert('Sender');
        $payload = json_encode($alert->toWebPush($other->fresh(), $alert)->toArray(), JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('Sender wrote in the Committee Chat', $payload);
        $this->assertStringContainsString('/dashboard/committee-chat', $payload);
        $this->assertStringNotContainsString('secret text', $payload);
    }

    public function test_normal_member_chat_is_not_affected(): void
    {
        $a = User::factory()->approved()->create();
        $b = User::factory()->approved()->create();
        $id = $this->actingAs($a)->postJson('/api/chat/conversations', ['user_id' => $b->id], self::EN)->assertCreated()->json('data.id');
        $this->actingAs($a)->postJson("/api/chat/conversations/{$id}/messages", ['body' => 'private hi'], self::EN)->assertCreated();
        $this->say($this->admin, 'committee only')->assertCreated();

        // The group chat is not a conversation, and a 1-to-1 unread total ignores it.
        $this->actingAs($b)->getJson('/api/chat/unread', self::EN)->assertJsonPath('data.unread_total', 1);
        $this->actingAs($this->super)->getJson('/api/chat/conversations', self::EN)->assertOk()->assertJsonPath('data', []);
        $this->actingAs($this->super)->getJson("/api/chat/conversations/{$id}", self::EN)->assertForbidden();
    }
}
