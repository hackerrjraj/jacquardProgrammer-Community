<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\MemberDocument;
use App\Models\User;
use Database\Seeders\ProfileListSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('private');
        $this->seed(ProfileListSeeder::class);

        $this->member = User::factory()->approved()->create([
            'phone' => '01712345678',
            'district' => 'dhaka',
            'workplace' => 'Old Knit Ltd',
        ]);
    }

    private function uploadDocument(User $user, string $name = 'nid.pdf'): MemberDocument
    {
        $this->actingAs($user)
            ->post('/api/profile/documents', [
                'type' => 'nid',
                'document' => UploadedFile::fake()->create($name, 100, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        return MemberDocument::latest('id')->first();
    }

    // ---------- View / update ----------

    public function test_member_sees_own_profile_with_default_visibility(): void
    {
        $this->actingAs($this->member)
            ->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.id', $this->member->id)
            ->assertJsonPath('data.workplace', 'Old Knit Ltd')
            ->assertJsonPath('data.photo_url', null)
            ->assertJsonPath('data.phone_visibility', 'members')
            ->assertJsonPath('data.email_visibility', 'hidden')
            ->assertJsonPath('data.workplace_visibility', 'public')
            ->assertJsonPath('data.documents', []);
    }

    public function test_member_updates_editable_fields_and_audit_log_is_written(): void
    {
        $this->actingAs($this->member)
            ->putJson('/api/profile', [
                'bio' => '  Jacquard programmer since 2015  ',
                'district' => 'gazipur',
                'workplace' => 'New Knit Ltd',
                'experience_years' => 9,
                'machines' => ['stoll', 'shima-seiki'],
                'skills' => ['m1plus'],
                'phone_visibility' => 'public',
                'email_visibility' => 'members',
                'workplace_visibility' => 'hidden',
            ], ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertJsonPath('message', __('messages.profile_updated', [], 'en'))
            ->assertJsonPath('data.bio', 'Jacquard programmer since 2015')
            ->assertJsonPath('data.district', 'gazipur')
            ->assertJsonPath('data.machines', [['key' => 'stoll', 'name_bn' => 'STOLL', 'name_en' => 'STOLL'], ['key' => 'shima-seiki', 'name_bn' => 'SHIMA SEIKI', 'name_en' => 'SHIMA SEIKI']])
            ->assertJsonPath('data.workplace_visibility', 'hidden');

        $this->member->refresh();
        $this->assertSame('New Knit Ltd', $this->member->workplace);
        $this->assertSame(9, $this->member->experience_years);

        $log = AuditLog::where('action', 'profile.updated')->sole();
        $this->assertSame($this->member->id, $log->actor_id);
        $this->assertSame($this->member->id, $log->target_id);
        $this->assertSame(['from' => 'Old Knit Ltd', 'to' => 'New Knit Ltd'], $log->details['changes']['workplace']);
        $this->assertSame(['from' => 'members', 'to' => 'public'], $log->details['changes']['phone_visibility']);
    }

    public function test_partial_update_changes_only_sent_fields(): void
    {
        $this->actingAs($this->member)->putJson('/api/profile', ['bio' => 'Hello'])->assertOk();

        $this->member->refresh();
        $this->assertSame('Hello', $this->member->bio);
        $this->assertSame('Old Knit Ltd', $this->member->workplace);
        $this->assertSame(['bio'], array_keys(AuditLog::where('action', 'profile.updated')->sole()->details['changes']));
    }

    public function test_mobile_name_role_and_status_cannot_be_changed(): void
    {
        $name = $this->member->name;

        $this->actingAs($this->member)
            ->putJson('/api/profile', [
                'phone' => '01899999999',
                'name' => 'Someone Else',
                'role' => 'super_admin',
                'status' => 'approved',
                'must_change_password' => true,
                'photo_path' => 'evil.jpg',
                'workplace' => 'Allowed Change Ltd',
            ])
            ->assertOk();

        $this->member->refresh();
        $this->assertSame('01712345678', $this->member->phone);
        $this->assertSame($name, $this->member->name);
        $this->assertSame('member', $this->member->role);
        $this->assertFalse($this->member->must_change_password);
        $this->assertNull($this->member->photo_path);
        $this->assertSame('Allowed Change Ltd', $this->member->workplace);
    }

    public function test_invalid_profile_values_are_rejected(): void
    {
        $this->actingAs($this->member)
            ->putJson('/api/profile', [
                'district' => 'atlantis',
                'workplace' => '',
                'experience_years' => 99,
                'machines' => [],
                'phone_visibility' => 'everyone',
                'bio' => str_repeat('a', 1001),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['district', 'workplace', 'experience_years', 'machines', 'phone_visibility', 'bio']);

        $this->assertSame('Old Knit Ltd', $this->member->refresh()->workplace);
        $this->assertSame(0, AuditLog::count());
    }

    // ---------- Access ----------

    public function test_guests_pending_rejected_and_suspended_users_are_blocked(): void
    {
        $this->getJson('/api/profile')->assertUnauthorized();
        $this->putJson('/api/profile', ['bio' => 'x'])->assertUnauthorized();

        foreach ([
            User::factory()->create(),
            User::factory()->rejected()->create(),
            User::factory()->suspended()->create(),
        ] as $user) {
            $this->app['auth']->forgetGuards();
            $this->actingAs($user)->getJson('/api/profile')->assertForbidden()->assertJsonPath('status', $user->status);
            $this->app['auth']->forgetGuards();
            $this->actingAs($user)->putJson('/api/profile', ['bio' => 'x'])->assertForbidden();
            $this->app['auth']->forgetGuards();
            $this->actingAs($user)
                ->post('/api/profile/photo', ['photo' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])
                ->assertForbidden();
        }

        $this->assertSame(0, AuditLog::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_member_with_forced_password_change_is_blocked(): void
    {
        $this->member->forceFill(['must_change_password' => true])->save();

        $this->actingAs($this->member)
            ->getJson('/api/profile')
            ->assertForbidden()
            ->assertJsonPath('must_change_password', true);
    }

    public function test_suspended_message_says_contact_the_admin(): void
    {
        $this->assertSame(
            'Your account has been suspended. Please contact the admin for details.',
            __('messages.account_suspended', [], 'en'),
        );
        $this->assertStringContainsString('অ্যাডমিনের সাথে যোগাযোগ', __('messages.account_suspended', [], 'bn'));
        $this->assertStringNotContainsString('কমিটি', __('messages.account_suspended', [], 'bn'));
    }

    // ---------- Photo ----------

    public function test_photo_upload_stores_random_name_and_replacing_deletes_old_file(): void
    {
        $this->actingAs($this->member)
            ->post('/api/profile/photo', ['photo' => UploadedFile::fake()->image('my-original-photo-name.jpg', 300, 300)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.photo_url', fn ($url) => str_contains($url, '/storage/profile-photos/'));

        $first = $this->member->refresh()->photo_path;
        $this->assertStringStartsWith('profile-photos/', $first);
        $this->assertStringNotContainsString('my-original-photo-name', basename($first));
        Storage::disk('public')->assertExists($first);

        $this->post('/api/profile/photo', ['photo' => UploadedFile::fake()->image('new.webp')], ['Accept' => 'application/json'])
            ->assertOk();

        $second = $this->member->refresh()->photo_path;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertExists($second);
        Storage::disk('public')->assertMissing($first);
        $this->assertSame(2, AuditLog::where('action', 'profile.photo_updated')->count());
    }

    public function test_photo_over_2mb_or_wrong_type_is_rejected(): void
    {
        $this->actingAs($this->member);

        foreach ([
            UploadedFile::fake()->image('big.jpg')->size(2049),
            UploadedFile::fake()->create('photo.pdf', 100, 'application/pdf'),
            UploadedFile::fake()->create('photo.gif', 100, 'image/gif'),
        ] as $file) {
            $this->post('/api/profile/photo', ['photo' => $file], ['Accept' => 'application/json'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('photo');
        }

        $this->assertNull($this->member->refresh()->photo_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    // ---------- Documents ----------

    public function test_document_upload_is_stored_privately_with_random_name(): void
    {
        $this->actingAs($this->member)
            ->post('/api/profile/documents', [
                'type' => 'certificate',
                'document' => UploadedFile::fake()->create('certificate.pdf', 300, 'application/pdf'),
            ], ['Accept' => 'application/json', 'Accept-Language' => 'en'])
            ->assertCreated()
            ->assertJsonPath('message', __('messages.document_uploaded', [], 'en'))
            ->assertJsonPath('data.type', 'certificate')
            ->assertJsonPath('data.original_name', 'certificate.pdf');

        $doc = MemberDocument::sole();
        $this->assertSame($this->member->id, $doc->user_id);
        $this->assertStringNotContainsString('certificate', $doc->path);
        Storage::disk('private')->assertExists($doc->path);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame($doc->id, AuditLog::where('action', 'profile.document_added')->sole()->details['document_id']);

        $this->getJson('/api/profile')->assertJsonCount(1, 'data.documents');
    }

    public function test_document_over_5mb_or_wrong_type_is_rejected(): void
    {
        $this->actingAs($this->member);

        foreach ([
            UploadedFile::fake()->create('big.pdf', 5121, 'application/pdf'),
            UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
            UploadedFile::fake()->create('setup.exe', 10, 'application/x-msdownload'),
            UploadedFile::fake()->create('pic.webp', 10, 'image/webp'),
        ] as $file) {
            $this->post('/api/profile/documents', ['type' => 'other', 'document' => $file], ['Accept' => 'application/json'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('document');
        }

        $this->assertSame(0, MemberDocument::count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_jpg_and_png_documents_are_accepted(): void
    {
        $this->actingAs($this->member);

        foreach ([UploadedFile::fake()->image('scan.jpg'), UploadedFile::fake()->image('scan.png')] as $file) {
            $this->post('/api/profile/documents', ['type' => 'birth_certificate', 'document' => $file], ['Accept' => 'application/json'])->assertCreated();
        }

        $this->assertSame(2, MemberDocument::count());
    }

    public function test_document_type_is_required_and_must_be_valid(): void
    {
        $this->actingAs($this->member);

        foreach ([[], ['type' => ''], ['type' => 'passport']] as $extra) {
            $this->post('/api/profile/documents', [
                ...$extra,
                'document' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
            ], ['Accept' => 'application/json'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('type');
        }

        $this->assertSame(0, MemberDocument::count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_owner_and_admins_can_download_but_committee_and_other_members_cannot(): void
    {
        $doc = $this->uploadDocument($this->member);

        $this->actingAs($this->member)
            ->get("/api/profile/documents/{$doc->id}/download")
            ->assertOk()
            ->assertDownload('nid.pdf');

        foreach ([User::factory()->admin()->create(), User::factory()->superAdmin()->create()] as $reader) {
            $this->actingAs($reader)->get("/api/profile/documents/{$doc->id}/download")->assertOk()->assertDownload('nid.pdf');
        }

        // Committee sees the list only — never the file (MASTER_SPEC §10).
        foreach ([User::factory()->committee()->create(), User::factory()->approved()->create()] as $other) {
            $this->actingAs($other)
                ->getJson("/api/profile/documents/{$doc->id}/download", ['Accept-Language' => 'en'])
                ->assertForbidden()
                ->assertJsonPath('message', __('messages.forbidden', [], 'en'));
        }

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/profile/documents/{$doc->id}/download")->assertUnauthorized();
        $this->actingAs($this->member)->getJson('/api/profile/documents/99999/download')->assertNotFound();
    }

    public function test_owner_deletes_document_and_file_is_removed_but_others_cannot(): void
    {
        $doc = $this->uploadDocument($this->member);

        $other = User::factory()->approved()->create();
        $this->actingAs($other)->deleteJson("/api/profile/documents/{$doc->id}")->assertNotFound();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->deleteJson("/api/profile/documents/{$doc->id}")->assertNotFound();
        Storage::disk('private')->assertExists($doc->path);

        $this->actingAs($this->member)->deleteJson("/api/profile/documents/{$doc->id}")->assertOk();

        $this->assertSame(0, MemberDocument::count());
        Storage::disk('private')->assertMissing($doc->path);
        $this->assertSame($doc->id, AuditLog::where('action', 'profile.document_deleted')->sole()->details['document_id']);
    }

    public function test_document_limit(): void
    {
        $this->actingAs($this->member);
        for ($i = 0; $i < MemberDocument::MAX_PER_MEMBER; $i++) {
            $this->member->documents()->create(['type' => 'other', 'original_name' => "d{$i}.pdf", 'path' => "x/d{$i}.pdf", 'mime_type' => 'application/pdf', 'size' => 1]);
        }
        $this->assertSame(20, MemberDocument::MAX_PER_MEMBER);

        $this->post('/api/profile/documents', [
            'type' => 'other',
            'document' => UploadedFile::fake()->create('one-more.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    // ---------- Admin view ----------

    public function test_admin_super_admin_and_committee_can_view_any_member_profile(): void
    {
        $this->uploadDocument($this->member);

        foreach ([User::factory()->admin()->create(), User::factory()->superAdmin()->create(), User::factory()->committee()->create()] as $admin) {
            $this->actingAs($admin)
                ->getJson("/api/admin/members/{$this->member->id}/profile")
                ->assertOk()
                ->assertJsonPath('data.id', $this->member->id)
                ->assertJsonPath('data.phone', '01712345678')
                ->assertJsonCount(1, 'data.documents');
        }
    }

    public function test_committee_sees_document_type_and_date_only(): void
    {
        $doc = $this->uploadDocument($this->member);
        $url = "/api/admin/members/{$this->member->id}/profile";

        $committeeDoc = $this->actingAs(User::factory()->committee()->create())->getJson($url)->assertOk()->json('data.documents.0');
        $this->assertSame(['id', 'type', 'created_at'], array_keys($committeeDoc));
        $this->assertSame('nid', $committeeDoc['type']);

        $this->actingAs(User::factory()->admin()->create())->getJson($url)
            ->assertJsonPath('data.documents.0.original_name', 'nid.pdf')
            ->assertJsonPath('data.documents.0.download_url', url("/api/profile/documents/{$doc->id}/download"));
    }

    public function test_members_cannot_use_admin_profile_view(): void
    {
        $target = User::factory()->approved()->create();

        $this->actingAs($this->member)->getJson("/api/admin/members/{$target->id}/profile")->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/admin/members/{$target->id}/profile")->assertUnauthorized();
    }
}
