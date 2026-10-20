<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Notice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NoticeTest extends TestCase
{
    use RefreshDatabase;

    private const EN = ['Accept' => 'application/json', 'Accept-Language' => 'en'];

    private const BN = ['Accept' => 'application/json', 'Accept-Language' => 'bn'];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('private');
        $this->admin = User::factory()->admin()->create();
    }

    /** A notice straight in the database (published "public", dated a minute ago unless overridden). */
    private function notice(array $attributes = []): Notice
    {
        $notice = new Notice([
            'title_bn' => 'নোটিশ',
            'body_bn' => 'বিস্তারিত',
            'visibility' => Notice::VISIBILITY_PUBLIC,
            'is_published' => true,
            'is_pinned' => false,
            'published_at' => now()->subMinute(),
            ...$attributes,
        ]);
        $notice->save();

        return $notice;
    }

    private function create(array $fields = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin)->post('/api/admin/notices', [
            'title_bn' => 'সাধারণ সভা',
            'body_bn' => 'আগামী শুক্রবার সাধারণ সভা।',
            ...$fields,
        ], self::EN);
    }

    public function test_admin_creates_notice_with_attachment_and_it_is_logged(): void
    {
        $response = $this->create([
            'title_bn' => '  সাধারণ সভা ',
            'title_en' => 'General meeting',
            'body_en' => '',
            'attachment' => UploadedFile::fake()->create('agenda.pdf', 300, 'application/pdf'),
        ])->assertCreated()
            ->assertJsonPath('message', __('messages.notice_created', [], 'en'))
            ->assertJsonPath('data.title_bn', 'সাধারণ সভা')
            ->assertJsonPath('data.title_en', 'General meeting')
            ->assertJsonPath('data.body_en', null)
            ->assertJsonPath('data.visibility', 'public')
            ->assertJsonPath('data.is_published', true)
            ->assertJsonPath('data.is_pinned', false)
            ->assertJsonPath('data.attachment.name', 'agenda.pdf')
            ->assertJsonPath('data.attachment.is_image', false);

        $notice = Notice::sole();
        $this->assertNotNull($notice->published_at);
        $this->assertStringStartsWith('notices/', $notice->attachment_path);
        $this->assertStringNotContainsString('agenda', $notice->attachment_path);
        Storage::disk('public')->assertExists($notice->attachment_path);
        $this->assertStringContainsString('/storage/notices/', $response->json('data.attachment.url'));

        $log = AuditLog::where('action', 'notice.created')->sole();
        $this->assertSame($notice->id, $log->details['notice_id']);
        $this->assertSame('agenda.pdf', $log->details['attachment']);
        $this->assertSame($this->admin->id, $log->actor_id);
    }

    public function test_validation_and_attachment_rules(): void
    {
        $cases = [
            ['title_bn', ['title_bn' => '']],
            ['body_bn', ['body_bn' => '']],
            ['title_bn', ['title_bn' => str_repeat('a', 201)]],
            ['visibility', ['visibility' => 'secret']],
            ['published_at', ['published_at' => 'not a date']],
            ['attachment', ['attachment' => UploadedFile::fake()->create('big.pdf', 5121, 'application/pdf')]],
            ['attachment', ['attachment' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')]],
            ['attachment', ['attachment' => UploadedFile::fake()->create('notes.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')]],
        ];

        foreach ($cases as [$field, $fields]) {
            $this->create($fields)->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertSame(0, Notice::count());
        $this->assertSame([], Storage::disk('public')->allFiles());

        // 5 MB exactly and an image are accepted.
        $this->create(['attachment' => UploadedFile::fake()->create('ok.pdf', 5120, 'application/pdf')])->assertCreated();
        $this->create(['attachment' => UploadedFile::fake()->image('poster.png', 200, 100)])
            ->assertCreated()->assertJsonPath('data.attachment.is_image', true);

        // bn + en messages
        $big = fn () => ['attachment' => UploadedFile::fake()->create('big.pdf', 5121, 'application/pdf')];
        $this->actingAs($this->admin)->post('/api/admin/notices', ['title_bn' => 'ক', 'body_bn' => 'খ', ...$big()], self::BN)
            ->assertJsonPath('errors.attachment.0', 'সংযুক্ত ফাইল সর্বোচ্চ ৫ এমবি হতে পারবে।');
        $this->actingAs($this->admin)->post('/api/admin/notices', ['title_bn' => 'ক', 'body_bn' => 'খ', ...$big()], self::EN)
            ->assertJsonPath('errors.attachment.0', 'The attachment must be 5 MB or smaller.');
        $this->actingAs($this->admin)->post('/api/admin/notices', ['body_bn' => 'খ'], self::BN)
            ->assertJsonPath('errors.title_bn.0', 'শিরোনাম (বাংলা) দেওয়া আবশ্যক।');
    }

    public function test_public_list_hides_unpublished_members_only_and_scheduled(): void
    {
        $public = $this->notice(['title_bn' => 'public']);
        $this->notice(['title_bn' => 'draft', 'is_published' => false]);
        $this->notice(['title_bn' => 'members', 'visibility' => Notice::VISIBILITY_MEMBERS]);
        $this->notice(['title_bn' => 'scheduled', 'published_at' => now()->addDay()]);

        $this->getJson('/api/public/notices')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $public->id)
            ->assertJsonMissingPath('data.0.is_published')
            ->assertJsonMissingPath('data.0.created_by');
    }

    public function test_public_detail_404_rules(): void
    {
        $public = $this->notice();
        $draft = $this->notice(['is_published' => false]);
        $members = $this->notice(['visibility' => Notice::VISIBILITY_MEMBERS]);
        $scheduled = $this->notice(['published_at' => now()->addDay()]);

        $this->getJson("/api/public/notices/{$public->id}")->assertOk()->assertJsonPath('data.id', $public->id);

        foreach ([$draft->id, $members->id, $scheduled->id, 9999, 'abc'] as $id) {
            $this->getJson("/api/public/notices/{$id}", self::EN)
                ->assertNotFound()
                ->assertExactJson(['message' => 'This notice could not be found.']);
        }
        $this->getJson("/api/public/notices/{$members->id}", self::BN)
            ->assertNotFound()->assertJsonPath('message', 'এই নোটিশটি পাওয়া যায়নি।');
    }

    public function test_approved_members_see_members_only_notices(): void
    {
        $public = $this->notice();
        $members = $this->notice(['visibility' => Notice::VISIBILITY_MEMBERS, 'published_at' => now()]);
        $draft = $this->notice(['visibility' => Notice::VISIBILITY_MEMBERS, 'is_published' => false]);
        $member = User::factory()->approved()->create();

        $this->actingAs($member)->getJson('/api/notices')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$members->id, $public->id]);
        $this->actingAs($member)->getJson("/api/notices/{$members->id}")->assertOk()->assertJsonPath('data.visibility', 'members');
        $this->actingAs($member)->getJson("/api/notices/{$draft->id}")->assertNotFound();
    }

    public function test_member_api_needs_an_approved_login(): void
    {
        $this->getJson('/api/notices')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/notices')->assertForbidden(); // pending
    }

    public function test_pinned_first_then_newest(): void
    {
        $old = $this->notice(['published_at' => now()->subDays(3)]);
        $new = $this->notice(['published_at' => now()->subDay()]);
        $pinnedOld = $this->notice(['published_at' => now()->subDays(10), 'is_pinned' => true]);
        $newest = $this->notice(['published_at' => now()->subHour()]);

        $this->getJson('/api/public/notices')
            ->assertJsonPath('data.*.id', [$pinnedOld->id, $newest->id, $new->id, $old->id])
            ->assertJsonPath('data.0.is_pinned', true);
    }

    public function test_non_admins_cannot_manage_notices(): void
    {
        $notice = $this->notice();

        $this->postJson('/api/admin/notices', [])->assertUnauthorized();

        foreach ([User::factory()->approved()->create(), User::factory()->committee()->create()] as $user) {
            $this->actingAs($user)->getJson('/api/admin/notices')->assertForbidden();
            $this->create([], $user)->assertForbidden();
            $this->actingAs($user)->patchJson("/api/admin/notices/{$notice->id}", ['title_bn' => 'x'])->assertForbidden();
            $this->actingAs($user)->postJson("/api/admin/notices/{$notice->id}/pin")->assertForbidden();
            $this->actingAs($user)->postJson("/api/admin/notices/{$notice->id}/unpublish")->assertForbidden();
            $this->actingAs($user)->deleteJson("/api/admin/notices/{$notice->id}")->assertForbidden();
        }

        $this->assertSame(1, Notice::count());
        $this->assertSame('নোটিশ', $notice->fresh()->title_bn);

        $this->actingAs(User::factory()->superAdmin()->create())->getJson('/api/admin/notices')->assertOk();
    }

    public function test_admin_publishes_unpublishes_pins_and_edits_with_audit_log(): void
    {
        $id = $this->create(['is_published' => false])->assertCreated()->json('data.id');
        $this->assertNull(Notice::find($id)->published_at);
        $this->getJson("/api/public/notices/{$id}")->assertNotFound();

        $this->postJson("/api/admin/notices/{$id}/publish")->assertOk()->assertJsonPath('data.is_published', true);
        $this->assertNotNull(Notice::find($id)->published_at);
        $this->getJson("/api/public/notices/{$id}")->assertOk();

        $this->postJson("/api/admin/notices/{$id}/pin")->assertOk()->assertJsonPath('data.is_pinned', true);
        $this->postJson("/api/admin/notices/{$id}/unpin")->assertOk()->assertJsonPath('data.is_pinned', false);
        $this->postJson("/api/admin/notices/{$id}/unpublish")->assertOk()->assertJsonPath('data.is_published', false);
        $this->getJson("/api/public/notices/{$id}")->assertNotFound();

        $this->patchJson("/api/admin/notices/{$id}", ['title_en' => 'Meeting', 'visibility' => 'members'], self::EN)
            ->assertOk()
            ->assertJsonPath('data.title_en', 'Meeting')
            ->assertJsonPath('data.title_bn', 'সাধারণ সভা')
            ->assertJsonPath('data.visibility', 'members');
        $this->patchJson("/api/admin/notices/{$id}", ['title_bn' => ''], self::EN)
            ->assertUnprocessable()->assertJsonValidationErrors('title_bn');

        // Repeating an action that changes nothing is not logged again.
        $this->postJson("/api/admin/notices/{$id}/unpublish")->assertOk();

        $this->assertSame(
            ['notice.created', 'notice.published', 'notice.pinned', 'notice.unpinned', 'notice.unpublished', 'notice.updated'],
            AuditLog::orderBy('id')->pluck('action')->all()
        );
        $changes = AuditLog::where('action', 'notice.updated')->sole()->details['changes'];
        $this->assertSame(['from' => null, 'to' => 'Meeting'], $changes['title_en']);
        $this->assertSame(['from' => 'public', 'to' => 'members'], $changes['visibility']);

        // Admin list shows everything incl. unpublished; status filter works.
        $this->getJson('/api/admin/notices')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/admin/notices?status=published')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/admin/notices?status=unpublished')->assertJsonPath('meta.total', 1);
    }

    public function test_scheduled_notice_appears_once_its_date_comes(): void
    {
        $id = $this->create(['published_at' => now()->addDay()->toDateTimeString()])
            ->assertCreated()->assertJsonPath('data.is_scheduled', true)->json('data.id');

        $this->getJson("/api/public/notices/{$id}")->assertNotFound();
        $this->getJson('/api/admin/notices?status=scheduled')->assertJsonPath('meta.total', 1);

        $this->travel(2)->days();
        $this->getJson("/api/public/notices/{$id}")->assertOk();
    }

    public function test_attachment_replace_and_remove_delete_old_files(): void
    {
        $id = $this->create(['attachment' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->json('data.id');
        $first = Notice::find($id)->attachment_path;

        $this->post("/api/admin/notices/{$id}/attachment", ['attachment' => UploadedFile::fake()->image('b.jpg')], self::EN)
            ->assertOk()->assertJsonPath('data.attachment.name', 'b.jpg');
        $second = Notice::find($id)->attachment_path;
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);

        $this->post("/api/admin/notices/{$id}/attachment", ['attachment' => UploadedFile::fake()->create('c.exe', 10)], self::EN)
            ->assertUnprocessable()->assertJsonValidationErrors('attachment');
        Storage::disk('public')->assertExists($second);

        $this->deleteJson("/api/admin/notices/{$id}/attachment")->assertOk()->assertJsonPath('data.attachment', null);
        Storage::disk('public')->assertMissing($second);
        $this->assertSame(['notice.created', 'notice.attachment_replaced', 'notice.attachment_removed'], AuditLog::orderBy('id')->pluck('action')->all());
    }

    public function test_delete_removes_the_attachment_file(): void
    {
        $id = $this->create(['attachment' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->json('data.id');
        $path = Notice::find($id)->attachment_path;
        Storage::disk('public')->assertExists($path);

        $this->deleteJson("/api/admin/notices/{$id}", [], self::EN)
            ->assertOk()->assertJsonPath('message', 'Notice deleted.');

        $this->assertNull(Notice::find($id));
        Storage::disk('public')->assertMissing($path);
        $log = AuditLog::where('action', 'notice.deleted')->sole();
        $this->assertSame('a.pdf', $log->details['attachment']);

        $this->deleteJson("/api/admin/notices/{$id}")->assertNotFound();
    }

    public function test_members_only_attachment_is_private_and_served_to_approved_members_only(): void
    {
        $data = $this->create([
            'visibility' => 'members',
            'attachment' => UploadedFile::fake()->create('minutes.pdf', 20, 'application/pdf'),
        ])->assertCreated()
            ->assertJsonPath('data.attachment.is_private', true)
            ->json('data');
        $id = $data['id'];
        $this->assertSame(url("/api/notices/{$id}/attachment"), $data['attachment']['url']);
        $path = Notice::find($id)->attachment_path;

        Storage::disk('private')->assertExists($path);
        Storage::disk('public')->assertMissing($path);

        // Guests and pending accounts cannot download; approved members of any role can.
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/notices/{$id}/attachment")->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson("/api/notices/{$id}/attachment")->assertForbidden();
        foreach ([User::factory()->approved()->create(), User::factory()->committee()->create(), $this->admin] as $user) {
            $this->actingAs($user)->get("/api/notices/{$id}/attachment")
                ->assertOk()
                ->assertHeader('Content-Type', 'application/pdf')
                ->assertHeader('X-Content-Type-Options', 'nosniff');
        }

        // A draft's file: admin can preview it, members get 404.
        $this->actingAs($this->admin)->postJson("/api/admin/notices/{$id}/unpublish")->assertOk();
        $this->actingAs(User::factory()->approved()->create())->getJson("/api/notices/{$id}/attachment", self::EN)
            ->assertNotFound()->assertJsonPath('message', 'This notice could not be found.');
        $this->actingAs($this->admin)->get("/api/notices/{$id}/attachment")->assertOk();

        // Public notices keep a plain public URL.
        $this->actingAs($this->admin);
        $public = $this->create(['attachment' => UploadedFile::fake()->image('p.png')])
            ->assertJsonPath('data.attachment.is_private', false)->json('data');
        $this->assertStringContainsString('/storage/notices/', $public['attachment']['url']);
        Storage::disk('public')->assertExists(Notice::find($public['id'])->attachment_path);
    }

    public function test_changing_visibility_moves_the_attachment_between_disks(): void
    {
        $id = $this->create(['attachment' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->json('data.id');
        $path = Notice::find($id)->attachment_path;
        Storage::disk('public')->assertExists($path);

        $this->patchJson("/api/admin/notices/{$id}", ['visibility' => 'members'])
            ->assertOk()->assertJsonPath('data.attachment.is_private', true);
        Storage::disk('private')->assertExists($path);
        Storage::disk('public')->assertMissing($path);

        // Replacing / removing the file of a members-only notice works on the private disk.
        $this->post("/api/admin/notices/{$id}/attachment", ['attachment' => UploadedFile::fake()->image('b.jpg')], self::EN)->assertOk();
        $second = Notice::find($id)->attachment_path;
        Storage::disk('private')->assertMissing($path);
        Storage::disk('private')->assertExists($second);
        Storage::disk('public')->assertMissing($second);

        $this->patchJson("/api/admin/notices/{$id}", ['visibility' => 'public'])
            ->assertOk()->assertJsonPath('data.attachment.is_private', false);
        Storage::disk('public')->assertExists($second);
        Storage::disk('private')->assertMissing($second);

        $this->patchJson("/api/admin/notices/{$id}", ['visibility' => 'members'])->assertOk();
        $this->deleteJson("/api/admin/notices/{$id}")->assertOk();
        Storage::disk('private')->assertMissing($second);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_public_notices_are_rate_limited(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/public/notices')->assertOk();
        }
        $this->getJson('/api/public/notices', self::BN)
            ->assertStatus(429)
            ->assertJsonPath('message', __('messages.directory_rate_limited', [], 'bn'));
    }
}
