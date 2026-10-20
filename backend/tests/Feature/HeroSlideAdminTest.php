<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\HeroSlide;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Phase 11b-1: Home hero slides admin (admin + super_admin). */
class HeroSlideAdminTest extends TestCase
{
    use RefreshDatabase;

    private const EN = ['Accept' => 'application/json', 'Accept-Language' => 'en'];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->admin = User::factory()->admin()->create();
    }

    private function slide(array $fields = []): HeroSlide
    {
        $path = 'slides/'.uniqid().'.jpg';
        Storage::disk('public')->put($path, 'img');

        return HeroSlide::factory()->create(['image_path' => $path, ...$fields]);
    }

    private function image(string $name = 'slide.jpg', int $kb = 100): UploadedFile
    {
        return UploadedFile::fake()->image($name, 1600, 900)->size($kb);
    }

    public function test_only_admin_and_super_admin_can_manage_slides(): void
    {
        $slide = $this->slide();
        $routes = [
            ['GET', '/api/admin/hero-slides'],
            ['POST', '/api/admin/hero-slides'],
            ['PUT', '/api/admin/hero-slides/order'],
            ['PATCH', "/api/admin/hero-slides/{$slide->id}"],
            ['POST', "/api/admin/hero-slides/{$slide->id}/active"],
            ['DELETE', "/api/admin/hero-slides/{$slide->id}"],
        ];

        foreach ($routes as [$method, $url]) {
            $this->json($method, $url, [], self::EN)->assertUnauthorized();
        }
        foreach ([User::factory()->approved()->create(), User::factory()->committee()->create()] as $user) {
            foreach ($routes as [$method, $url]) {
                $this->actingAs($user)->json($method, $url, [], self::EN)->assertForbidden();
            }
        }

        $this->assertDatabaseHas('hero_slides', ['id' => $slide->id]);
        $this->actingAs(User::factory()->superAdmin()->create())->getJson('/api/admin/hero-slides', self::EN)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_admin_creates_a_slide_with_an_image_and_optional_texts(): void
    {
        $this->slide(['sort_order' => 4]);

        $res = $this->actingAs($this->admin)->post('/api/admin/hero-slides', [
            'image' => $this->image(),
            'title_en' => '  New slide  ',
            'title_bn' => '',
            'button_text_en' => 'Join',
            'button_link' => '/register',
        ], self::EN)->assertCreated();

        $res->assertJsonPath('data.title_en', 'New slide')
            ->assertJsonPath('data.title_bn', null)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.sort_order', 5)
            ->assertJsonPath('data.button_link', '/register');

        $created = HeroSlide::findOrFail($res->json('data.id'));
        Storage::disk('public')->assertExists($created->image_path);
        $this->assertStringStartsWith('slides/', $created->image_path);

        // A picture-only slide is fine.
        $this->actingAs($this->admin)->post('/api/admin/hero-slides', ['image' => $this->image('b.png'), 'is_active' => '0'], self::EN)
            ->assertCreated()
            ->assertJsonPath('data.title_en', null)
            ->assertJsonPath('data.is_active', false);

        $log = AuditLog::where('action', 'hero_slide.created')->firstOrFail();
        $this->assertSame($this->admin->id, $log->actor_id);
        $this->assertSame('New slide', $log->details['title_en']);
        $this->assertArrayNotHasKey('image', $log->details);
    }

    public function test_image_is_required_and_checked_for_type_and_size(): void
    {
        $this->actingAs($this->admin)->post('/api/admin/hero-slides', ['title_en' => 'x'], self::EN)
            ->assertJsonValidationErrors('image');
        $this->actingAs($this->admin)->post('/api/admin/hero-slides', ['image' => UploadedFile::fake()->create('a.pdf', 50, 'application/pdf')], self::EN)
            ->assertJsonValidationErrors('image');
        $this->actingAs($this->admin)->post('/api/admin/hero-slides', ['image' => UploadedFile::fake()->image('a.gif')], self::EN)
            ->assertJsonValidationErrors('image');
        $this->actingAs($this->admin)->post('/api/admin/hero-slides', ['image' => $this->image('big.jpg', 2049)], self::EN)
            ->assertJsonValidationErrors('image');

        $this->actingAs($this->admin)->post('/api/admin/hero-slides', ['image' => $this->image('ok.webp', 2048)], self::EN)->assertCreated();
        $this->assertSame(1, HeroSlide::count());
        $this->assertCount(1, Storage::disk('public')->allFiles('slides'));
    }

    public function test_button_link_must_be_an_internal_path_or_https_url(): void
    {
        $bad = ['javascript:alert(1)', 'http://example.com', '//evil.com', '/\\evil.com', 'example.com', 'https://', 'ftp://x.com/a', '/has space', 'mailto:a@b.com'];
        foreach ($bad as $link) {
            $this->actingAs($this->admin)->post('/api/admin/hero-slides', [
                'image' => $this->image(), 'button_text_en' => 'Go', 'button_link' => $link,
            ], self::EN)->assertJsonValidationErrors('button_link');
        }

        foreach (['/register', '/programmers?district=dhaka', 'https://example.com/page'] as $link) {
            $this->actingAs($this->admin)->post('/api/admin/hero-slides', [
                'image' => $this->image(), 'button_text_en' => 'Go', 'button_link' => $link,
            ], self::EN)->assertCreated()->assertJsonPath('data.button_link', $link);
        }
    }

    public function test_button_text_and_link_go_together(): void
    {
        $this->actingAs($this->admin)->post('/api/admin/hero-slides', ['image' => $this->image(), 'button_text_bn' => 'যোগ দিন'], self::EN)
            ->assertJsonValidationErrors('button_link');
        $this->actingAs($this->admin)->post('/api/admin/hero-slides', ['image' => $this->image(), 'button_link' => '/register'], self::EN)
            ->assertJsonValidationErrors('button_link');

        // Updating: the saved link counts for a new text; removing the link while a text stays is refused.
        $slide = $this->slide(['button_text_en' => 'Join', 'button_link' => '/register']);
        $this->actingAs($this->admin)->patchJson("/api/admin/hero-slides/{$slide->id}", ['button_text_en' => 'Join now'], self::EN)->assertOk();
        $this->actingAs($this->admin)->patchJson("/api/admin/hero-slides/{$slide->id}", ['button_link' => null], self::EN)
            ->assertJsonValidationErrors('button_link');
        $this->actingAs($this->admin)->patchJson("/api/admin/hero-slides/{$slide->id}", ['button_link' => null, 'button_text_en' => null, 'button_text_bn' => null], self::EN)
            ->assertOk()
            ->assertJsonPath('data.button_link', null);
    }

    public function test_update_changes_texts_and_replacing_the_image_deletes_the_old_file(): void
    {
        $slide = $this->slide(['title_en' => 'Old']);
        $oldPath = $slide->image_path;

        $this->actingAs($this->admin)->patchJson("/api/admin/hero-slides/{$slide->id}", ['title_en' => 'New', 'subtitle_en' => ''], self::EN)
            ->assertOk()
            ->assertJsonPath('data.title_en', 'New')
            ->assertJsonPath('data.subtitle_en', null);
        Storage::disk('public')->assertExists($oldPath);

        $this->actingAs($this->admin)->post("/api/admin/hero-slides/{$slide->id}", ['_method' => 'PATCH', 'image' => $this->image('new.png')], self::EN)
            ->assertOk();

        $newPath = $slide->refresh()->image_path;
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($newPath);

        $logs = AuditLog::where('action', 'hero_slide.updated')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame(['from' => 'Old', 'to' => 'New'], $logs[0]->details['changes']['title_en']);
        $this->assertTrue($logs[1]->details['image_replaced']);

        // A bad replacement keeps the current image.
        $this->actingAs($this->admin)->post("/api/admin/hero-slides/{$slide->id}", ['_method' => 'PATCH', 'image' => UploadedFile::fake()->create('x.svg', 5, 'image/svg+xml')], self::EN)
            ->assertJsonValidationErrors('image');
        Storage::disk('public')->assertExists($newPath);

        $this->actingAs($this->admin)->patchJson('/api/admin/hero-slides/999', ['title_en' => 'x'], self::EN)->assertNotFound();
    }

    public function test_delete_removes_the_slide_and_its_image_file(): void
    {
        $slide = $this->slide(['title_en' => 'Bye']);
        $other = $this->slide();

        $this->actingAs($this->admin)->deleteJson("/api/admin/hero-slides/{$slide->id}", [], self::EN)->assertOk();

        $this->assertModelMissing($slide);
        Storage::disk('public')->assertMissing($slide->image_path);
        Storage::disk('public')->assertExists($other->image_path);
        $log = AuditLog::where('action', 'hero_slide.deleted')->firstOrFail();
        $this->assertSame('Bye', $log->details['title_en']);
    }

    public function test_toggle_active_hides_the_slide_from_the_public_api(): void
    {
        $slide = $this->slide(['title_en' => 'Toggle me']);
        $this->getJson('/api/hero-slides')->assertJsonCount(1, 'data');

        $this->actingAs($this->admin)->postJson("/api/admin/hero-slides/{$slide->id}/active", ['is_active' => false], self::EN)
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
        // Same value again: nothing new in the log.
        $this->actingAs($this->admin)->postJson("/api/admin/hero-slides/{$slide->id}/active", ['is_active' => false], self::EN)->assertOk();

        $this->getJson('/api/hero-slides')->assertOk()->assertExactJson(['data' => [], 'overlay' => 40]);
        // Admins still see the hidden slide.
        $this->actingAs($this->admin)->getJson('/api/admin/hero-slides', self::EN)->assertJsonPath('data.0.is_active', false);

        $this->actingAs($this->admin)->postJson("/api/admin/hero-slides/{$slide->id}/active", ['is_active' => true], self::EN)->assertOk();
        $this->getJson('/api/hero-slides')->assertJsonPath('data.0.title_en', 'Toggle me');

        $this->assertSame(1, AuditLog::where('action', 'hero_slide.deactivated')->count());
        $this->assertSame(1, AuditLog::where('action', 'hero_slide.activated')->count());
        $this->actingAs($this->admin)->postJson("/api/admin/hero-slides/{$slide->id}/active", [], self::EN)->assertJsonValidationErrors('is_active');
    }

    public function test_reorder_changes_the_public_order(): void
    {
        $a = $this->slide(['title_en' => 'A', 'sort_order' => 0]);
        $b = $this->slide(['title_en' => 'B', 'sort_order' => 1]);
        $c = $this->slide(['title_en' => 'C', 'sort_order' => 2]);

        foreach ([[$a->id, $b->id], [$a->id, $b->id, $c->id, 999], [$a->id, $a->id, $b->id]] as $ids) {
            $this->actingAs($this->admin)->putJson('/api/admin/hero-slides/order', ['ids' => $ids], self::EN)->assertUnprocessable();
        }

        $this->actingAs($this->admin)->putJson('/api/admin/hero-slides/order', ['ids' => [$c->id, $a->id, $b->id]], self::EN)
            ->assertOk()
            ->assertJsonPath('data.*.title_en', ['C', 'A', 'B']);

        $this->getJson('/api/hero-slides')->assertJsonPath('data.*.title_en', ['C', 'A', 'B']);
        $log = AuditLog::where('action', 'hero_slide.reordered')->firstOrFail();
        $this->assertSame([$c->id, $a->id, $b->id], $log->details['order']);
    }
}
