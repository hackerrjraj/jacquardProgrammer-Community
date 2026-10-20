<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SuperAdminSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_super_admin_from_config_without_printing_password(): void
    {
        config(['jpphaf.super_admin' => [
            'name' => 'Owner', 'phone' => '+8801711000000', 'email' => 'owner@example.com', 'password' => 'Very-Secret-99',
        ]]);

        $this->artisan('db:seed', ['--class' => SuperAdminSeeder::class])
            ->doesntExpectOutputToContain('Very-Secret-99')
            ->assertSuccessful();

        $user = User::sole();
        $this->assertSame('01711000000', $user->phone);
        $this->assertSame('super_admin', $user->role);
        $this->assertSame('approved', $user->status);
        $this->assertTrue(Hash::check('Very-Secret-99', $user->password));

        // Running again does not duplicate or change the password.
        config(['jpphaf.super_admin.password' => 'Another-Pass-1']);
        $this->artisan('db:seed', ['--class' => SuperAdminSeeder::class])->assertSuccessful();
        $this->assertSame(1, User::count());
        $this->assertTrue(Hash::check('Very-Secret-99', $user->refresh()->password));
    }

    public function test_skips_when_values_are_missing(): void
    {
        config(['jpphaf.super_admin' => ['name' => null, 'phone' => null, 'email' => null, 'password' => null]]);

        $this->artisan('db:seed', ['--class' => SuperAdminSeeder::class])->assertSuccessful();

        $this->assertSame(0, User::count());
    }
}
