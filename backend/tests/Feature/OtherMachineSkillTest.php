<?php

namespace Tests\Feature;

use App\Models\ProfileListItem;
use App\Models\User;
use Database\Seeders\ProfileListSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "Other" machine / skill typed by members, shown on public profiles, moved to the official list by an admin. */
class OtherMachineSkillTest extends TestCase
{
    use RefreshDatabase;

    private const EN = ['Accept' => 'application/json', 'Accept-Language' => 'en'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://localhost:3000');
        $this->seed(ProfileListSeeder::class);
    }

    private function registration(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Karim Mia', 'phone' => '01812345678',
            'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1',
            'district' => 'gazipur', 'workplace' => 'ABC Knit Ltd', 'experience_years' => 5,
            'machines' => ['stoll'], 'skills' => ['m1plus'],
        ], $overrides);
    }

    public function test_registration_accepts_other_text_with_or_without_list_picks(): void
    {
        $this->postJson('/api/register', $this->registration([
            'machines' => ['stoll'], 'other_machine' => '  Karl   Mayer ', 'skills' => [], 'other_skill' => 'Custom CAD',
        ]), self::EN)->assertCreated();

        $user = User::where('phone', '01812345678')->firstOrFail();
        $this->assertSame('Karl Mayer', $user->other_machine);
        $this->assertSame('Custom CAD', $user->other_skill);
        $this->assertSame([], $user->skills);
    }

    public function test_registration_needs_a_pick_or_other_and_limits_other_to_100_chars(): void
    {
        $this->postJson('/api/register', $this->registration(['machines' => [], 'skills' => []]), self::EN)
            ->assertUnprocessable()->assertJsonValidationErrors(['machines', 'skills']);
        // A blank "Other" text counts as nothing.
        $this->postJson('/api/register', $this->registration(['machines' => [], 'other_machine' => '   ']), self::EN)
            ->assertUnprocessable()->assertJsonValidationErrors(['machines']);
        $this->postJson('/api/register', $this->registration(['other_machine' => str_repeat('a', 101)]), self::EN)
            ->assertUnprocessable()->assertJsonValidationErrors(['other_machine']);
    }

    public function test_profile_edit_saves_and_clears_other_text_and_public_profile_shows_it_as_a_tag(): void
    {
        $user = User::factory()->approved()->create(['machines' => ['stoll'], 'skills' => ['m1plus']]);

        $this->actingAs($user)->putJson('/api/profile', [
            'machines' => ['stoll'], 'other_machine' => 'Karl Mayer', 'skills' => ['m1plus'], 'other_skill' => 'Custom CAD',
        ], self::EN)->assertOk()
            ->assertJsonPath('data.other_machine', 'Karl Mayer')
            ->assertJsonPath('data.other_skill', 'Custom CAD');

        $public = $this->getJson("/api/public/programmers/{$user->id}", self::EN)->assertOk();
        $this->assertContains('Karl Mayer', array_column($public->json('data.machines'), 'name_en'));
        $this->assertContains('Custom CAD', array_column($public->json('data.skills'), 'name_en'));

        $this->actingAs($user)->putJson('/api/profile', ['machines' => ['stoll'], 'other_machine' => ''], self::EN)->assertOk();
        $this->assertNull($user->refresh()->other_machine);

        $this->actingAs($user)->putJson('/api/profile', ['machines' => [], 'other_machine' => null], self::EN)
            ->assertUnprocessable()->assertJsonValidationErrors(['machines']);
    }

    public function test_admin_lists_other_entries_grouped_and_adds_one_to_the_official_list(): void
    {
        $admin = User::factory()->admin()->create();
        $a = User::factory()->approved()->create(['name' => 'A', 'machines' => ['stoll'], 'other_machine' => 'Karl Mayer']);
        $b = User::factory()->approved()->create(['name' => 'B', 'machines' => [], 'other_machine' => 'karl  mayer']);
        $c = User::factory()->approved()->create(['name' => 'C', 'other_skill' => 'Custom CAD']);

        $this->actingAs($admin)->getJson('/api/admin/profile-lists/others', self::EN)->assertOk()
            ->assertJsonCount(1, 'data.machines')
            ->assertJsonPath('data.machines.0.count', 2)
            ->assertJsonPath('data.machines.0.members', ['A', 'B'])
            ->assertJsonPath('data.skills.0.text', 'Custom CAD');

        $this->actingAs($admin)->postJson('/api/admin/profile-lists/others/add', ['type' => 'machine', 'text' => 'Karl Mayer'], self::EN)
            ->assertCreated()
            ->assertJsonPath('data.name_en', 'Karl Mayer')
            ->assertJsonCount(0, 'others.machines');

        $item = ProfileListItem::where('type', 'machine')->where('name_en', 'Karl Mayer')->firstOrFail();
        $this->assertTrue($item->is_active);
        $this->assertEqualsCanonicalizing(['stoll', $item->key], $a->refresh()->machines);
        $this->assertNull($a->other_machine);
        $this->assertSame([$item->key], $b->refresh()->machines);
        $this->assertNull($b->other_machine);
        $this->assertSame('Custom CAD', $c->refresh()->other_skill, 'other types are untouched');
        $this->assertDatabaseHas('audit_logs', ['action' => 'profile_list.other_added']);

        // The tag is now a normal list item on the public profile, shown once.
        $names = array_column($this->getJson("/api/public/programmers/{$a->id}", self::EN)->json('data.machines'), 'name_en');
        $this->assertSame(1, count(array_keys($names, 'Karl Mayer')));

        // Nothing left to add → validation error.
        $this->actingAs($admin)->postJson('/api/admin/profile-lists/others/add', ['type' => 'machine', 'text' => 'Karl Mayer'], self::EN)
            ->assertUnprocessable();
    }

    public function test_adding_an_other_that_matches_an_existing_item_reuses_it(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->approved()->create(['machines' => [], 'other_machine' => 'stoll']);
        $before = ProfileListItem::where('type', 'machine')->count();

        $this->actingAs($admin)->postJson('/api/admin/profile-lists/others/add', ['type' => 'machine', 'text' => 'stoll'], self::EN)->assertCreated();

        $this->assertSame($before, ProfileListItem::where('type', 'machine')->count());
        $this->assertSame(['stoll'], $user->refresh()->machines);
    }

    public function test_only_admins_can_see_or_add_other_entries(): void
    {
        $member = User::factory()->approved()->create();
        $super = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_APPROVED]);

        $this->actingAs($member)->getJson('/api/admin/profile-lists/others', self::EN)->assertForbidden();
        $this->actingAs($member)->postJson('/api/admin/profile-lists/others/add', ['type' => 'skill', 'text' => 'x'], self::EN)->assertForbidden();
        $this->actingAs($super)->getJson('/api/admin/profile-lists/others', self::EN)->assertOk();
    }
}
