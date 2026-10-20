<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\SiteInfo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Phase 11c: site contact info + About text (admin + super_admin), public site info. */
class SiteSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const EN = ['Accept' => 'application/json', 'Accept-Language' => 'en'];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    private function fullSettings(array $override = []): array
    {
        return array_merge([
            'phone_1' => '+880 1712-345678',
            'phone_2' => '01812345678',
            'email' => 'info@jpphaf.org',
            'whatsapp' => '8801912345678',
            'facebook_url' => 'https://facebook.com/jpphaf',
            'address_bn' => 'মিরপুর, ঢাকা',
            'address_en' => 'Mirpur, Dhaka',
            'office_hours_bn' => 'শনি–বৃহস্পতি, সকাল ১০টা–৬টা',
            'office_hours_en' => 'Sat–Thu, 10am–6pm',
            'about_bn' => "প্রথম অনুচ্ছেদ।\n\nদ্বিতীয় অনুচ্ছেদ।",
            'about_en' => 'About us in English.',
        ], $override);
    }

    public function test_only_admin_and_super_admin_can_use_the_admin_api(): void
    {
        $committee = User::factory()->committee()->create();
        $routes = [
            ['json', '/api/admin/site-settings', []],
            ['putJson', '/api/admin/site-settings', ['email' => 'x@y.org']],
        ];

        $call = fn (string $method, string $url, array $body) => $method === 'json'
            ? $this->getJson($url, self::EN)
            : $this->{$method}($url, $body, self::EN);
        foreach ($routes as [$method, $url, $body]) {
            auth()->forgetGuards();
            $call($method, $url, $body)->assertUnauthorized();
            foreach ([User::factory()->approved()->create(), $committee] as $user) {
                $this->actingAs($user);
                $call($method, $url, $body)->assertForbidden();
            }
        }

        $this->actingAs($this->admin)->getJson('/api/admin/site-settings', self::EN)->assertOk();
        $this->assertNull(SiteInfo::stored()['email']);
    }

    public function test_settings_are_saved_normalized_and_shown_publicly(): void
    {
        $this->getJson('/api/public/site-info')->assertOk()->assertJsonPath('data.email', null);

        $this->actingAs($this->admin)->putJson('/api/admin/site-settings', $this->fullSettings(), self::EN)
            ->assertOk()
            ->assertJsonPath('message', 'Site settings saved.')
            ->assertJsonPath('data.phone_1', '01712345678')
            ->assertJsonPath('data.whatsapp', '01912345678');

        auth()->forgetGuards();
        $data = $this->getJson('/api/public/site-info')->assertOk()->json('data');
        $this->assertSame(SiteInfo::FIELDS, array_keys($data));
        $this->assertSame('01712345678', $data['phone_1']);
        $this->assertSame("প্রথম অনুচ্ছেদ।\n\nদ্বিতীয় অনুচ্ছেদ।", $data['about_bn']);
        $this->assertSame('https://facebook.com/jpphaf', $data['facebook_url']);

        // Empty fields become null (the website hides them); the cache is cleared on save.
        $this->actingAs($this->admin)->putJson('/api/admin/site-settings', $this->fullSettings(['phone_2' => '  ', 'about_en' => '']), self::EN)->assertOk();
        auth()->forgetGuards();
        $this->getJson('/api/public/site-info')->assertJsonPath('data.phone_2', null)->assertJsonPath('data.about_en', null)
            ->assertJsonPath('data.phone_1', '01712345678');
    }

    public function test_validation(): void
    {
        $bad = [
            'phone_1' => '12345',
            'phone_2' => '01212345678',
            'whatsapp' => 'call me',
            'email' => 'not-an-email',
            'facebook_url' => 'http://facebook.com/jpphaf',
        ];
        foreach ($bad as $field => $value) {
            $this->actingAs($this->admin)->putJson('/api/admin/site-settings', [$field => $value], self::EN)
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        foreach (['javascript:alert(1)', 'facebook.com/jpphaf', 'ftp://x.org'] as $url) {
            $this->actingAs($this->admin)->putJson('/api/admin/site-settings', ['facebook_url' => $url], self::EN)
                ->assertUnprocessable()->assertJsonValidationErrors('facebook_url');
        }
        $this->actingAs($this->admin)->putJson('/api/admin/site-settings', ['about_en' => str_repeat('a', 5001)], self::EN)
            ->assertUnprocessable()->assertJsonValidationErrors('about_en');
        $this->actingAs($this->admin)->putJson('/api/admin/site-settings', ['about_en' => ['x']], self::EN)
            ->assertUnprocessable()->assertJsonValidationErrors('about_en');

        $this->assertSame(SiteInfo::defaults(), SiteInfo::stored());
        $this->assertSame(0, AuditLog::where('action', 'site_settings.updated')->count());
    }

    public function test_changes_are_audit_logged_by_field_name_only(): void
    {
        $this->actingAs($this->admin)->putJson('/api/admin/site-settings', $this->fullSettings(), self::EN)->assertOk();
        $this->actingAs($this->admin)->putJson('/api/admin/site-settings', $this->fullSettings(['email' => 'new@jpphaf.org']), self::EN)->assertOk();
        // Nothing changed → no new entry.
        $this->actingAs($this->admin)->putJson('/api/admin/site-settings', $this->fullSettings(['email' => 'new@jpphaf.org']), self::EN)->assertOk();

        $logs = AuditLog::where('action', 'site_settings.updated')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame(SiteInfo::FIELDS, $logs[0]->details['fields']);
        $this->assertSame(['fields' => ['email']], $logs[1]->details);
        $this->assertSame($this->admin->id, $logs[1]->actor_id);
        $this->assertStringNotContainsString('new@jpphaf.org', json_encode($logs->pluck('details')));
        $this->assertStringNotContainsString('01712345678', json_encode($logs->pluck('details')));
    }

    public function test_public_site_info_contains_only_public_fields(): void
    {
        $this->actingAs($this->admin)->putJson('/api/admin/site-settings', $this->fullSettings(), self::EN)->assertOk();
        auth()->forgetGuards();

        $json = $this->getJson('/api/public/site-info')->assertOk()->json();
        $this->assertSame(['data'], array_keys($json));
        $this->assertSame(SiteInfo::FIELDS, array_keys($json['data']));
        // No payment accounts, admin data, timestamps or other settings leak through.
        $text = json_encode($json);
        foreach (['bkash', 'updated', 'fund_start_month', 'payment_accounts', $this->admin->phone] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
    }
}
