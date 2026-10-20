<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PortfolioItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PortfolioTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->member = User::factory()->approved()->create();
    }

    private function add(array $fields = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->member)->post('/api/profile/portfolio', [
            'image' => UploadedFile::fake()->image('sweater.jpg', 400, 300),
            'category' => 'jacquard',
            'title' => 'Nordic Jacquard Collection',
            'description' => 'Three-colour snowflake jacquard.',
            ...$fields,
        ], ['Accept' => 'application/json', 'Accept-Language' => 'en']);
    }

    public function test_member_adds_item_with_random_image_name(): void
    {
        $this->add()
            ->assertCreated()
            ->assertJsonPath('message', __('messages.portfolio_added', [], 'en'))
            ->assertJsonPath('data.category', 'jacquard')
            ->assertJsonPath('data.title', 'Nordic Jacquard Collection')
            ->assertJsonPath('data.image_url', fn ($url) => str_contains($url, '/storage/portfolio/'));

        $item = PortfolioItem::sole();
        $this->assertStringStartsWith('portfolio/', $item->image_path);
        $this->assertStringNotContainsString('sweater', $item->image_path);
        Storage::disk('public')->assertExists($item->image_path);
        $this->assertSame($item->id, AuditLog::where('action', 'portfolio.added')->sole()->details['item_id']);

        $this->getJson('/api/profile/portfolio')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/profile')->assertJsonCount(1, 'data.portfolio');
    }

    public function test_description_is_optional(): void
    {
        $this->add(['description' => ''])->assertCreated()->assertJsonPath('data.description', null);
    }

    public function test_portfolio_limit_is_12(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->add(['title' => "Item {$i}"])->assertCreated();
        }

        $this->add()->assertUnprocessable()->assertJsonPath('message', __('messages.portfolio_limit', ['max' => 12], 'en'));
        $this->assertSame(12, PortfolioItem::count());
        $this->assertCount(12, Storage::disk('public')->allFiles('portfolio'));
    }

    public function test_image_size_and_type_are_checked(): void
    {
        foreach ([
            UploadedFile::fake()->image('big.jpg')->size(2049),
            UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
            UploadedFile::fake()->create('anim.gif', 10, 'image/gif'),
        ] as $file) {
            $this->add(['image' => $file])->assertUnprocessable()->assertJsonValidationErrors('image');
        }

        $this->add(['image' => UploadedFile::fake()->image('ok.webp')])->assertCreated();
        $this->add(['image' => UploadedFile::fake()->image('ok.png')])->assertCreated();
        $this->assertSame(2, PortfolioItem::count());
    }

    public function test_required_fields_category_and_lengths_are_checked(): void
    {
        $this->actingAs($this->member)
            ->post('/api/profile/portfolio', [], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image', 'category', 'title']);

        $this->add(['category' => 'mentoring'])->assertJsonValidationErrors('category');
        $this->add(['title' => str_repeat('t', 101)])->assertJsonValidationErrors('title');
        $this->add(['description' => str_repeat('d', 301)])->assertJsonValidationErrors('description');
        $this->add(['title' => str_repeat('t', 100), 'description' => str_repeat('d', 300), 'category' => 'optimisation'])->assertCreated();

        $this->assertSame(1, PortfolioItem::count());
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_update_fields_and_replace_image_deletes_old_file(): void
    {
        $this->add()->assertCreated();
        $item = PortfolioItem::sole();
        $oldPath = $item->image_path;

        $this->putJson("/api/profile/portfolio/{$item->id}", ['title' => 'Renamed', 'category' => 'sampling'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Renamed')
            ->assertJsonPath('data.category', 'sampling')
            ->assertJsonPath('data.description', 'Three-colour snowflake jacquard.');
        Storage::disk('public')->assertExists($oldPath);

        // Multipart with _method=PUT (how the browser sends a file on update).
        $this->post("/api/profile/portfolio/{$item->id}", [
            '_method' => 'PUT',
            'image' => UploadedFile::fake()->image('new.png'),
        ], ['Accept' => 'application/json'])->assertOk();

        $newPath = $item->refresh()->image_path;
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('public')->assertExists($newPath);
        Storage::disk('public')->assertMissing($oldPath);
        $this->assertSame('Renamed', $item->title);

        $log = AuditLog::where('action', 'portfolio.updated')->first();
        $this->assertSame(['from' => 'Nordic Jacquard Collection', 'to' => 'Renamed'], $log->details['changes']['title']);

        $this->putJson("/api/profile/portfolio/{$item->id}", ['category' => 'bad', 'title' => ''])
            ->assertJsonValidationErrors(['category', 'title']);
    }

    public function test_delete_removes_item_and_file(): void
    {
        $this->add()->assertCreated();
        $item = PortfolioItem::sole();

        $this->deleteJson("/api/profile/portfolio/{$item->id}")->assertOk();

        $this->assertSame(0, PortfolioItem::count());
        Storage::disk('public')->assertMissing($item->image_path);
        $this->assertSame($item->id, AuditLog::where('action', 'portfolio.deleted')->sole()->details['item_id']);
    }

    public function test_reorder(): void
    {
        foreach (['A', 'B', 'C'] as $title) {
            $this->add(['title' => $title])->assertCreated();
        }
        [$a, $b, $c] = PortfolioItem::orderBy('id')->pluck('id')->all();

        $this->putJson('/api/profile/portfolio/order', ['ids' => [$c, $a, $b]])
            ->assertOk()
            ->assertJsonPath('data.0.title', 'C')
            ->assertJsonPath('data.1.title', 'A')
            ->assertJsonPath('data.2.title', 'B');

        $this->getJson('/api/profile')->assertJsonPath('data.portfolio.0.title', 'C');
        $this->assertSame([$c, $a, $b], AuditLog::where('action', 'portfolio.reordered')->sole()->details['order']);

        // Must list every own item exactly once.
        foreach ([[$a, $b], [$a, $b, $c, 999], [$a, $a, $b], []] as $bad) {
            $this->putJson('/api/profile/portfolio/order', ['ids' => $bad])->assertUnprocessable();
        }
        $this->getJson('/api/profile/portfolio')->assertJsonPath('data.0.title', 'C');
    }

    public function test_only_the_owner_can_modify_items(): void
    {
        $this->add()->assertCreated();
        $item = PortfolioItem::sole();

        foreach ([User::factory()->approved()->create(), User::factory()->admin()->create(), User::factory()->superAdmin()->create()] as $other) {
            $this->actingAs($other)->putJson("/api/profile/portfolio/{$item->id}", ['title' => 'Hacked'])->assertNotFound();
            $this->actingAs($other)->deleteJson("/api/profile/portfolio/{$item->id}")->assertNotFound();
            $this->actingAs($other)->putJson('/api/profile/portfolio/order', ['ids' => [$item->id]])->assertUnprocessable();
            $this->actingAs($other)->getJson('/api/profile/portfolio')->assertJsonCount(0, 'data');
        }

        $this->assertSame('Nordic Jacquard Collection', $item->refresh()->title);
        Storage::disk('public')->assertExists($item->image_path);
    }

    public function test_pending_and_suspended_users_are_blocked(): void
    {
        foreach ([User::factory()->create(), User::factory()->suspended()->create()] as $user) {
            $this->app['auth']->forgetGuards();
            $this->add([], $user)->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/profile/portfolio')->assertUnauthorized();
        $this->assertSame(0, PortfolioItem::count());
    }
}
