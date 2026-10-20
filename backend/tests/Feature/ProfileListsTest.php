<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ProfileListItem;
use App\Models\User;
use Database\Seeders\ProfileListSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Phase 11d-1: admin-editable Machine + Skill lists. */
class ProfileListsTest extends TestCase
{
    use RefreshDatabase;

    private const EN = ['Accept' => 'application/json', 'Accept-Language' => 'en'];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', 'http://localhost:3000');
        $this->seed(ProfileListSeeder::class);
        $this->admin = User::factory()->admin()->create(['machines' => null, 'skills' => null]);
    }

    private function item(string $key, string $type = ProfileListItem::TYPE_MACHINE): ProfileListItem
    {
        return ProfileListItem::where('type', $type)->where('key', $key)->firstOrFail();
    }

    private function registration(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Karim Mia',
            'phone' => '01812345678',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
            'district' => 'gazipur',
            'workplace' => 'ABC Knit Ltd',
            'experience_years' => 5,
            'machines' => ['stoll'],
            'skills' => ['m1plus'],
        ], $overrides);
    }

    // ---------- Permissions ----------

    public function test_only_admin_and_super_admin_can_manage_lists(): void
    {
        $item = $this->item('cixing');
        $routes = [
            ['GET', '/api/admin/profile-lists'],
            ['POST', '/api/admin/profile-lists'],
            ['PUT', '/api/admin/profile-lists/order'],
            ['PATCH', "/api/admin/profile-lists/{$item->id}"],
            ['POST', "/api/admin/profile-lists/{$item->id}/active"],
            ['DELETE', "/api/admin/profile-lists/{$item->id}"],
        ];

        foreach ($routes as [$method, $url]) {
            $this->json($method, $url, [], self::EN)->assertUnauthorized();
        }
        // Programmer (member) and committee: 403 everywhere.
        foreach ([User::factory()->approved()->create(), User::factory()->committee()->create()] as $user) {
            foreach ($routes as [$method, $url]) {
                $this->actingAs($user)->json($method, $url, [], self::EN)->assertForbidden();
            }
        }

        $this->actingAs($this->admin)->getJson('/api/admin/profile-lists', self::EN)->assertOk()
            ->assertJsonCount(4, 'data.machines')
            ->assertJsonCount(5, 'data.skills')
            ->assertJsonPath('data.machines.0.key', 'stoll');
        $this->assertDatabaseHas('profile_list_items', ['id' => $item->id]);
    }

    public function test_normal_admin_cannot_delete_but_super_admin_can_delete_an_unused_item(): void
    {
        $item = $this->item('cixing');

        $this->actingAs($this->admin)->deleteJson("/api/admin/profile-lists/{$item->id}", [], self::EN)->assertForbidden();
        $this->assertDatabaseHas('profile_list_items', ['id' => $item->id]);

        $this->actingAs(User::factory()->superAdmin()->create(['machines' => null, 'skills' => null]))
            ->deleteJson("/api/admin/profile-lists/{$item->id}", [], self::EN)
            ->assertOk()
            ->assertJsonPath('message', __('messages.list_item_deleted', [], 'en'));
        $this->assertDatabaseMissing('profile_list_items', ['id' => $item->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'profile_list.deleted', 'target_id' => $item->id]);
    }

    public function test_an_item_in_use_cannot_be_deleted_even_by_super_admin(): void
    {
        User::factory()->create(['machines' => ['cixing']]); // a pending account counts too
        $item = $this->item('cixing');
        $super = User::factory()->superAdmin()->create(['machines' => null, 'skills' => null]);

        $this->actingAs($super)->getJson('/api/admin/profile-lists', self::EN)
            ->assertJsonPath('data.machines.3.key', 'cixing')
            ->assertJsonPath('data.machines.3.used_by', 1);

        $this->actingAs($super)->deleteJson("/api/admin/profile-lists/{$item->id}", [], self::EN)
            ->assertUnprocessable()
            ->assertJsonPath('errors.item.0', __('messages.list_item_in_use', ['count' => 1], 'en'));
        $this->assertDatabaseHas('profile_list_items', ['id' => $item->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'profile_list.deleted']);
    }

    // ---------- Create / rename / hide / order ----------

    public function test_admin_adds_renames_hides_and_reorders_items_and_every_change_is_logged(): void
    {
        $this->actingAs($this->admin);

        $created = $this->postJson('/api/admin/profile-lists', [
            'type' => 'machine', 'name_bn' => '  লং  শিং ', 'name_en' => ' Long Xing ',
        ], self::EN)->assertCreated()
            ->assertJsonPath('data.key', 'long-xing')
            ->assertJsonPath('data.name_bn', 'লং শিং')
            ->assertJsonPath('data.name_en', 'Long Xing')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.sort_order', 4)
            ->json('data');

        // Same name again (any case / spacing, either language) is refused.
        $this->postJson('/api/admin/profile-lists', ['type' => 'machine', 'name_bn' => 'নতুন', 'name_en' => 'long  XING'], self::EN)
            ->assertUnprocessable()->assertJsonValidationErrors(['name_en']);
        // …but the same name in the other list is fine.
        $this->postJson('/api/admin/profile-lists', ['type' => 'skill', 'name_bn' => 'লং শিং', 'name_en' => 'Long Xing'], self::EN)->assertCreated();
        $this->postJson('/api/admin/profile-lists', ['type' => 'other', 'name_bn' => 'x', 'name_en' => 'x'], self::EN)
            ->assertUnprocessable()->assertJsonValidationErrors(['type']);
        $this->postJson('/api/admin/profile-lists', ['type' => 'machine', 'name_bn' => '   ', 'name_en' => 'Y'], self::EN)
            ->assertUnprocessable()->assertJsonValidationErrors(['name_bn']);

        $this->patchJson("/api/admin/profile-lists/{$created['id']}", ['name_en' => 'LONGXING'], self::EN)
            ->assertOk()->assertJsonPath('data.name_en', 'LONGXING')->assertJsonPath('data.key', 'long-xing');

        $this->postJson("/api/admin/profile-lists/{$created['id']}/active", ['is_active' => false], self::EN)
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->postJson("/api/admin/profile-lists/{$created['id']}/active", ['is_active' => false], self::EN)->assertOk(); // no 2nd log
        $this->postJson("/api/admin/profile-lists/{$created['id']}/active", ['is_active' => true], self::EN)
            ->assertOk()->assertJsonPath('data.is_active', true);

        $ids = ProfileListItem::where('type', 'machine')->orderBy('sort_order')->pluck('id')->all();
        $new = array_reverse($ids);
        $this->putJson('/api/admin/profile-lists/order', ['type' => 'machine', 'ids' => $new], self::EN)
            ->assertOk()->assertJsonPath('data.machines.0.key', 'long-xing');
        $this->putJson('/api/admin/profile-lists/order', ['type' => 'machine', 'ids' => array_slice($new, 1)], self::EN)
            ->assertUnprocessable()->assertJsonValidationErrors(['ids']);

        $this->assertSame(
            ['profile_list.created', 'profile_list.created', 'profile_list.updated', 'profile_list.hidden', 'profile_list.shown', 'profile_list.reordered'],
            AuditLog::orderBy('id')->pluck('action')->all(),
        );
        $this->assertSame(['name_en' => ['from' => 'Long Xing', 'to' => 'LONGXING']], AuditLog::firstWhere('action', 'profile_list.updated')->details['changes']);
    }

    // ---------- Hidden items ----------

    public function test_hidden_item_is_not_offered_but_stays_on_existing_profiles(): void
    {
        $member = User::factory()->approved()->create(['machines' => ['stoll', 'cixing'], 'skills' => ['m1plus']]);
        $this->actingAs($this->admin)->postJson('/api/admin/profile-lists/'.$this->item('cixing')->id.'/active', ['is_active' => false], self::EN)->assertOk();

        // Not offered: profile options, directory filter, registration.
        $options = $this->getJson('/api/profile-options')->assertOk()->json('data.machines');
        $this->assertSame(['stoll', 'shima-seiki', 'steiger'], array_column($options, 'key'));
        $this->assertNotContains('cixing', array_column($this->getJson('/api/public/programmers/filters')->json('data.machines'), 'key'));
        auth()->forgetGuards();
        $this->postJson('/api/register', $this->registration(['machines' => ['cixing']]), self::EN)
            ->assertUnprocessable()->assertJsonValidationErrors(['machines.0']);

        // Kept + still shown on the member's profile and the public profile; the directory filter still finds them.
        $this->actingAs($member)->getJson('/api/profile')->assertOk()
            ->assertJsonPath('data.machines.1', ['key' => 'cixing', 'name_bn' => 'Cixing', 'name_en' => 'Cixing']);
        $this->getJson("/api/public/programmers/{$member->id}")->assertOk()->assertJsonPath('data.machines.1.key', 'cixing');
        $this->assertSame([$member->id], array_column($this->getJson('/api/public/programmers?machine=cixing')->json('data'), 'id'));

        // Saving the profile with the hidden item kept works; other changes go through.
        $this->actingAs($member)->putJson('/api/profile', ['machines' => ['cixing', 'shima-seiki']], self::EN)->assertOk()
            ->assertJsonPath('data.machines.0.key', 'cixing');
        $this->assertSame(['cixing', 'shima-seiki'], $member->refresh()->machines);

        // Another member cannot newly pick the hidden item.
        $other = User::factory()->approved()->create(['machines' => ['stoll']]);
        $this->actingAs($other)->putJson('/api/profile', ['machines' => ['stoll', 'cixing']], self::EN)
            ->assertUnprocessable()->assertJsonValidationErrors(['machines.1']);
        $this->assertSame(['stoll'], $other->refresh()->machines);
    }

    public function test_registration_and_profile_accept_only_list_keys(): void
    {
        $this->postJson('/api/register', $this->registration(['machines' => ['Made Up Machine'], 'skills' => ['m1plus', 'sds-one']]), self::EN)
            ->assertUnprocessable()->assertJsonValidationErrors(['machines.0'])->assertJsonMissingValidationErrors(['skills.0', 'skills.1']);

        $this->postJson('/api/register', $this->registration(), self::EN)->assertCreated()
            ->assertJsonPath('data.machines', [['key' => 'stoll', 'name_bn' => 'STOLL', 'name_en' => 'STOLL']]);
        $this->assertSame(['stoll'], User::firstWhere('phone', '01812345678')->machines);
    }

    // ---------- Rename ----------

    public function test_rename_shows_on_profiles_everywhere(): void
    {
        $member = User::factory()->approved()->create(['machines' => ['shima-seiki'], 'skills' => ['jacquard']]);

        $this->actingAs($this->admin)->patchJson('/api/admin/profile-lists/'.$this->item('shima-seiki')->id, ['name_en' => 'Shima Seiki', 'name_bn' => 'শিমা সেইকি'], self::EN)->assertOk();
        $this->patchJson('/api/admin/profile-lists/'.$this->item('jacquard', 'skill')->id, ['name_en' => 'Jacquard design'], self::EN)->assertOk();

        $shima = ['key' => 'shima-seiki', 'name_bn' => 'শিমা সেইকি', 'name_en' => 'Shima Seiki'];
        $this->assertSame(['shima-seiki'], $member->refresh()->machines); // stored key unchanged
        $this->actingAs($member)->getJson('/api/profile')->assertOk()
            ->assertJsonPath('data.machines', [$shima])
            ->assertJsonPath('data.skills.0.name_en', 'Jacquard design');
        $this->getJson("/api/public/programmers/{$member->id}")->assertJsonPath('data.machines', [$shima])->assertJsonPath('data.skills.0.name_en', 'Jacquard design');
        $this->getJson('/api/public/programmers')->assertJsonPath('data.0.machines', [$shima]);
        $this->actingAs($this->admin)->getJson('/api/admin/members?status=approved')->assertOk();
        $this->assertContains($shima, $this->getJson('/api/public/programmers/filters')->json('data.machines'));
    }

    // ---------- Migration ----------

    public function test_migration_maps_every_saved_text_to_a_key_and_rolls_back_to_the_exact_old_data(): void
    {
        $migration = require database_path('migrations/2026_10_16_800000_create_profile_list_items_table.php');
        $migration->down(); // back to the free-text world (empty lists here)

        $raw = [
            ['["STOLL", "SHIMA SEIKI"]', '["M1PLUS", "Jacquard"]'],
            ['["Stoll", "  shima   seiki ", "STOLL"]', '["m1plus", ""]'],
            ['["Cixing"]', null],
            [null, null],
            ['["স্টল মেশিন"]', '[12]'],
        ];
        $ids = [];
        foreach ($raw as [$machines, $skills]) {
            $id = User::factory()->approved()->create()->id;
            DB::table('users')->where('id', $id)->update(['machines' => $machines, 'skills' => $skills]);
            $ids[] = $id;
        }
        $snapshot = fn () => DB::table('users')->whereIn('id', $ids)->orderBy('id')->get(['id', 'machines', 'skills'])->map(fn ($u) => (array) $u)->all();
        $before = $snapshot();

        $migration->up();

        // One item per distinct name (case / spacing ignored); most used first (tie → by name); the most used spelling is the name.
        $machines = ProfileListItem::where('type', 'machine')->orderBy('sort_order')->get();
        $this->assertSame(['shima-seiki', 'stoll', 'cixing', 'stl-mesin'], $machines->pluck('key')->all());
        $this->assertSame(['SHIMA SEIKI', 'STOLL', 'Cixing', 'স্টল মেশিন'], $machines->pluck('name_en')->all());
        $this->assertSame('স্টল মেশিন', $machines[3]->name_bn);

        $users = User::whereIn('id', $ids)->orderBy('id')->get();
        $this->assertSame(['stoll', 'shima-seiki'], $users[0]->machines);
        $this->assertSame(['m1plus', 'jacquard'], $users[0]->skills);
        $this->assertSame(['stoll', 'shima-seiki'], $users[1]->machines); // duplicates in one profile → one key
        $this->assertSame(['m1plus', ''], $users[1]->skills); // empty text cannot be mapped → kept as it was
        $this->assertNull($users[2]->skills);
        $this->assertNull($users[3]->machines);
        $this->assertSame([12], $users[4]->skills); // not text → kept, reported in the log
        $this->assertSame(['stl-mesin'], $users[4]->machines);

        // Shown with the same names as before.
        $this->actingAs($users[0])->getJson('/api/profile')->assertOk()
            ->assertJsonPath('data.machines.0.name_en', 'STOLL')
            ->assertJsonPath('data.skills.1.name_en', 'Jacquard');
        $this->assertSame(count($ids) - 1, DB::table('profile_list_migration_backups')->count());

        // Rollback: every account gets its exact old values back.
        $migration->down();
        $this->assertSame($before, $snapshot());

        $migration->up(); // leave the schema as the other tests expect
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('profile_list_items'));
    }

    public function test_rollback_keeps_later_changes_as_names(): void
    {
        $migration = require database_path('migrations/2026_10_16_800000_create_profile_list_items_table.php');
        $migration->down();
        $member = User::factory()->approved()->create();
        DB::table('users')->where('id', $member->id)->update(['machines' => '["STOLL"]', 'skills' => '["M1PLUS"]']);
        $migration->up();

        // Edited after the migration → on rollback the keys become the current English names.
        DB::table('users')->where('id', $member->id)->update(['machines' => '["stoll", "unknown-key"]']);
        ProfileListItem::where('key', 'stoll')->update(['name_en' => 'Stoll Germany']);
        $migration->down();

        $member->refresh();
        $this->assertSame(['Stoll Germany', 'unknown-key'], $member->machines);
        $this->assertSame(['M1PLUS'], $member->skills); // unchanged since → exact old text
        $migration->up();
    }
}
