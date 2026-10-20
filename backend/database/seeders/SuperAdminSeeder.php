<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\BdPhone;
use Illuminate\Database\Seeder;

/**
 * Creates the first Super Admin from SUPER_ADMIN_* values in backend/.env.
 * Safe to run again: an existing account with that phone is only promoted
 * (role/status) — its password is not changed. The password is never printed.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $config = config('jpphaf.super_admin');
        $phone = BdPhone::normalize((string) $config['phone']);

        if (! preg_match(BdPhone::PATTERN, (string) $phone) || blank($config['password'])) {
            $this->command?->warn('SuperAdminSeeder skipped: set SUPER_ADMIN_PHONE (01XXXXXXXXX) and SUPER_ADMIN_PASSWORD in backend/.env.');

            return;
        }
        if (strlen($config['password']) < 8) {
            $this->command?->warn('SuperAdminSeeder skipped: SUPER_ADMIN_PASSWORD must be at least 8 characters.');

            return;
        }

        $user = User::where('phone', $phone)->first();
        $created = ! $user;

        if ($created) {
            $user = new User([
                'name' => $config['name'] ?: 'Super Admin',
                'phone' => $phone,
                'email' => $config['email'] ?: null,
                'password' => $config['password'],
            ]);
        }

        $user->role = User::ROLE_SUPER_ADMIN;
        $user->status = User::STATUS_APPROVED;
        $user->approved_at ??= now();
        $user->save();

        $this->command?->info(($created ? 'Super Admin created' : 'Super Admin updated').' for phone '.$phone.'.');
    }
}
