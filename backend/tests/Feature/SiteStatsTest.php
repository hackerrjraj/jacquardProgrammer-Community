<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_placeholder_stats(): void
    {
        // Plain users are not "approved members" until the approval status exists (Phase 3/5).
        User::factory()->create();

        $this->getJson('/api/site-stats')
            ->assertOk()
            ->assertExactJson(['data' => [
                'approved_members' => 0,
            ]]);
    }

    public function test_counts_only_approved_members(): void
    {
        User::factory()->approved()->count(2)->create();
        User::factory()->create();
        User::factory()->rejected()->create();
        User::factory()->suspended()->create();

        $this->getJson('/api/site-stats')->assertJsonPath('data.approved_members', 2);
    }

    public function test_districts_list_has_all_64(): void
    {
        $this->getJson('/api/districts')
            ->assertOk()
            ->assertJsonCount(64, 'data')
            ->assertJsonFragment(['key' => 'dhaka', 'name_bn' => 'ঢাকা', 'name_en' => 'Dhaka']);
    }
}
