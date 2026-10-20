<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicProgrammerController;
use App\Models\User;
use Database\Seeders\DemoProgrammersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Phase 6d-5: public programmers for the Home carousel (MASTER_SPEC §13). */
class HomeProgrammersTest extends TestCase
{
    use RefreshDatabase;

    private function homeIds(): array
    {
        Cache::forget(PublicProgrammerController::HOME_CACHE_KEY);

        return collect($this->getJson('/api/public/programmers/home')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    }

    public function test_only_listed_programmers_appear_and_never_demo(): void
    {
        $this->assertSame([], $this->homeIds());

        $member = User::factory()->approved()->create();
        $committee = User::factory()->committee()->create();
        User::factory()->create(); // pending
        User::factory()->rejected()->create();
        User::factory()->suspended()->create();
        User::factory()->superAdmin()->create(['district' => null, 'experience_years' => null]); // no programmer profile
        User::factory()->approved()->create(['email' => 'demo3@'.DemoProgrammersSeeder::EMAIL_DOMAIN]);

        $this->assertSame(collect([$member->id, $committee->id])->sort()->values()->all(), $this->homeIds());
    }

    public function test_a_member_suspended_after_the_pick_drops_out_at_once(): void
    {
        $member = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();
        $this->assertCount(2, $this->getJson('/api/public/programmers/home')->json('data')); // ids now cached

        $member->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $this->assertSame([$other->id], collect($this->getJson('/api/public/programmers/home')->json('data'))->pluck('id')->all());
    }

    public function test_at_most_6_programmers(): void
    {
        User::factory()->count(10)->approved()->create();

        $this->assertCount(PublicProgrammerController::HOME_LIMIT, $this->homeIds());
        $this->assertSame(6, PublicProgrammerController::HOME_LIMIT);
    }

    public function test_card_fields_only_and_privacy_respected(): void
    {
        User::factory()->approved()->create([
            'workplace' => 'Secret Knit Ltd',
            'workplace_visibility' => 'members',
            'phone_visibility' => 'public',
            'email_visibility' => 'public',
        ]);

        $card = $this->getJson('/api/public/programmers/home')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'photo_url', 'designation', 'employment_status', 'district', 'experience_years', 'machines', 'bio_excerpt', 'portfolio_count']]])
            ->json('data.0');

        foreach (['workplace', 'phone', 'email', 'role', 'status', 'rejection_reason', 'approved_by', 'documents', 'bio', 'skills'] as $key) {
            $this->assertArrayNotHasKey($key, $card);
        }

        // "Members only" workplace: shown to a logged-in approved member, as in the directory.
        $this->actingAs(User::factory()->approved()->create())
            ->getJson('/api/public/programmers/home')
            ->assertJsonFragment(['workplace' => 'Secret Knit Ltd']);
    }
}
