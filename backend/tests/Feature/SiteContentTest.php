<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SiteContent as SiteContentRow;
use App\Models\User;
use App\Support\SiteContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Phase 14a: Site Content — admin-editable public-page text (bn / en), public API, cache, audit, defaults. */
class SiteContentTest extends TestCase
{
    use RefreshDatabase;

    private const EN = ['Accept' => 'application/json', 'Accept-Language' => 'en'];

    private function save(User $user, array $items)
    {
        return $this->actingAs($user)->putJson('/api/admin/site-content', ['items' => $items], self::EN);
    }

    public function test_only_admin_and_super_admin_can_use_the_admin_api(): void
    {
        $calls = [
            ['GET', '/api/admin/site-content', []],
            ['PUT', '/api/admin/site-content', ['items' => ['home.cta.title' => ['bn' => 'x', 'en' => 'y']]]],
            ['POST', '/api/admin/site-content/reset', ['key' => 'home.cta.title']],
        ];
        foreach ($calls as [$method, $url, $body]) {
            auth()->forgetGuards();
            $this->json($method, $url, $body, self::EN)->assertUnauthorized();
            foreach ([User::factory()->approved()->create(), User::factory()->committee()->create()] as $user) {
                $this->actingAs($user)->json($method, $url, $body, self::EN)->assertForbidden();
            }
            foreach ([User::factory()->admin()->create(), User::factory()->superAdmin()->create()] as $user) {
                $this->actingAs($user)->json($method, $url, $body, self::EN)->assertSuccessful();
            }
        }
    }

    public function test_forbidden_roles_change_nothing(): void
    {
        $this->save(User::factory()->approved()->create(), ['home.cta.title' => ['bn' => 'x', 'en' => 'y']])->assertForbidden();
        $this->save(User::factory()->committee()->create(), ['home.cta.title' => ['bn' => 'x', 'en' => 'y']])->assertForbidden();
        $this->assertSame(0, SiteContentRow::count());
        $this->assertSame(0, AuditLog::where('action', 'like', 'site_content.%')->count());
    }

    public function test_admin_list_has_defaults_from_the_translation_files(): void
    {
        $rows = collect($this->actingAs(User::factory()->admin()->create())->getJson('/api/admin/site-content', self::EN)->assertOk()->json('data'));
        $this->assertCount(count(SiteContent::registry()), $rows);
        $cta = $rows->firstWhere('key', 'home.cta.button');
        $this->assertSame('home', $cta['group']);
        $this->assertSame('Join JPPHAF', $cta['default_en']);
        $this->assertNull($cta['value_en']);
        $this->assertEqualsCanonicalizing(['home', 'about', 'contact', 'join', 'footer', 'seo'], $rows->pluck('group')->unique()->values()->all());
    }

    public function test_defaults_match_the_translation_files_and_fit_their_limit(): void
    {
        $dicts = [];
        foreach (['bn', 'en'] as $lang) {
            $path = base_path("../frontend/src/i18n/{$lang}.json");
            $this->assertFileExists($path);
            $dicts[$lang] = json_decode(file_get_contents($path), true);
        }
        foreach (SiteContent::registry() as $key => $def) {
            foreach (['bn', 'en'] as $lang) {
                $this->assertNotSame('', $def[$lang], "$key $lang default is empty");
                $this->assertLessThanOrEqual($def['max'], mb_strlen($def[$lang]), "$key $lang default is longer than its limit");
                if (! str_starts_with($key, 'seo.')) {
                    $this->assertSame($def[$lang], data_get($dicts[$lang], $key), "$key $lang default differs from the translation file");
                }
            }
        }
    }

    public function test_save_public_read_and_cache_clear(): void
    {
        $this->getJson('/api/public/site-content')->assertOk()->assertExactJson(['data' => []]);
        $this->assertTrue(Cache::has(SiteContent::CACHE_KEY));

        $admin = User::factory()->admin()->create();
        $this->save($admin, ['home.cta.title' => ['bn' => 'নতুন শিরোনাম', 'en' => 'New headline'], 'seo.home.title' => ['bn' => '', 'en' => 'My SEO title']])
            ->assertOk()->assertJsonPath('message', 'Site content saved.');
        $this->assertFalse(Cache::has(SiteContent::CACHE_KEY), 'cache must be cleared on save');

        auth()->forgetGuards();
        $this->getJson('/api/public/site-content')->assertOk()
            ->assertExactJson(['data' => [
                'home.cta.title' => ['bn' => 'নতুন শিরোনাম', 'en' => 'New headline'],
                'seo.home.title' => ['en' => 'My SEO title'],
            ]]);
        $this->assertTrue(Cache::has(SiteContent::CACHE_KEY));
        $this->assertSame($admin->id, SiteContentRow::where('key', 'home.cta.title')->value('updated_by'));
    }

