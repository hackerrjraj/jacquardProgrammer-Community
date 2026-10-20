<?php

namespace Tests\Feature;

use App\Models\MemberDocument;
use App\Models\PortfolioItem;
use App\Models\User;
use App\Models\WorkExperience;
use Database\Seeders\DemoProgrammersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoProgrammersTest extends TestCase
{
    use RefreshDatabase;

    private function demoUsers()
    {
        return User::where('email', 'like', '%@demo.jpphaf.local');
    }

    public function test_seeder_creates_15_approved_directory_programmers_and_can_run_again(): void
    {
        Storage::fake('public');

        $this->artisan('db:seed', ['--class' => DemoProgrammersSeeder::class])->assertSuccessful();

        $this->assertSame(15, $this->demoUsers()->count());
        $this->assertSame(15, User::query()->inDirectory()->count());
        $this->assertSame(0, $this->demoUsers()->where('status', '!=', 'approved')->count());
        $this->assertGreaterThan(0, WorkExperience::count());
        $this->assertGreaterThan(0, PortfolioItem::count());
        // Mixed visibility, varied districts and employment.
        $this->assertGreaterThan(1, $this->demoUsers()->distinct()->count('phone_visibility'));
        $this->assertGreaterThan(3, $this->demoUsers()->distinct()->count('district'));
        $this->assertGreaterThan(1, $this->demoUsers()->distinct()->count('employment_status'));
        foreach (PortfolioItem::pluck('image_path') as $path) {
            Storage::disk('public')->assertExists($path);
        }

        $this->getJson('/api/public/programmers')->assertOk()->assertJsonPath('meta.total', 15);

        // Again: same accounts, no duplicates.
        $portfolioCount = PortfolioItem::count();
        $this->artisan('db:seed', ['--class' => DemoProgrammersSeeder::class])->assertSuccessful();
        $this->assertSame(15, User::count());
        $this->assertSame($portfolioCount, PortfolioItem::count());
    }

    public function test_seeder_is_not_part_of_the_default_seeder(): void
    {
        $this->assertStringNotContainsString(
            'DemoProgrammersSeeder',
            file_get_contents(database_path('seeders/DatabaseSeeder.php')),
        );
    }

    public function test_remove_command_deletes_only_demo_programmers_and_their_files(): void
    {
        Storage::fake('public');
        Storage::fake('private');

        $real = User::factory()->approved()->create(['email' => 'real@example.com']);
        // Look-alike domains are not demo accounts.
        $lookAlike = User::factory()->approved()->create(['email' => 'someone@demo.jpphaf.local.example.com']);
        $noEmail = User::factory()->approved()->create(['email' => null]);
        $realWork = $real->workExperiences()->create(['company' => 'Real Co', 'position' => 'Programmer', 'start_year' => 2020]);
        Storage::disk('public')->put('portfolio/real.jpg', 'x');
        $realItem = new PortfolioItem(['category' => 'other', 'title' => 'Real item']);
        $realItem->user()->associate($real);
        $realItem->image_path = 'portfolio/real.jpg';
        $realItem->save();

        $this->artisan('db:seed', ['--class' => DemoProgrammersSeeder::class])->assertSuccessful();

        // A demo user with a private document, to check it is removed too.
        $demo = $this->demoUsers()->first();
        Storage::disk('private')->put('member-documents/demo.pdf', 'x');
        $demo->documents()->create(['type' => 'other', 'original_name' => 'a.pdf', 'path' => 'member-documents/demo.pdf', 'mime_type' => 'application/pdf', 'size' => 1]);
        $demoFiles = $this->demoUsers()->whereNotNull('photo_path')->pluck('photo_path')
            ->merge(PortfolioItem::whereIn('user_id', $this->demoUsers()->pluck('id'))->pluck('image_path'));
        $this->assertNotEmpty($demoFiles);

        $this->artisan('jpphaf:remove-demo-programmers')
            ->expectsOutputToContain('Removed 15 demo programmers')
            ->assertSuccessful();

        $this->assertSame(0, $this->demoUsers()->count());
        $this->assertEqualsCanonicalizing([$real->id, $lookAlike->id, $noEmail->id], User::pluck('id')->all());
        foreach ($demoFiles as $path) {
            Storage::disk('public')->assertMissing($path);
        }
        Storage::disk('private')->assertMissing('member-documents/demo.pdf');
        $this->assertSame(0, MemberDocument::count());

        // Real member's data and files untouched.
        $this->assertTrue(WorkExperience::whereKey($realWork->id)->exists());
        $this->assertSame([$realItem->id], PortfolioItem::pluck('id')->all());
        Storage::disk('public')->assertExists('portfolio/real.jpg');

        // Running again is harmless.
        $this->artisan('jpphaf:remove-demo-programmers')
            ->expectsOutputToContain('nothing to remove')
            ->assertSuccessful();
    }
}
