<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Default state = a freshly registered member (role member, status pending).
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '017'.fake()->unique()->numerify('########'),
            // Email / workplace without random person names, so a search test for one name never matches them by
            // chance (Faker can make "Ibrahim…", which contains "rahim" — the old flaky test, Phase 12d).
            'email' => 'member'.fake()->unique()->numerify('#######').'@example.com',
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'district' => 'dhaka',
            'workplace' => 'Knit Factory '.fake()->numerify('###'),
            'experience_years' => fake()->numberBetween(0, 20),
            'machines' => ['stoll'], // list keys (Phase 11d-1)
            'skills' => ['m1plus'],
            'role' => User::ROLE_MEMBER,
            'status' => User::STATUS_PENDING,
            'must_change_password' => false,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => User::STATUS_APPROVED,
            'approved_at' => now(),
        ]);
    }

    public function rejected(string $reason = 'Incomplete information'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => User::STATUS_REJECTED,
            'rejection_reason' => $reason,
        ]);
    }

    public function suspended(): static
    {
        return $this->approved()->state(fn (array $attributes) => [
            'status' => User::STATUS_SUSPENDED,
        ]);
    }

    public function committee(): static
    {
        return $this->approved()->state(fn (array $attributes) => ['role' => User::ROLE_COMMITTEE]);
    }

    public function admin(): static
    {
        return $this->approved()->state(fn (array $attributes) => ['role' => User::ROLE_ADMIN]);
    }

    public function superAdmin(): static
    {
        return $this->approved()->state(fn (array $attributes) => ['role' => User::ROLE_SUPER_ADMIN]);
    }
}
