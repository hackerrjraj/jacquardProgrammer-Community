<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicProgrammerController;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\DemoProgrammersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Phase 11b-2: featured programmers (admin + super_admin) shown first in the Home rows. */
class FeaturedProgrammersAdminTest extends TestCase
{
    use RefreshDatabase;

    private const EN = ['Accept' => 'application/json', 'Accept-Language' => 'en'];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // The admin has no programmer profile, so it never shows up on Home itself.
        $this->admin = User::factory()->admin()->create(['district' => null, 'experience_years' => null]);
    }

    private function feature(User $user): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/featured-programmers', ['user_id' => $user->id], self::EN)->assertCreated();
    }

    /** @return list<int> featured ids in order, as the admin list shows them */
    private function adminOrder(): array
    {
        return collect($this->actingAs($this->admin)->getJson('/api/admin/featured-programmers', self::EN)->assertOk()->json('data'))
            ->pluck('id')->all();
    }

    /** @return array<int, int> id => featured_order straight from the database */
    private function storedOrder(): array
    {
        return User::where('is_featured', true)->orderBy('featured_order')->pluck('featured_order', 'id')->all();
    }

    /** @return list<int> */
    private function homeIds(): array
    {
        Cache::forget(PublicProgrammerController::HOME_CACHE_KEY);
        auth()->forgetGuards();

        return collect($this->getJson('/api/public/programmers/home')->assertOk()->json('data'))->pluck('id')->all();
    }

    public function test_only_admin_and_super_admin_can_manage_featured(): void
    {
        $member = User::factory()->approved()->create();
        $routes = [
            ['GET', '/api/admin/featured-programmers'],
            ['GET', '/api/admin/featured-programmers/candidates'],
            ['POST', '/api/admin/featured-programmers'],
            ['PUT', '/api/admin/featured-programmers/order'],
            ['DELETE', "/api/admin/featured-programmers/{$member->id}"],
        ];

        foreach ($routes as [$method, $url]) {
            $this->json($method, $url, [], self::EN)->assertUnauthorized();
        }
        foreach ([User::factory()->approved()->create(), User::factory()->committee()->create()] as $user) {
            foreach ($routes as [$method, $url]) {
                $this->actingAs($user)->json($method, $url, ['user_id' => $member->id, 'ids' => [$member->id]], self::EN)->assertForbidden();
            }
        }
        $this->assertFalse($member->refresh()->is_featured);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson('/api/admin/featured-programmers', ['user_id' => $member->id], self::EN)
            ->assertCreated()
            ->assertJsonPath('data.0.id', $member->id)
            ->assertJsonPath('data.0.featured_order', 1);
    }

    public function test_only_eligible_programmers_can_be_added_and_only_once(): void
    {
        $notEligible = [
            User::factory()->create(), // pending
            User::factory()->rejected()->create(),
            User::factory()->suspended()->create(),
            User::factory()->approved()->create(['district' => null, 'experience_years' => null]), // no programmer profile
            User::factory()->approved()->create(['email' => 'demo9@'.DemoProgrammersSeeder::EMAIL_DOMAIN]),
        ];
        foreach ($notEligible as $user) {
            $this->actingAs($this->admin)->postJson('/api/admin/featured-programmers', ['user_id' => $user->id], self::EN)
                ->assertStatus(422)
                ->assertJsonValidationErrors('user_id')
                ->assertJsonPath('message', 'Only approved programmers who are shown on the Home page can be featured.');
        }
        $this->actingAs($this->admin)->postJson('/api/admin/featured-programmers', ['user_id' => 99999], self::EN)->assertStatus(422);
        $this->assertSame(0, User::where('is_featured', true)->count());

        $ok = User::factory()->approved()->create(['name' => 'Rahim Uddin', 'phone' => '01712345678']);
        $this->feature($ok);
        $this->actingAs($this->admin)->postJson('/api/admin/featured-programmers', ['user_id' => $ok->id], self::EN)
            ->assertStatus(422)->assertJsonPath('message', 'This programmer is already featured.');

        $this->assertSame(1, AuditLog::where('action', 'featured.added')->where('target_id', $ok->id)->count());
    }

    public function test_candidates_search_shows_only_eligible_not_yet_featured(): void
    {
        $rahim = User::factory()->approved()->create(['name' => 'Rahim Uddin', 'phone' => '01712345678']);
        $karim = User::factory()->approved()->create(['name' => 'Karim Ali', 'phone' => '01898765432']);
        User::factory()->suspended()->create(['name' => 'Rahim Suspended']);
        User::factory()->create(['name' => 'Rahim Pending']);
        $this->feature($karim);

        $ids = fn (string $search) => collect($this->actingAs($this->admin)
            ->getJson('/api/admin/featured-programmers/candidates?search='.urlencode($search), self::EN)
            ->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$rahim->id], $ids(''));          // Karim already featured, others not eligible
        $this->assertSame([$rahim->id], $ids('rahim'));
        $this->assertSame([$rahim->id], $ids('0171234'));
        $this->assertSame([$rahim->id], $ids('০১৭১২৩৪'));  // Bengali digits
        $this->assertSame([], $ids('Karim'));
    }

    public function test_featured_come_first_on_home_in_admin_order_without_duplicates(): void
    {
        $others = User::factory()->count(6)->approved()->create();
        $a = User::factory()->approved()->create();
        $b = User::factory()->approved()->create();
        $c = User::factory()->approved()->create();
        $this->feature($b);
        $this->feature($c);
        $this->feature($a);

        $home = $this->homeIds();
        $this->assertSame([$b->id, $c->id, $a->id], array_slice($home, 0, 3));
        $this->assertCount(6, $home);
        $this->assertSame($home, array_values(array_unique($home)));
        $this->assertSame($others->pluck('id')->sortDesc()->take(3)->values()->all(), array_slice($home, 3)); // newest fill the rest

        // Reorder → Home follows at once.
        $this->actingAs($this->admin)->putJson('/api/admin/featured-programmers/order', ['ids' => [$a->id, $b->id, $c->id]], self::EN)
            ->assertOk()->assertJsonPath('data.0.id', $a->id);
        $this->assertSame([$a->id, $b->id, $c->id], array_slice($this->homeIds(), 0, 3));
        $this->assertSame(1, AuditLog::where('action', 'featured.reordered')->count());
    }

    public function test_featured_are_always_on_home_even_with_more_than_6_programmers(): void
    {
        User::factory()->count(10)->approved()->create();
        $star = User::factory()->approved()->create();
        $this->feature($star);

        $home = $this->homeIds();
        $this->assertCount(PublicProgrammerController::HOME_LIMIT, $home);
        $this->assertSame($star->id, $home[0]);
    }

    public function test_hidden_or_suspended_featured_are_not_shown_but_keep_the_flag(): void
    {
        $a = User::factory()->approved()->create();
        $b = User::factory()->approved()->create();
        $this->feature($a);
        $this->feature($b);

        $a->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $this->assertSame([$b->id], $this->homeIds());
        $this->assertTrue($a->refresh()->is_featured);

        // Admin still sees them, marked as not shown, so they can be removed.
        $list = $this->actingAs($this->admin)->getJson('/api/admin/featured-programmers', self::EN)->json('data');
        $this->assertSame([[$a->id, false], [$b->id, true]], array_map(fn ($r) => [$r['id'], $r['shown_on_home']], $list));

        // No longer listed (profile gone) → also hidden.
        $b->forceFill(['experience_years' => null])->save();
        $this->assertSame([], $this->homeIds());

        // Re-approved → back in their place without any admin action.
        $a->forceFill(['status' => User::STATUS_APPROVED])->save();
        $this->assertSame([$a->id], $this->homeIds());
    }

    public function test_remove_and_reorder_keep_the_order_gap_free(): void
    {
        [$a, $b, $c, $d] = User::factory()->count(4)->approved()->create()->all();
        foreach ([$a, $b, $c, $d] as $u) {
            $this->feature($u);
        }
        $this->assertSame([$a->id => 1, $b->id => 2, $c->id => 3, $d->id => 4], $this->storedOrder());

        $this->actingAs($this->admin)->deleteJson("/api/admin/featured-programmers/{$b->id}", [], self::EN)
            ->assertOk()->assertJsonPath('message', 'Removed from featured programmers.');
        $this->assertSame([$a->id => 1, $c->id => 2, $d->id => 3], $this->storedOrder());
        $this->assertNull($b->refresh()->featured_order);
        $this->assertFalse($b->is_featured);
        $this->assertSame(['position' => 2], AuditLog::where('action', 'featured.removed')->first()->details);

        $this->actingAs($this->admin)->putJson('/api/admin/featured-programmers/order', ['ids' => [$d->id, $a->id, $c->id]], self::EN)->assertOk();
        $this->assertSame([$d->id => 1, $a->id => 2, $c->id => 3], $this->storedOrder());
        $this->assertSame([$d->id, $a->id, $c->id], $this->adminOrder());

        // Adding again goes to the end, still 1..n.
        $this->feature($b);
        $this->assertSame([$d->id => 1, $a->id => 2, $c->id => 3, $b->id => 4], $this->storedOrder());

        // Bad orders are refused and change nothing.
        foreach ([[$d->id, $a->id, $c->id], [$d->id, $a->id, $c->id, $b->id, 99999], [$d->id, $d->id, $a->id, $c->id], []] as $ids) {
            $this->actingAs($this->admin)->putJson('/api/admin/featured-programmers/order', ['ids' => $ids], self::EN)->assertStatus(422);
        }
        $this->assertSame([$d->id => 1, $a->id => 2, $c->id => 3, $b->id => 4], $this->storedOrder());

        // Removing someone not featured → 404.
        $plain = User::factory()->approved()->create();
        $this->actingAs($this->admin)->deleteJson("/api/admin/featured-programmers/{$plain->id}", [], self::EN)->assertNotFound();
    }

    public function test_public_json_never_shows_the_featured_flags(): void
    {
        $star = User::factory()->approved()->create();
        $this->feature($star);
        auth()->forgetGuards();

        $responses = [
            $this->getJson('/api/public/programmers/home'),
            $this->getJson('/api/public/programmers'),
            $this->getJson("/api/public/programmers/{$star->id}"),
        ];
        foreach ($responses as $response) {
            $response->assertOk();
            $this->assertStringNotContainsString('is_featured', $response->getContent());
            $this->assertStringNotContainsString('featured_order', $response->getContent());
        }

        // The member's own profile does not show it either.
        $own = $this->actingAs($star)->getJson('/api/profile')->getContent();
        $this->assertStringNotContainsString('featured', $own);
    }
}
