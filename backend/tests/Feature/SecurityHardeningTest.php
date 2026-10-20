<?php

namespace Tests\Feature;

use App\Models\Contribution;
use App\Models\MemberDocument;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Phase 12c (MASTER_SPEC §38): 401 / 403 on private files, committee fields, rate limits, upload checks, headers. */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const EN = ['Accept' => 'application/json', 'Accept-Language' => 'en'];

    private const BN = ['Accept' => 'application/json', 'Accept-Language' => 'bn'];

    private const PRIVATE_FILE_URLS = [
        '/api/profile/documents/1/download',
        '/api/contributions/1/proof',
        '/api/assistance/1/documents/1',
        '/api/assistance/1/payout-proof',
        '/api/notices/1/attachment',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('private');
    }

    // ---------- 401 / 403 ----------

    public function test_private_files_without_login_answer_401_not_500(): void
    {
        foreach (self::PRIVATE_FILE_URLS as $url) {
            // A plain browser request (no "Accept: application/json") used to crash with "Route [login] not defined".
            $this->get($url, ['Accept-Language' => 'bn'])->assertUnauthorized()->assertJsonPath('message', __('Unauthenticated.', [], 'bn'));
            $this->get($url, ['Accept-Language' => 'en'])->assertUnauthorized();
            $this->getJson($url)->assertUnauthorized();
        }
        $this->get('/api/me')->assertUnauthorized();
    }

    public function test_private_files_for_the_wrong_person_answer_403(): void
    {
        $owner = User::factory()->approved()->create();
        $this->actingAs($owner)->post('/api/profile/documents', [
            'type' => 'nid',
            'document' => UploadedFile::fake()->create('nid.pdf', 100, 'application/pdf'),
        ], self::EN)->assertCreated();
        $document = MemberDocument::sole();

        Setting::put(Setting::FUND_START_MONTH, now()->format('Y-m'));
        $this->actingAs($owner)->post('/api/contributions', [
            'method' => 'bkash',
            'sender_number' => '01712345678',
            'transaction_id' => 'SEC12C001',
            'amount' => 200,
            'months' => [now()->format('Y-m')],
            'proof' => UploadedFile::fake()->image('slip.png'),
        ], self::EN)->assertCreated();
        $contribution = Contribution::sole();

        foreach ([User::factory()->approved()->create(), User::factory()->committee()->create()] as $other) {
            $this->actingAs($other)->get("/api/profile/documents/{$document->id}/download")->assertForbidden();
            $this->actingAs($other)->get("/api/contributions/{$contribution->id}/proof")->assertForbidden();
        }
        $this->actingAs($owner)->get("/api/profile/documents/{$document->id}/download")->assertOk();
        $this->actingAs(User::factory()->admin()->create())->get("/api/contributions/{$contribution->id}/proof")->assertOk();
    }

    public function test_wrong_role_on_admin_routes_answers_403(): void
    {
        $member = User::factory()->approved()->create();
        $committee = User::factory()->committee()->create();
        $admin = User::factory()->admin()->create();

        foreach (['/api/admin/contributions', '/api/admin/assistance', '/api/admin/audit-log', '/api/admin/contact-messages'] as $url) {
            $this->actingAs($member)->getJson($url)->assertForbidden();
            $this->actingAs($committee)->getJson($url)->assertForbidden();
        }
        foreach (['/api/admin/settings', '/api/admin/ledger'] as $url) {
            $this->actingAs($admin)->getJson($url)->assertForbidden();
        }
        $this->actingAs($member)->getJson('/api/admin/members')->assertForbidden();
    }

    // ---------- Committee gets only the Programmers List fields ----------

    public function test_committee_does_not_receive_email_or_account_admin_fields(): void
    {
        $approved = User::factory()->approved()->create(['email' => 'secret@example.com']);
        $pending = User::factory()->create(['email' => 'pending@example.com']);
        $rejected = User::factory()->create(['status' => 'rejected', 'rejection_reason' => 'Fake']);
        $committee = User::factory()->committee()->create(['email' => 'me@example.com']);

        $list = $this->actingAs($committee)->getJson('/api/admin/members?per_page=100')->assertOk();
        $ids = collect($list->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($approved->id));
        $this->assertFalse($ids->contains($pending->id));
        $this->assertFalse($ids->contains($rejected->id));
        $row = collect($list->json('data'))->firstWhere('id', $approved->id);
        // The Programmers List columns (§37a) stay: photo, name, mobile, district / workplace, status.
        foreach (['id', 'name', 'phone', 'district', 'workplace', 'status', 'photo_url', 'payments'] as $key) {
            $this->assertArrayHasKey($key, $row);
        }
        foreach (['email', 'rejection_reason', 'must_change_password', 'approved_at', 'approved_by'] as $key) {
            $this->assertArrayNotHasKey($key, $row);
        }

        $profile = $this->actingAs($committee)->getJson("/api/admin/members/{$approved->id}/profile")->assertOk();
        $this->assertArrayNotHasKey('email', $profile->json('data'));
        $this->assertStringNotContainsString('secret@example.com', $profile->getContent());

        // Pending / rejected accounts are not on the Programmers List → not found for committee.
        $this->actingAs($committee)->getJson("/api/admin/members/{$pending->id}/profile")->assertNotFound();
        $this->actingAs($committee)->getJson("/api/admin/members/{$rejected->id}/payments")->assertNotFound();

        // Own account and admins still get everything.
        $this->actingAs($committee)->getJson('/api/me')->assertJsonPath('data.email', 'me@example.com');
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->getJson("/api/admin/members/{$approved->id}/profile")->assertJsonPath('data.email', 'secret@example.com');
        $this->actingAs($admin)->getJson("/api/admin/members/{$pending->id}/profile")->assertOk();
        $this->actingAs($admin)->getJson('/api/admin/members?status=rejected')->assertJsonPath('data.0.rejection_reason', 'Fake');
    }

    // ---------- Rate limits ----------

    public function test_login_is_limited_per_ip_across_phone_numbers(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/login', ['phone' => sprintf('0171%07d', $i), 'password' => 'wrong-pass'])->assertUnprocessable();
        }
        $this->postJson('/api/login', ['phone' => '01799999999', 'password' => 'wrong-pass'], self::EN)
            ->assertTooManyRequests()
            ->assertJsonPath('message', __('messages.login_rate_limited', [], 'en'));
    }

    public function test_change_password_is_limited(): void
    {
        $user = User::factory()->approved()->create();
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->postJson('/api/change-password', [
                'current_password' => 'wrong-'.$i,
                'password' => 'new-password-1',
                'password_confirmation' => 'new-password-1',
            ])->assertUnprocessable();
        }
        $this->actingAs($user)->postJson('/api/change-password', [], self::BN)
            ->assertTooManyRequests()
            ->assertJsonPath('message', __('messages.password_rate_limited', [], 'bn'));
    }

    public function test_registration_and_contact_limits_answer_in_bn_and_en(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/register', [])->assertUnprocessable();
        }
        $this->postJson('/api/register', [], self::BN)
            ->assertTooManyRequests()
            ->assertJsonPath('message', __('messages.register_rate_limited', [], 'bn'));

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/contact', [])->assertUnprocessable();
        }
        $this->postJson('/api/contact', [], self::EN)
            ->assertTooManyRequests()
            ->assertJsonPath('message', __('messages.contact_rate_limited', [], 'en'));
    }

    public function test_plain_throttles_answer_with_a_friendly_message(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/districts')->assertOk();
        }
        $this->getJson('/api/districts', self::EN)
            ->assertTooManyRequests()
            ->assertJsonPath('message', __('messages.too_many_requests', [], 'en'));
        $this->getJson('/api/districts', self::BN)
            ->assertJsonPath('message', __('messages.too_many_requests', [], 'bn'));
    }

    // ---------- Uploads ----------

    public function test_uploads_check_content_and_extension_and_size(): void
    {
        $user = User::factory()->approved()->create();
        $upload = fn (UploadedFile $file) => $this->actingAs($user)
            ->post('/api/profile/documents', ['type' => 'other', 'document' => $file], self::EN);

        // Real PNG content but a script extension → refused (extension check).
        $png = UploadedFile::fake()->image('real.png')->getContent();
        $upload($this->realFile('nid.php', $png))->assertJsonValidationErrors('document');
        // Script content with an allowed extension → refused (content / MIME check).
        $upload($this->realFile('nid.pdf', '<?php echo "x";'))->assertJsonValidationErrors('document');
        $upload($this->realFile('nid.jpg', '<?php echo "x";'))->assertJsonValidationErrors('document');
        // Real PNG content with its own extension is fine.
        $upload($this->realFile('nid.png', $png))->assertCreated();
        MemberDocument::query()->delete();
        // Too big (documents: 5 MB).
        $upload(UploadedFile::fake()->create('nid.pdf', 5121, 'application/pdf'))->assertJsonValidationErrors('document');
        $this->assertSame(0, MemberDocument::count());

        // Profile photo: image only, 2 MB.
        $this->actingAs($user)->post('/api/profile/photo', ['photo' => UploadedFile::fake()->image('me.svg')], self::EN)
            ->assertJsonValidationErrors('photo');
        $this->actingAs($user)->post('/api/profile/photo', ['photo' => UploadedFile::fake()->image('me.jpg')->size(2049)], self::EN)
            ->assertJsonValidationErrors('photo');

        // A good file is stored on the private disk under a random name.
        $upload(UploadedFile::fake()->create('my-nid-card.pdf', 100, 'application/pdf'))->assertCreated();
        $path = MemberDocument::sole()->path;
        $this->assertStringNotContainsString('my-nid-card', $path);
        Storage::disk('private')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
    }

    /** A real file on disk (fake uploads report a MIME type from the file name, so they cannot test content checks). */
    private function realFile(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    public function test_private_storage_folder_is_not_served_directly(): void
    {
        $this->get('/storage/member-documents/1/x.pdf')->assertNotFound();
        $this->put('/storage/member-documents/1/x.pdf')->assertStatus(404);
    }

    // ---------- Headers, session ----------

    public function test_security_headers_are_sent(): void
    {
        foreach ([$this->getJson('/api/health'), $this->get('/api/contributions/1/proof')] as $response) {
            $response->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
                ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        }
    }

    public function test_logout_ends_the_session(): void
    {
        User::factory()->approved()->create(['phone' => '01712345678', 'password' => 'secret-pass-1']);
        $this->postJson('/api/login', ['phone' => '01712345678', 'password' => 'secret-pass-1'])->assertOk();
        $this->getJson('/api/me')->assertOk();

        $this->postJson('/api/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/me')->assertUnauthorized();
    }
}
