<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Support\RegistrationTerms;
use Database\Seeders\ProfileListSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Registration terms & conditions: admin editor, public cached endpoint, acceptance on register. */
class RegistrationTermsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', 'http://localhost:3000');
        $this->seed(ProfileListSeeder::class);
    }

    private function registration(array $overrides = []): array
    {
        return array_merge([
            'name' => 'করিম মিয়া',
            'phone' => '01812345678',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
            'email' => 'karim@example.com',
            'district' => 'gazipur',
            'workplace' => 'ABC Knit Ltd',
            'experience_years' => 5,
            'machines' => ['stoll'],
            'skills' => ['m1plus'],
        ], $overrides);
    }

    private function terms(array $override = []): array
    {
        return array_merge([
            'enabled' => true,
            'heading_bn' => '',
            'heading_en' => '',
            'body_bn' => "১. নিয়ম এক।\n২. নিয়ম দুই।",
            'body_en' => "1. Rule one.\n2. Rule two.",
            'checkbox_bn' => '',
            'checkbox_en' => '',
        ], $override);
    }

    private function publishTerms(array $override = []): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->putJson('/api/admin/registration-terms', $this->terms($override))
            ->assertOk();
        $this->app['auth']->forgetGuards();
    }

    public function test_register_fails_without_acceptance_when_terms_are_on(): void
    {
        $this->publishTerms();

        $this->postJson('/api/register', $this->registration(), ['Accept-Language' => 'bn'])
            ->assertStatus(422)
            ->assertJsonPath('errors.terms_accepted.0', __('messages.terms_must_accept', [], 'bn'));
        $this->postJson('/api/register', $this->registration(['terms_accepted' => false]), ['Accept-Language' => 'en'])
            ->assertStatus(422)
            ->assertJsonPath('errors.terms_accepted.0', 'You must read and accept the terms to register.');
        $this->assertDatabaseCount('users', 1); // only the admin
    }

    public function test_register_with_acceptance_stores_version_and_time(): void
    {
        $this->publishTerms();
        $this->travelTo('2026-10-20 10:00:00');

        $this->postJson('/api/register', $this->registration(['terms_accepted' => true]))->assertCreated();

        $user = User::where('phone', '01812345678')->firstOrFail();
        $this->assertSame(1, $user->terms_version);
        $this->assertSame('2026-10-20 10:00:00', $user->terms_accepted_at->format('Y-m-d H:i:s'));
    }

    public function test_every_edit_raises_the_version_and_is_audit_logged(): void
    {
        $this->publishTerms();
        $this->publishTerms(['body_en' => 'New rules']);
        $this->publishTerms(['body_en' => 'New rules']); // no change → no new version

        $this->assertSame(2, RegistrationTerms::stored()['version']);
        $this->assertNotNull(RegistrationTerms::stored()['updated_at']);
        $this->assertSame(2, AuditLog::where('action', 'registration_terms.updated')->count());
    }

    public function test_admin_can_edit_and_member_cannot(): void
    {
        $member = User::factory()->approved()->create();
        $this->actingAs($member)->getJson('/api/admin/registration-terms')->assertForbidden();
        $this->actingAs($member)->putJson('/api/admin/registration-terms', $this->terms())->assertForbidden();
        $this->assertNull(Setting::valueOf(Setting::REGISTRATION_TERMS));

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson('/api/admin/registration-terms', $this->terms())
            ->assertOk()
            ->assertJsonPath('data.heading_bn', 'আমাদের নিয়মাবলী আগে পড়ুন')
            ->assertJsonPath('data.version', 1);
    }

    public function test_public_endpoint_is_cached_and_cleared_on_edit(): void
    {
        $this->publishTerms();
        $this->getJson('/api/registration-terms')
            ->assertOk()
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.body_en', "1. Rule one.\n2. Rule two.");
        $this->assertTrue(Cache::has(RegistrationTerms::CACHE_KEY));

        $this->publishTerms(['body_en' => 'Changed']);
        $this->getJson('/api/registration-terms')->assertJsonPath('data.body_en', 'Changed');
    }

    public function test_disabled_or_empty_terms_need_no_acceptance(): void
    {
        // Default (nothing written yet): not active.
        $this->getJson('/api/registration-terms')->assertJsonPath('data.active', false);
        $this->postJson('/api/register', $this->registration())->assertCreated();
        $this->assertNull(User::where('phone', '01812345678')->value('terms_version'));

        // Written but switched off.
        $this->publishTerms(['enabled' => false]);
        $this->getJson('/api/registration-terms')->assertJsonPath('data.active', false);
        $this->postJson('/api/register', $this->registration(['phone' => '01912345678', 'email' => 'b@example.com']))
            ->assertCreated();
        $this->assertNull(User::where('phone', '01912345678')->value('terms_accepted_at'));
    }
}