    public function test_empty_or_default_value_falls_back_and_reset_clears(): void
    {
        $admin = User::factory()->admin()->create();
        $default = SiteContent::registry()['home.cta.button'];
        $this->save($admin, ['home.cta.button' => ['bn' => 'কাস্টম', 'en' => 'Custom']])->assertOk();

        // Empty bn → default; en equal to the default → stored as default too.
        $this->save($admin, ['home.cta.button' => ['bn' => '  ', 'en' => $default['en']]])->assertOk();
        $this->assertSame(0, SiteContentRow::count());
        $this->getJson('/api/public/site-content')->assertExactJson(['data' => []]);

        // Reset button.
        $this->save($admin, ['home.cta.button' => ['bn' => 'কাস্টম', 'en' => 'Custom']])->assertOk();
        $this->actingAs($admin)->postJson('/api/admin/site-content/reset', ['key' => 'home.cta.button'], self::EN)
            ->assertOk()->assertJsonPath('message', 'Content reset to default.');
        $this->assertSame(0, SiteContentRow::count());
        $this->getJson('/api/public/site-content')->assertExactJson(['data' => []]);
    }

    public function test_every_change_is_audit_logged_and_nothing_changed_writes_nothing(): void
    {
        $admin = User::factory()->admin()->create();
        $default = SiteContent::registry()['home.cta.title']['en'];
        $this->save($admin, ['home.cta.title' => ['bn' => '', 'en' => 'Fresh']])->assertOk();

        $log = AuditLog::where('action', 'site_content.updated')->sole();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame('home.cta.title', $log->details['key']);
        $this->assertSame(['from' => $default, 'to' => 'Fresh'], $log->details['en']);
        $this->assertArrayNotHasKey('bn', $log->details);

        $this->save($admin, ['home.cta.title' => ['bn' => '', 'en' => 'Fresh']])->assertOk()->assertJsonPath('message', 'Nothing was changed.');
        $this->assertSame(1, AuditLog::where('action', 'site_content.updated')->count());

        $this->actingAs($admin)->postJson('/api/admin/site-content/reset', ['key' => 'home.cta.title'], self::EN)->assertOk();
        $reset = AuditLog::where('action', 'site_content.reset')->sole();
        $this->assertSame(['from' => 'Fresh', 'to' => $default], $reset->details['en']);
    }

    public function test_validation_plain_text_limits_and_unknown_keys(): void
    {
        $admin = User::factory()->admin()->create();
        $max = SiteContent::registry()['home.cta.button']['max'];

        $this->save($admin, ['home.cta.button' => ['bn' => '<b>bold</b>', 'en' => 'ok']])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.home.cta.button.bn']);
        $this->save($admin, ['home.cta.button' => ['bn' => 'ok', 'en' => str_repeat('a', $max + 1)]])->assertUnprocessable()
            ->assertJsonPath('errors', fn ($e) => str_contains(array_values($e)[0][0], (string) $max));
        $this->save($admin, ['not.a.key' => ['bn' => 'x', 'en' => 'y']])->assertUnprocessable();
        $this->actingAs($admin)->putJson('/api/admin/site-content', [], self::EN)->assertUnprocessable();
        $this->actingAs($admin)->postJson('/api/admin/site-content/reset', ['key' => 'nope'], self::EN)->assertUnprocessable();

        // A bad field saves nothing, even when another field in the same request is fine.
        $this->save($admin, ['home.cta.title' => ['bn' => 'fine', 'en' => 'fine'], 'home.cta.button' => ['bn' => '<x>', 'en' => '']])->assertUnprocessable();
        $this->assertSame(0, SiteContentRow::count());
        $this->assertSame(0, AuditLog::where('action', 'like', 'site_content.%')->count());
    }

    public function test_single_line_fields_lose_line_breaks_and_long_texts_keep_them(): void
    {
        $admin = User::factory()->admin()->create();
        $this->save($admin, [
            'home.cta.button' => ['bn' => "এক\nদুই", 'en' => 'One   two'],
            'home.cta.text' => ['bn' => "প্রথম\n\nদ্বিতীয়", 'en' => ''],
        ])->assertOk();
        $this->assertSame('এক দুই', SiteContentRow::where('key', 'home.cta.button')->value('value_bn'));
        $this->assertSame('One two', SiteContentRow::where('key', 'home.cta.button')->value('value_en'));
        $this->assertSame("প্রথম\n\nদ্বিতীয়", SiteContentRow::where('key', 'home.cta.text')->value('value_bn'));
    }
}
