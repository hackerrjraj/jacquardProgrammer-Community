<?php

namespace Tests\Feature;

use App\Models\HeroSlide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HeroSlidesTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_only_active_slides_in_sort_order_with_full_image_url(): void
    {
        HeroSlide::factory()->create(['title_en' => 'Second', 'sort_order' => 2, 'image_path' => 'slides/b.svg']);
        HeroSlide::factory()->create(['title_en' => 'First', 'sort_order' => 1, 'image_path' => 'slides/a.svg']);
        HeroSlide::factory()->inactive()->create(['title_en' => 'Hidden', 'sort_order' => 0]);

        $response = $this->getJson('/api/hero-slides');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title_en', 'First')
            ->assertJsonPath('data.1.title_en', 'Second')
            ->assertJsonPath('data.0.image_url', rtrim(config('app.url'), '/').'/storage/slides/a.svg')
            ->assertJsonStructure(['data' => [[
                'id', 'image_url', 'title_bn', 'title_en', 'subtitle_bn', 'subtitle_en',
                'button_text_bn', 'button_text_en', 'button_link', 'sort_order',
            ]]]);

        $this->assertNotContains('Hidden', $response->json('data.*.title_en'));
    }

    public function test_returns_empty_list_when_no_active_slides(): void
    {
        HeroSlide::factory()->inactive()->create();

        $this->getJson('/api/hero-slides')->assertOk()->assertExactJson(['data' => [], 'overlay' => 40]);
    }
}
