<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\ProfileListSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Behave like the Next.js frontend so Sanctum starts a cookie session.
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
            'machines' => ['stoll', 'shima-seiki'],
            'skills' => ['m1plus'],
        ], $overrides);
    }

    private function login(string $phone, string $password, string $lang = 'bn')
    {
        return $this->postJson('/api/login', ['phone' => $phone, 'password' => $password], ['Accept-Language' => $lang]);
    }

    // ---------- Register ----------

    public function test_register_creates_pending_member(): void
    {
        $this->postJson('/api/register', $this->registration(), ['Accept-Language' => 'en'])
            ->assertCreated()
            ->assertJsonPath('message', __('messages.registered', [], 'en'))
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.role', 'member')
            ->assertJsonPath('data.machines', [['key' => 'stoll', 'name_bn' => 'STOLL', 'name_en' => 'STOLL'], ['key' => 'shima-seiki', 'name_bn' => 'SHIMA SEIKI', 'name_en' => 'SHIMA SEIKI']])
            ->assertJsonMissingPath('data.password');

        $user = User::firstWhere('phone', '01812345678');
        $this->assertSame('pending', $user->status);
        $this->assertSame('member', $user->role);
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('secret-pass-1', $user->password));
        $this->assertGuest('web'); // pending members are not logged in
    }

    public function test_register_normalises_plus_880_phone_and_cannot_set_role_or_status(): void
    {
        $this->postJson('/api/register', $this->registration([
            'phone' => '+880 1812-345678',
            'email' => '',
            'role' => 'super_admin',
            'status' => 'approved',
        ]))->assertCreated();

        $user = User::sole();
        $this->assertSame('01812345678', $user->phone);
        $this->assertNull($user->email);
        $this->assertSame('member', $user->role);
        $this->assertSame('pending', $user->status);
    }

    public function test_register_validation(): void
    {
        $this->postJson('/api/register', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'phone', 'password', 'district', 'workplace', 'experience_years', 'machines', 'skills'])
            ->assertJsonMissingValidationErrors(['email']);

        $this->postJson('/api/register', $this->registration([
            'phone' => '0181234567',
            'password_confirmation' => 'different-1',
            'district' => 'atlantis',
            'experience_years' => 'many',
            'machines' => [],
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['phone', 'password', 'district', 'experience_years', 'machines']);

        $this->postJson('/api/register', $this->registration(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertJsonValidationErrors(['password']);

        $this->assertSame(0, User::count());
    }

    public function test_duplicate_phone_is_rejected_in_bn_and_en(): void
    {
        User::factory()->create(['phone' => '01812345678']);

        $this->postJson('/api/register', $this->registration(), ['Accept-Language' => 'bn'])
            ->assertJsonPath('errors.phone.0', 'এই মোবাইল নম্বর দিয়ে আগেই নিবন্ধন করা হয়েছে।');

        $this->postJson('/api/register', $this->registration(['phone' => '+8801812345678']), ['Accept-Language' => 'en'])
            ->assertJsonPath('errors.phone.0', 'This mobile number is already registered.');
    }

    // ---------- Login ----------

    public function test_approved_member_can_log_in_and_see_me(): void
    {
        $user = User::factory()->approved()->create(['phone' => '01712345678', 'password' => 'secret-pass-1']);

        $this->login('01712345678', 'secret-pass-1', 'en')
            ->assertOk()
            ->assertJsonPath('message', __('messages.logged_in', [], 'en'))
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.must_change_password', false);

        $this->assertAuthenticatedAs($user, 'web');

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.phone', '01712345678')
            ->assertJsonPath('data.status', 'approved');
    }

    public function test_login_accepts_plus_880_format(): void
    {
        User::factory()->approved()->create(['phone' => '01712345678', 'password' => 'secret-pass-1']);

        $this->login('+8801712345678', 'secret-pass-1')->assertOk();
    }

    public function test_pending_member_gets_clear_message_in_bn_and_en(): void
    {
        User::factory()->create(['phone' => '01712345678', 'password' => 'secret-pass-1']);

        $this->login('01712345678', 'secret-pass-1', 'bn')
            ->assertForbidden()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('message', 'আপনার নিবন্ধন এখনও অনুমোদনের অপেক্ষায় আছে। অ্যাডমিন অনুমোদন দিলে আপনি লগইন করতে পারবেন।');

        $this->login('01712345678', 'secret-pass-1', 'en')
            ->assertForbidden()
            ->assertJsonPath('message', __('messages.account_pending', [], 'en'));

        $this->assertGuest('web');
    }

    public function test_rejected_member_sees_reason(): void
    {
        User::factory()->rejected('ছবি অস্পষ্ট')->create(['phone' => '01712345678', 'password' => 'secret-pass-1']);

        $this->login('01712345678', 'secret-pass-1', 'bn')
            ->assertForbidden()
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('rejection_reason', 'ছবি অস্পষ্ট')
            ->assertJsonPath('message', 'দুঃখিত, আপনার নিবন্ধন বাতিল করা হয়েছে। কারণ: ছবি অস্পষ্ট');

        $this->login('01712345678', 'secret-pass-1', 'en')
            ->assertJsonPath('message', 'Sorry, your registration has been rejected. Reason: ছবি অস্পষ্ট');

        $this->assertGuest('web');
    }

    public function test_suspended_member_cannot_log_in(): void
    {
        User::factory()->suspended()->create(['phone' => '01712345678', 'password' => 'secret-pass-1']);

        $this->login('01712345678', 'secret-pass-1', 'en')
            ->assertForbidden()
            ->assertJsonPath('status', 'suspended')
            ->assertJsonPath('message', __('messages.account_suspended', [], 'en'));

        $this->assertGuest('web');
    }

    public function test_wrong_password_or_unknown_phone_gives_same_error_and_hides_status(): void
    {
        User::factory()->create(['phone' => '01712345678', 'password' => 'secret-pass-1']); // pending

        $this->login('01712345678', 'wrong-password', 'bn')
            ->assertUnprocessable()
            ->assertJsonPath('errors.phone.0', 'মোবাইল নম্বর বা পাসওয়ার্ড সঠিক নয়।')
            ->assertJsonMissingPath('status');

        $this->login('01999999999', 'secret-pass-1', 'en')
            ->assertUnprocessable()
            ->assertJsonPath('errors.phone.0', 'The mobile number or password is incorrect.');

        $this->assertGuest('web');
    }

    public function test_login_is_rate_limited_per_phone_and_ip(): void
    {
        User::factory()->approved()->create(['phone' => '01712345678', 'password' => 'secret-pass-1']);

        for ($i = 0; $i < 5; $i++) {
            $this->login('01712345678', 'wrong-password')->assertUnprocessable();
        }

        $this->login('01712345678', 'secret-pass-1', 'bn')
            ->assertTooManyRequests()
            ->assertJsonPath('message', __('messages.login_rate_limited', [], 'bn'));
        $this->login('+8801712345678', 'secret-pass-1', 'en') // same number, other format
            ->assertTooManyRequests()
            ->assertJsonPath('message', __('messages.login_rate_limited', [], 'en'));

        // Same phone from another IP, and another phone from the same IP, are not blocked.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->login('01712345678', 'wrong-password')->assertUnprocessable();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->login('01999999999', 'wrong-password')->assertUnprocessable();

        $this->travel(61)->seconds();
        $this->login('01712345678', 'secret-pass-1')->assertOk();
    }

    public function test_logout(): void
    {
        $user = User::factory()->approved()->create(['phone' => '01712345678', 'password' => 'secret-pass-1']);
        $this->login('01712345678', 'secret-pass-1')->assertOk();
        $this->assertAuthenticatedAs($user, 'web');

        $this->postJson('/api/logout', [], ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertJsonPath('message', __('messages.logged_out', [], 'en'));

        $this->assertGuest('web');
    }

    public function test_me_requires_login(): void
    {
        $this->getJson('/api/me', ['Accept-Language' => 'bn'])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'অনুগ্রহ করে আগে লগইন করুন।');

        $this->postJson('/api/change-password', [])->assertUnauthorized();
    }

    public function test_member_suspended_while_logged_in_is_logged_out(): void
    {
        $user = User::factory()->suspended()->create();

        $this->actingAs($user)
            ->getJson('/api/me', ['Accept-Language' => 'en'])
            ->assertForbidden()
            ->assertJsonPath('status', 'suspended')
            ->assertJsonPath('message', __('messages.account_suspended', [], 'en'));

        $this->assertGuest('web');
    }

    // ---------- must_change_password ----------

    public function test_admin_reset_forces_password_change_at_next_login(): void
    {
        $super = User::factory()->superAdmin()->create();
        $admin = User::factory()->admin()->create(['phone' => '01712345678', 'password' => 'old-password-1']);

        // Super Admin resets the admin's password.
        $temporary = $this->actingAs($super)
            ->postJson("/api/admin/members/{$admin->id}/reset-password")
            ->assertOk()
            ->json('temporary_password');
        $this->postJson('/api/logout')->assertOk();
        $this->app['auth']->forgetGuards();

        // Old password no longer works; temporary one does, with the flag set.
        $this->login('01712345678', 'old-password-1')->assertUnprocessable();
        $this->login('01712345678', $temporary, 'en')
            ->assertOk()
            ->assertJsonPath('data.must_change_password', true)
            ->assertJsonPath('message', __('messages.password_change_required', [], 'en'));

        // Everything else is blocked until the password is changed; /me still works.
        $this->getJson('/api/admin/members', ['Accept-Language' => 'bn'])
            ->assertForbidden()
            ->assertJsonPath('must_change_password', true)
            ->assertJsonPath('message', __('messages.password_change_required', [], 'bn'));
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.must_change_password', true);

        // Wrong current password / same password / unconfirmed are refused.
        $this->postJson('/api/change-password', [
            'current_password' => 'nope-nope-1', 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass',
        ], ['Accept-Language' => 'en'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.current_password.0', 'The current password is incorrect.');
        $this->postJson('/api/change-password', [
            'current_password' => $temporary, 'password' => $temporary, 'password_confirmation' => $temporary,
        ])->assertJsonValidationErrors(['password']);
        $this->postJson('/api/change-password', [
            'current_password' => $temporary, 'password' => 'brand-new-pass', 'password_confirmation' => 'other',
        ])->assertJsonValidationErrors(['password']);

        $this->postJson('/api/change-password', [
            'current_password' => $temporary, 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass',
        ], ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertJsonPath('message', __('messages.password_changed', [], 'en'))
            ->assertJsonPath('data.must_change_password', false);

        $this->assertFalse($admin->refresh()->must_change_password);
        $this->assertTrue(Hash::check('brand-new-pass', $admin->password));
        $this->getJson('/api/admin/members')->assertOk();
    }
}
