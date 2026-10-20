<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SiteImage;
use App\Models\User;
use App\Support\SiteImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Site Content Manager part 2: editable site images — permissions, upload validation, resize, replace/remove, audit, public API. */
class SiteImagesTest extends TestCase
{
    use RefreshDatabase;

    private const EN = ['Accept' => 'application/json', 'Accept-Language' => 'en'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function upload(User $user, string $key, ?UploadedFile $file = null)
    {
        return $this->actingAs($user)->post("/api/admin/site-images/$key", ['image' => $file ?? UploadedFile::fake()->image('x.jpg', 1000, 800)], self::EN);
    }

    public function test_only_admin_and_super_admin_can_use_the_admin_api(): void
    {
        $calls = [['GET', '/api/admin/site-images'], ['POST', '/api/admin/site-images/logo'], ['DELETE', '/api/admin/site-images/logo']];
        foreach ($calls as [$method, $url]) {
            auth()->forgetGuards();
            $this->json($method, $url, [], self::EN)->assertUnauthorized();
            foreach ([User::factory()->approved()->create(), User::factory()->committee()->create()] as $user) {
                $this->actingAs($user)->json($method, $url, [], self::EN)->assertForbidden();
            }
        }
        $this->assertSame(0, SiteImage::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
        foreach ([User::factory()->admin()->create(), User::factory()->superAdmin()->create()] as $admin) {
            $this->upload($admin, 'logo')->assertOk();
        }
    }

    public function test_list_has_every_image_with_recommended_size_and_no_url_by_default(): void
    {
        $rows = collect($this->actingAs(User::factory()->admin()->create())->getJson('/api/admin/site-images', self::EN)->assertOk()->json('data'));
        $this->assertCount(count(SiteImages::registry()), $rows);
        $this->assertSame('1200 × 630', $rows->firstWhere('key', 'og_image')['recommended']);
        $this->assertNull($rows->firstWhere('key', 'logo')['url']);
        $this->getJson('/api/public/site-images')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_upload_is_validated(): void
    {
        $admin = User::factory()->admin()->create();
        $this->upload($admin, 'nope')->assertNotFound();
        $this->upload($admin, 'logo', UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'))->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->upload($admin, 'logo', UploadedFile::fake()->create('evil.php', 1, 'text/x-php'))->assertUnprocessable();
        $this->upload($admin, 'logo', UploadedFile::fake()->create('fake.jpg', 5, 'image/jpeg'))->assertUnprocessable(); // not a real image
        $this->upload($admin, 'logo', UploadedFile::fake()->image('big.jpg', 800, 800)->size(3000))->assertUnprocessable(); // over 2 MB
        $this->upload($admin, 'favicon', UploadedFile::fake()->image('tiny.png', 100, 100))->assertUnprocessable()->assertJsonValidationErrors('image'); // below 192
        $this->actingAs($admin)->postJson('/api/admin/site-images/logo', [], self::EN)->assertUnprocessable();
        $this->assertSame(0, SiteImage::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_upload_resizes_stores_publishes_and_audits(): void
    {
        $admin = User::factory()->admin()->create();
        $this->getJson('/api/public/site-images')->assertExactJson(['data' => []]);
        $this->assertTrue(Cache::has(SiteImages::CACHE_KEY));

        $this->upload($admin, 'header_gallery', UploadedFile::fake()->image('wide.jpg', 3000, 1500))->assertOk()->assertJsonPath('message', 'Image saved.');
        $this->assertFalse(Cache::has(SiteImages::CACHE_KEY), 'cache must be cleared');

        $row = SiteImage::where('key', 'header_gallery')->sole();
        $this->assertSame($admin->id, $row->updated_by);
        Storage::disk('public')->assertExists($row->path);
        $this->assertStringEndsWith('.webp', $row->path);
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($row->path));
        $this->assertSame([1920, 500], [$w, $h]); // cropped to the banner shape and scaled down

        auth()->forgetGuards();
        $this->getJson('/api/public/site-images')->assertOk()->assertJsonPath('data.header_gallery', SiteImages::url($row->path));

        $log = AuditLog::where('action', 'site_image.updated')->sole();
        $this->assertSame('header_gallery', $log->details['key']);
        $this->assertFalse($log->details['replaced']);
    }

    public function test_small_images_are_not_enlarged_and_og_is_jpeg(): void
    {
        $admin = User::factory()->admin()->create();
        $this->upload($admin, 'og_image', UploadedFile::fake()->image('s.png', 600, 315))->assertOk();
        $row = SiteImage::where('key', 'og_image')->sole();
        $this->assertStringEndsWith('.jpg', $row->path);
        $this->assertSame([600, 315], array_slice(getimagesizefromstring(Storage::disk('public')->get($row->path)), 0, 2));
    }

    public function test_replace_deletes_the_old_file_and_remove_goes_back_to_default(): void
    {
        $admin = User::factory()->admin()->create();
        $this->upload($admin, 'logo')->assertOk();
        $old = SiteImage::where('key', 'logo')->value('path');
        $this->upload($admin, 'logo')->assertOk();
        $new = SiteImage::where('key', 'logo')->value('path');
        $this->assertNotSame($old, $new);
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($new);
        $this->assertSame(1, SiteImage::count());
        $this->assertTrue(AuditLog::where('action', 'site_image.updated')->get()->last()->details['replaced']);

        $this->actingAs($admin)->deleteJson('/api/admin/site-images/logo', [], self::EN)->assertOk()->assertJsonPath('message', 'Image removed — the default design is used again.');
        $this->assertSame(0, SiteImage::count());
        Storage::disk('public')->assertMissing($new);
        $this->assertSame(1, AuditLog::where('action', 'site_image.removed')->count());
        $this->getJson('/api/public/site-images')->assertExactJson(['data' => []]);

        // Removing again changes nothing and is not logged again.
        $this->actingAs($admin)->deleteJson('/api/admin/site-images/logo', [], self::EN)->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'site_image.removed')->count());
    }

    public function test_favicon_makes_every_icon_size(): void
    {
        $admin = User::factory()->admin()->create();
        $this->upload($admin, 'favicon', UploadedFile::fake()->image('f.png', 800, 600))->assertOk();
        $row = SiteImage::where('key', 'favicon')->sole();
        $expected = ['icon-192' => 192, 'icon-512' => 512, 'icon-maskable-512' => 512, 'apple-touch-icon' => 180, 'favicon-32' => 32];
        $this->assertEqualsCanonicalizing(array_keys($expected), array_keys($row->files));
        foreach ($expected as $name => $size) {
            $this->assertSame([$size, $size], array_slice(getimagesizefromstring(Storage::disk('public')->get($row->files[$name])), 0, 2), $name);
        }
        $public = $this->getJson('/api/public/site-images')->json('data');
        $this->assertArrayHasKey('favicon', $public);
        $this->assertArrayHasKey('favicon_apple-touch-icon', $public);

        $this->actingAs($admin)->deleteJson('/api/admin/site-images/favicon', [], self::EN)->assertOk();
        $this->assertSame([], Storage::disk('public')->allFiles());
    }
}
