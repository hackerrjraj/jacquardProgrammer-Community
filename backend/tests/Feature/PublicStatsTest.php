<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicHomeController;
use App\Models\GalleryCategory;
use App\Models\GalleryImage;
use App\Models\Notice;
use App\Models\User;
use Database\Seeders\DemoProgrammersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PublicStatsTest extends TestCase
{
    use RefreshDatabase;

    private function photo(bool $published): void
    {
        (new GalleryImage)->forceFill([
            'gallery_category_id' => GalleryCategory::where('key', 'events')->value('id'),
            'image_path' => 'gallery/x.webp',
            'thumb_path' => 'gallery/thumbs/x.webp',
            'width' => 10,
            'height' => 10,
            'is_published' => $published,
        ])->save();
    }

    private function notice(array $fields): void
    {
        Notice::create(['title_bn' => 'নোটিশ', 'body_bn' => 'বিস্তারিত', 'is_published' => true, 'published_at' => now()->subDay(), ...$fields]);
    }

    public function test_counts_only_approved_real_records(): void
    {
        // Counted: 3 approved programmers (2 companies — same name typed differently —, 2 districts) + 1 admin-only account.
        User::factory()->approved()->create(['workplace' => 'Alpha Knit', 'district' => 'dhaka', 'experience_years' => 4]);
        User::factory()->approved()->create(['workplace' => '  alpha   KNIT ', 'district' => 'gazipur', 'experience_years' => 22]);
        User::factory()->committee()->create(['workplace' => 'Beta Sweaters', 'district' => 'dhaka', 'experience_years' => 7, 'email' => null]);
        User::factory()->superAdmin()->create(['workplace' => null, 'district' => null, 'experience_years' => null]);

        // Never counted: pending, rejected, suspended, demo programmers.
        User::factory()->create(['workplace' => 'Pending Co', 'district' => 'khulna', 'experience_years' => 40]);
        User::factory()->rejected()->create(['workplace' => 'Rejected Co', 'district' => 'sylhet']);
        User::factory()->suspended()->create(['workplace' => 'Suspended Co', 'district' => 'rajshahi', 'experience_years' => 50]);
        User::factory()->approved()->create([
            'email' => 'demo1@'.DemoProgrammersSeeder::EMAIL_DOMAIN, 'workplace' => 'Demo Co', 'district' => 'barishal', 'experience_years' => 60,
        ]);

        $this->notice([]);                                         // counted
        $this->notice(['visibility' => Notice::VISIBILITY_MEMBERS]); // members only
        $this->notice(['is_published' => false]);                  // draft
        $this->notice(['published_at' => now()->addDay()]);        // scheduled

        $this->photo(true);
        $this->photo(true);
        $this->photo(false);

        $this->getJson('/api/public/stats')
            ->assertOk()
            ->assertJsonPath('data.members', 4)
            ->assertJsonPath('data.programmers', 3)
            ->assertJsonPath('data.companies', 2)
            ->assertJsonPath('data.locations', 2)
            ->assertJsonPath('data.max_experience_years', 22)
            ->assertJsonPath('data.notices', 1)
            ->assertJsonPath('data.gallery_photos', 2)
            ->assertJsonMissingPath('data.total_collected')
            ->assertJsonMissingPath('data.total_disbursed')
            ->assertJsonMissingPath('data.fund_balance')
            ->assertJsonMissingPath('data.cases_helped');
    }

    public function test_empty_site_gives_zeros(): void
    {
        $this->getJson('/api/public/stats')
            ->assertOk()
            ->assertJsonPath('data.members', 0)
            ->assertJsonPath('data.programmers', 0)
            ->assertJsonPath('data.companies', 0)
            ->assertJsonPath('data.locations', 0)
            ->assertJsonPath('data.max_experience_years', 0)
            ->assertJsonPath('data.notices', 0)
            ->assertJsonPath('data.gallery_photos', 0);
    }

    public function test_result_is_cached_for_ten_minutes(): void
    {
        User::factory()->approved()->create();
        $this->getJson('/api/public/stats')->assertJsonPath('data.members', 1);

        User::factory()->approved()->create();
        $this->getJson('/api/public/stats')->assertJsonPath('data.members', 1);

        $this->travel(PublicHomeController::STATS_CACHE_SECONDS + 1)->seconds();
        $this->getJson('/api/public/stats')->assertJsonPath('data.members', 2);

        $this->assertTrue(Cache::has(PublicHomeController::STATS_CACHE_KEY));
    }
}
