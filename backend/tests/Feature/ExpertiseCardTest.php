<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ExpertiseCard;
use App\Models\User;
use Database\Seeders\ExpertiseCardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExpertiseCardTest extends TestCase
{
    use RefreshDatabase;

    private const EN = ['Accept' => 'application/json', 'Accept-Language' => 'en'];

    private const BN = ['Accept' => 'application/json', 'Accept-Language' => 'bn'];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->admin = User::factory()->admin()->create();
    }

    private function card(array $fields = []): ExpertiseCard
    {
        return ExpertiseCard::create([
            'title_bn' => 'কার্ড',
            'description_bn' => 'বিবরণ',
            'sort_order' => 0,
            'is_published' => true,
            ...$fields,
        ]);
    }

    public function test_seeder_adds_the_three_default_cards_once(): void
    {
        $this->seed(ExpertiseCardSeeder::class);
        $this->seed(ExpertiseCardSeeder::class);

        $this->assertSame(['STOLL', 'SHIMA SEIKI', 'Sweater Programming'], ExpertiseCard::query()->ordered()->pluck('title_en')->all());
        $this->assertSame(3, ExpertiseCard::where('is_published', true)->count());
    }

    public function test_public_lists_only_published_cards_in_order(): void
    {
        $this->card(['title_bn' => 'তৃতীয়', 'sort_order' => 3]);
        $this->card(['title_bn' => 'লুকানো', 'sort_order' => 1, 'is_published' => false]);
        $this->card(['title_bn' => 'প্রথম', 'sort_order' => 0]);
        $this->card(['title_bn' => 'দ্বিতীয়', 'sort_order' => 2]);

        $this->getJson('/api/public/expertise-cards', self::BN)
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.*.title_bn', ['প্রথম', 'দ্বিতীয়', 'তৃতীয়'])
            ->assertJsonMissingPath('data.0.is_published');
    }

    public function test_english_falls_back_to_bengali(): void
    {
        $this->card(['title_bn' => 'শুধু বাংলা', 'description_bn' => 'বাংলা বিবরণ', 'sort_order' => 0]);
        $this->card(['title_bn' => 'দুই ভাষা', 'title_en' => 'Both', 'description_bn' => 'বাংলা', 'description_en' => 'English text', 'icon' => 'chip', 'sort_order' => 1]);

        $this->getJson('/api/public/expertise-cards', self::EN)
            ->assertOk()
            ->assertJsonPath('data.0.title', 'শুধু বাংলা')
            ->assertJsonPath('data.0.description', 'বাংলা বিবরণ')
            ->assertJsonPath('data.0.title_en', null)
            ->assertJsonPath('data.1.title', 'Both')
            ->assertJsonPath('data.1.description', 'English text')
            ->assertJsonPath('data.1.icon', 'chip')
            ->assertJsonPath('data.1.image_url', null);

        $this->getJson('/api/public/expertise-cards', self::BN)
            ->assertJsonPath('data.1.title', 'দুই ভাষা')
            ->assertJsonPath('data.1.description', 'বাংলা');
    }

    public function test_only_admins_can_use_the_admin_routes(): void
    {
        $card = $this->card();
        $routes = [
            ['GET', '/api/admin/expertise-cards'],
            ['POST', '/api/admin/expertise-cards'],
            ['PATCH', "/api/admin/expertise-cards/{$card->id}"],
            ['DELETE', "/api/admin/expertise-cards/{$card->id}"],
            ['PUT', '/api/admin/expertise-cards/order'],
            ['POST', "/api/admin/expertise-cards/{$card->id}/publish"],
            ['POST', "/api/admin/expertise-cards/{$card->id}/unpublish"],
            ['POST', "/api/admin/expertise-cards/{$card->id}/image"],
            ['DELETE', "/api/admin/expertise-cards/{$card->id}/image"],
        ];

        foreach ($routes as [$method, $url]) {
            $this->json($method, $url, [], self::EN)->assertUnauthorized();
        }

        foreach ([User::factory()->approved()->create(), User::factory()->committee()->create()] as $user) {
            foreach ($routes as [$method, $url]) {
                $this->actingAs($user)->json($method, $url, [], self::EN)->assertForbidden();
            }
        }

        $this->assertSame(1, ExpertiseCard::count());
        $this->assertSame(0, AuditLog::count());

        $this->actingAs(User::factory()->superAdmin()->create())->getJson('/api/admin/expertise-cards', self::EN)->assertOk();
    }

    public function test_admin_creates_validates_and_updates_cards_with_audit_log(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/expertise-cards', ['title_en' => str_repeat('x', 101), 'icon' => 'bad icon!'], self::EN)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title_bn', 'description_bn', 'title_en', 'icon']);

        $this->actingAs($this->admin)->postJson('/api/admin/expertise-cards', ['title_bn' => ''], self::BN)
            ->assertUnprocessable()
            ->assertJsonPath('errors.title_bn.0', __('validation.required', ['attribute' => 'শিরোনাম (বাংলা)'], 'bn'));

        $first = $this->actingAs($this->admin)->postJson('/api/admin/expertise-cards', [
            'title_bn' => ' STOLL ',
            'description_bn' => 'বাংলা',
            'title_en' => '',
            'icon' => 'chip',
        ], self::EN)
            ->assertCreated()
            ->assertJsonPath('message', __('messages.expertise_created', [], 'en'))
            ->assertJsonPath('data.title_bn', 'STOLL')
            ->assertJsonPath('data.title_en', null)
            ->assertJsonPath('data.is_published', true)
            ->assertJsonPath('data.sort_order', 0)
            ->json('data.id');

        $second = $this->actingAs($this->admin)->postJson('/api/admin/expertise-cards', [
            'title_bn' => 'দ্বিতীয়', 'description_bn' => 'বাংলা', 'is_published' => false,
        ], self::EN)->assertCreated()->assertJsonPath('data.sort_order', 1)->assertJsonPath('data.is_published', false);

        $this->assertSame(['title_bn' => 'STOLL', 'title_en' => null, 'icon' => 'chip', 'is_published' => true, 'has_image' => false],
            AuditLog::where('action', 'expertise_card.created')->where('target_id', $first)->sole()->details);

        $this->actingAs($this->admin)->patchJson("/api/admin/expertise-cards/{$first}", ['title_en' => 'STOLL', 'description_bn' => 'নতুন'], self::EN)
            ->assertOk()
            ->assertJsonPath('data.title_en', 'STOLL')
            ->assertJsonPath('data.description_bn', 'নতুন')
            ->assertJsonPath('data.icon', 'chip');

        $log = AuditLog::where('action', 'expertise_card.updated')->sole();
        $this->assertSame($this->admin->id, $log->actor_id);
        $this->assertSame([
            'title_en' => ['from' => null, 'to' => 'STOLL'],
            'description_bn' => ['from' => 'বাংলা', 'to' => 'নতুন'],
        ], $log->details['changes']);

        // Nothing changed → no new audit row; required fields cannot be emptied.
        $this->actingAs($this->admin)->patchJson("/api/admin/expertise-cards/{$first}", ['title_en' => 'STOLL'], self::EN)->assertOk();
        $this->actingAs($this->admin)->patchJson("/api/admin/expertise-cards/{$first}", ['title_bn' => ''], self::EN)->assertJsonValidationErrors('title_bn');
        $this->assertSame(1, AuditLog::where('action', 'expertise_card.updated')->count());

        $this->actingAs($this->admin)->patchJson('/api/admin/expertise-cards/999', ['title_en' => 'x'], self::EN)->assertNotFound();
        $this->assertNotNull($second);
    }

    public function test_publish_and_hide(): void
    {
        $card = $this->card();

        $this->actingAs($this->admin)->postJson("/api/admin/expertise-cards/{$card->id}/unpublish", [], self::EN)
            ->assertOk()->assertJsonPath('data.is_published', false);
        $this->actingAs($this->admin)->postJson("/api/admin/expertise-cards/{$card->id}/unpublish", [], self::EN)->assertOk();
        $this->getJson('/api/public/expertise-cards')->assertJsonCount(0, 'data');

        $this->actingAs($this->admin)->postJson("/api/admin/expertise-cards/{$card->id}/publish", [], self::EN)
            ->assertOk()->assertJsonPath('data.is_published', true);
        $this->getJson('/api/public/expertise-cards')->assertJsonCount(1, 'data');

        $this->assertSame(['expertise_card.unpublished', 'expertise_card.published'], AuditLog::orderBy('id')->pluck('action')->all());

        // Admin list still shows hidden cards.
        $card->update(['is_published' => false]);
        $this->actingAs($this->admin)->getJson('/api/admin/expertise-cards', self::EN)->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_published', false);
    }

    public function test_reorder_needs_every_card_once(): void
    {
        $a = $this->card(['sort_order' => 0]);
        $b = $this->card(['sort_order' => 1]);
        $c = $this->card(['sort_order' => 2]);

        foreach ([[$a->id, $b->id], [$a->id, $b->id, $c->id, 999], [$a->id, $a->id, $b->id]] as $ids) {
            $this->actingAs($this->admin)->putJson('/api/admin/expertise-cards/order', ['ids' => $ids], self::EN)->assertUnprocessable();
        }

        $this->actingAs($this->admin)->putJson('/api/admin/expertise-cards/order', ['ids' => [$c->id, $a->id, $b->id]], self::EN)
            ->assertOk()
            ->assertJsonPath('data.*.id', [$c->id, $a->id, $b->id]);

        $this->getJson('/api/public/expertise-cards')->assertJsonPath('data.*.id', [$c->id, $a->id, $b->id]);
        $this->assertSame(['order' => [$c->id, $a->id, $b->id]], AuditLog::where('action', 'expertise_card.reordered')->sole()->details);
    }

    public function test_image_upload_replace_remove_and_delete_remove_files(): void
    {
        $id = $this->actingAs($this->admin)->post('/api/admin/expertise-cards', [
            'title_bn' => 'ছবিসহ', 'description_bn' => 'বিবরণ', 'image' => UploadedFile::fake()->image('stoll.jpg', 400, 300),
        ], self::EN)->assertCreated()->json('data.id');

        $card = ExpertiseCard::findOrFail($id);
        $first = $card->image_path;
        $this->assertStringStartsWith('expertise/', $first);
        $this->assertStringNotContainsString('stoll', $first);
        Storage::disk('public')->assertExists($first);
        $this->getJson('/api/public/expertise-cards')->assertJsonPath('data.0.image_url', Storage::disk('public')->url($first));

        // Wrong type / too big refused.
        $this->actingAs($this->admin)->post("/api/admin/expertise-cards/{$id}/image", ['image' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')], self::EN)
            ->assertJsonValidationErrors('image');
        $this->actingAs($this->admin)->post("/api/admin/expertise-cards/{$id}/image", ['image' => UploadedFile::fake()->image('big.jpg')->size(2049)], self::EN)
            ->assertJsonValidationErrors('image');

        $this->actingAs($this->admin)->post("/api/admin/expertise-cards/{$id}/image", ['image' => UploadedFile::fake()->image('new.png', 200, 200)], self::EN)->assertOk();
        $second = $card->refresh()->image_path;
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);

        $this->actingAs($this->admin)->deleteJson("/api/admin/expertise-cards/{$id}/image", [], self::EN)->assertOk()->assertJsonPath('data.image_url', null);
        Storage::disk('public')->assertMissing($second);

        $this->actingAs($this->admin)->post("/api/admin/expertise-cards/{$id}/image", ['image' => UploadedFile::fake()->image('third.webp', 200, 200)], self::EN)->assertOk();
        $third = $card->refresh()->image_path;

        $this->actingAs($this->admin)->deleteJson("/api/admin/expertise-cards/{$id}", [], self::EN)
            ->assertOk()->assertJsonPath('message', __('messages.expertise_deleted', [], 'en'));
        $this->assertNull(ExpertiseCard::find($id));
        Storage::disk('public')->assertMissing($third);

        $this->assertSame([
            'expertise_card.created', 'expertise_card.image_replaced', 'expertise_card.image_removed',
            'expertise_card.image_replaced', 'expertise_card.deleted',
        ], AuditLog::orderBy('id')->pluck('action')->all());
        $this->assertSame('ছবিসহ', AuditLog::where('action', 'expertise_card.deleted')->sole()->details['title_bn']);
    }

    public function test_card_limit(): void
    {
        for ($i = 0; $i < ExpertiseCard::MAX_CARDS; $i++) {
            $this->card(['sort_order' => $i]);
        }

        $this->actingAs($this->admin)->postJson('/api/admin/expertise-cards', ['title_bn' => 'আরও', 'description_bn' => 'বিবরণ'], self::EN)
            ->assertUnprocessable()
            ->assertJsonPath('message', __('messages.expertise_limit', ['max' => ExpertiseCard::MAX_CARDS], 'en'));
    }
}
