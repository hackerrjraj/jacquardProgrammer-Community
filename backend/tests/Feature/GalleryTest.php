<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\GalleryCategory;
use App\Models\GalleryImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GalleryTest extends TestCase
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

    /** Uploads through the admin API (real resize) and returns the response. */
    private function upload(array $fields = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin)->post('/api/admin/gallery/images', [
            'images' => [UploadedFile::fake()->image('meetup.jpg', 2400, 1200)],
            'category' => 'events',
            ...$fields,
        ], self::EN);
    }

    /** @return list<int> ids of the uploaded images */
    private function uploadMany(int $count, array $fields = []): array
    {
        $files = [];
        for ($i = 0; $i < $count; $i++) {
            $files[] = UploadedFile::fake()->image("photo{$i}.png", 300, 200);
        }

        return collect($this->upload(['images' => $files, ...$fields])->assertCreated()->json('data'))->pluck('id')->all();
    }

    public function test_initial_categories_exist(): void
    {
        $this->getJson('/api/public/gallery/categories')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.*.key', ['programs', 'events', 'training', 'community', 'workplace'])
            ->assertJsonPath('data.1.name_en', 'Events')
            ->assertJsonPath('data.1.name_bn', 'ইভেন্ট')
            ->assertJsonPath('data.1.images_count', 0);
    }

    public function test_admin_uploads_resized_display_image_and_thumbnail(): void
    {
        $response = $this->upload(['title_bn' => '  বার্ষিক সভা ', 'title_en' => 'Annual meetup'])
            ->assertCreated()
            ->assertJsonPath('message', __('messages.gallery_uploaded', ['count' => 1], 'en'))
            ->assertJsonPath('data.0.title_bn', 'বার্ষিক সভা')
            ->assertJsonPath('data.0.category.key', 'events')
            ->assertJsonPath('data.0.is_published', true)
            ->assertJsonPath('data.0.width', 1600)
            ->assertJsonPath('data.0.height', 800);

        $image = GalleryImage::sole();
        $this->assertStringStartsWith('gallery/', $image->image_path);
        $this->assertStringStartsWith('gallery/thumbs/', $image->thumb_path);
        $this->assertStringNotContainsString('meetup', $image->image_path);
        $this->assertSame($this->admin->id, $image->uploaded_by);

        $disk = Storage::disk('public');
        $display = getimagesizefromstring($disk->get($image->image_path));
        $thumb = getimagesizefromstring($disk->get($image->thumb_path));
        $this->assertSame([1600, 800, 'image/webp'], [$display[0], $display[1], $display['mime']]);
        $this->assertSame([600, 300, 'image/webp'], [$thumb[0], $thumb[1], $thumb['mime']]);
        $this->assertStringContainsString('/storage/gallery/thumbs/', $response->json('data.0.thumb_url'));

        $log = AuditLog::where('action', 'gallery.images_uploaded')->sole();
        $this->assertSame([$image->id], $log->details['image_ids']);
        $this->assertSame('events', $log->details['category']);
        $this->assertSame($this->admin->id, $log->actor_id);
    }

    public function test_small_images_are_not_enlarged_and_new_uploads_go_on_top(): void
    {
        $old = $this->uploadMany(1);
        $new = $this->uploadMany(2);

        $this->getJson('/api/public/gallery')
            ->assertJsonPath('data.*.id', [...$new, ...$old])
            ->assertJsonPath('data.0.width', 300)
            ->assertJsonPath('data.0.height', 200);
    }

    public function test_upload_validation(): void
    {
        $cases = [
            ['images.0', ['images' => [UploadedFile::fake()->image('big.jpg')->size(5121)]]],
            ['images.1', ['images' => [UploadedFile::fake()->image('ok.jpg'), UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')]]],
            ['images.0', ['images' => [UploadedFile::fake()->create('anim.gif', 10, 'image/gif')]]],
            ['images.0', ['images' => [UploadedFile::fake()->image('huge.png', 6001, 10)]]],
            ['images', ['images' => []]],
            ['category', ['category' => 'weddings']],
            ['category', ['category' => '']],
        ];

        foreach ($cases as [$field, $fields]) {
            $this->upload($fields)->assertUnprocessable()->assertJsonValidationErrors($field);
        }

        $tooMany = array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg", 10, 10), range(1, 21));
        $this->upload(['images' => $tooMany])->assertUnprocessable()->assertJsonValidationErrors('images');

        $this->assertSame(0, GalleryImage::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_gallery_accepts_photos_up_to_5_mb_with_clear_messages(): void
    {
        $this->upload(['images' => [UploadedFile::fake()->image('phone.jpg', 40, 30)->size(5120)]])->assertCreated();

        $this->upload(['images' => [UploadedFile::fake()->image('big.jpg', 40, 30)->size(5121)]])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['images.0' => ['Each photo must be 5 MB or smaller.']]);

        $bn = $this->actingAs($this->admin)->post('/api/admin/gallery/images', [
            'images' => [UploadedFile::fake()->image('big.jpg', 40, 30)->size(5121)],
            'category' => 'events',
        ], ['Accept' => 'application/json', 'Accept-Language' => 'bn'])->assertUnprocessable();
        $this->assertSame(['প্রতিটি ছবি সর্বোচ্চ ৫ এমবি হতে পারবে।'], $bn->json('errors')['images.0']);

        $this->upload(['images' => [UploadedFile::fake()->image('huge.png', 6001, 10)]])
            ->assertJsonPath('errors', ['images.0' => ['This photo could not be read, or it is larger than 6000 × 6000 pixels.']]);

        $this->assertSame(1, GalleryImage::count());
    }

    public function test_unreadable_image_is_refused_and_leaves_no_files(): void
    {
        $fake = UploadedFile::fake()->createWithContent('broken.jpg', "\xFF\xD8\xFF\xE0".str_repeat('x', 200));

        $this->upload(['images' => [UploadedFile::fake()->image('good.jpg', 50, 50), $fake]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('images.1');

        $this->assertSame(0, GalleryImage::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_public_listing_hides_unpublished_and_filters_by_category(): void
    {
        $events = $this->uploadMany(2);
        $hidden = $this->uploadMany(1, ['is_published' => '0']);
        $training = $this->uploadMany(1, ['category' => 'training']);

        $this->getJson('/api/public/gallery')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.*.id', [...$training, ...$events])
            ->assertJsonPath('meta.total', 3)
            ->assertJsonMissingPath('data.0.is_published');

        $this->getJson('/api/public/gallery?category=events')->assertJsonPath('data.*.id', $events);
        $this->getJson('/api/public/gallery?category=weddings')->assertUnprocessable();

        $this->getJson('/api/public/gallery/categories')
            ->assertJsonPath('data.1.images_count', 2)  // events: unpublished one not counted
            ->assertJsonPath('data.2.images_count', 1); // training

        $this->assertNotContains($hidden[0], $this->getJson('/api/public/gallery')->json('data.*.id'));
    }

    public function test_public_image_is_404_when_unpublished_or_missing(): void
    {
        [$published] = $this->uploadMany(1);
        [$hidden] = $this->uploadMany(1, ['is_published' => '0']);

        $this->getJson("/api/public/gallery/{$published}")->assertOk()->assertJsonPath('data.id', $published);

        foreach ([$hidden, 9999, 'abc'] as $id) {
            $this->getJson("/api/public/gallery/{$id}", self::EN)
                ->assertNotFound()
                ->assertExactJson(['message' => __('messages.gallery_image_not_found', [], 'en')]);
        }
    }

    public function test_admin_edits_title_category_and_publish_state(): void
    {
        [$id] = $this->uploadMany(1);

        $this->actingAs($this->admin)->patchJson("/api/admin/gallery/images/{$id}", [
            'title_en' => 'Training day',
            'category' => 'training',
            'is_published' => false,
        ], self::EN)
            ->assertOk()
            ->assertJsonPath('data.title_en', 'Training day')
            ->assertJsonPath('data.category.key', 'training')
            ->assertJsonPath('data.is_published', false);

        $this->getJson("/api/public/gallery/{$id}")->assertNotFound();

        $changes = AuditLog::where('action', 'gallery.image_updated')->sole()->details['changes'];
        $this->assertSame(['from' => null, 'to' => 'Training day'], $changes['title_en']);
        $this->assertArrayHasKey('gallery_category_id', $changes);
        $this->assertArrayHasKey('is_published', $changes);

        $this->patchJson("/api/admin/gallery/images/{$id}", ['is_published' => true])->assertOk();
        $this->getJson("/api/public/gallery/{$id}")->assertOk();
    }

    public function test_delete_removes_the_files(): void
    {
        [$keep, $delete] = $this->uploadMany(2);
        $image = GalleryImage::findOrFail($delete);
        $disk = Storage::disk('public');
        $disk->assertExists([$image->image_path, $image->thumb_path]);

        $this->actingAs($this->admin)->deleteJson("/api/admin/gallery/images/{$delete}")->assertOk();

        $disk->assertMissing([$image->image_path, $image->thumb_path]);
        $this->assertSame([$keep], GalleryImage::pluck('id')->all());
        $this->assertCount(2, $disk->allFiles()); // the other image's display + thumbnail
        $this->assertSame($delete, AuditLog::where('action', 'gallery.image_deleted')->sole()->details['image_id']);

        $this->deleteJson("/api/admin/gallery/images/{$delete}")->assertNotFound();
    }

    public function test_reorder_moves_only_the_listed_images(): void
    {
        [$a, $b, $c, $d] = $this->uploadMany(4); // shown a, b, c, d

        $this->actingAs($this->admin)->putJson('/api/admin/gallery/images/order', ['ids' => [$d, $b]])->assertOk();
        $this->getJson('/api/public/gallery')->assertJsonPath('data.*.id', [$a, $d, $c, $b]);

        $this->putJson('/api/admin/gallery/images/order', ['ids' => [$a, 9999]], self::EN)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ids');
        $this->assertSame(1, AuditLog::where('action', 'gallery.images_reordered')->count());
    }

    public function test_admin_manages_categories(): void
    {
        $this->actingAs($this->admin);

        $id = $this->postJson('/api/admin/gallery/categories', ['key' => 'awards', 'name_bn' => 'পুরস্কার', 'name_en' => 'Awards'])
            ->assertCreated()
            ->assertJsonPath('data.sort_order', 6)
            ->json('data.id');

        $this->postJson('/api/admin/gallery/categories', ['key' => 'awards', 'name_bn' => 'x', 'name_en' => 'x'])->assertJsonValidationErrors('key');
        $this->postJson('/api/admin/gallery/categories', ['key' => 'Big Events', 'name_bn' => 'x', 'name_en' => 'x'])->assertJsonValidationErrors('key');

        $this->patchJson("/api/admin/gallery/categories/{$id}", ['name_en' => 'Awards & Honours', 'key' => 'ignored'])
            ->assertOk()
            ->assertJsonPath('data.name_en', 'Awards & Honours')
            ->assertJsonPath('data.key', 'awards');

        // A category with photos cannot be deleted.
        $this->uploadMany(1, ['category' => 'awards']);
        $this->deleteJson("/api/admin/gallery/categories/{$id}", [], self::EN)
            ->assertUnprocessable()
            ->assertJsonPath('message', __('messages.gallery_category_not_empty', [], 'en'));
        $this->deleteJson("/api/admin/gallery/categories/{$id}", [], ['Accept' => 'application/json', 'Accept-Language' => 'bn'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'এই ক্যাটাগরিতে এখনো ছবি আছে। আগে ছবিগুলো সরান বা মুছে ফেলুন।');

        $this->getJson('/api/admin/gallery/categories')->assertJsonPath('data.5.images_count', 1);

        $empty = GalleryCategory::where('key', 'workplace')->value('id');
        $this->deleteJson("/api/admin/gallery/categories/{$empty}")->assertOk();
        $this->assertNull(GalleryCategory::find($empty));

        $this->assertSame(
            ['gallery.category_created', 'gallery.category_updated', 'gallery.images_uploaded', 'gallery.category_deleted'],
            AuditLog::orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_admin_list_shows_unpublished_and_filters(): void
    {
        $this->uploadMany(2);
        [$hidden] = $this->uploadMany(1, ['is_published' => '0']);

        $this->actingAs($this->admin)->getJson('/api/admin/gallery/images')
            ->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.is_published', false);
        $this->getJson('/api/admin/gallery/images?status=unpublished')->assertJsonPath('data.*.id', [$hidden]);
        $this->getJson('/api/admin/gallery/images?status=published&category=events')->assertJsonCount(2, 'data');
    }

    public function test_only_admin_and_super_admin_can_manage(): void
    {
        $requests = [
            ['get', '/api/admin/gallery/images'],
            ['post', '/api/admin/gallery/images'],
            ['patch', '/api/admin/gallery/images/1'],
            ['delete', '/api/admin/gallery/images/1'],
            ['put', '/api/admin/gallery/images/order'],
            ['get', '/api/admin/gallery/categories'],
            ['post', '/api/admin/gallery/categories'],
            ['patch', '/api/admin/gallery/categories/2'],
            ['delete', '/api/admin/gallery/categories/2'],
        ];

        foreach ($requests as [$method, $url]) {
            $this->json($method, $url)->assertUnauthorized();
        }

        $this->uploadMany(1); // image 1 in category 2 (events)

        foreach ([User::factory()->approved()->create(), User::factory()->committee()->create()] as $user) {
            foreach ($requests as [$method, $url]) {
                $this->actingAs($user)->json($method, $url)->assertForbidden();
            }
        }

        $this->assertSame(1, GalleryImage::count());
        $this->assertSame(5, GalleryCategory::count());

        $this->upload([], User::factory()->superAdmin()->create())->assertCreated();
    }
}
