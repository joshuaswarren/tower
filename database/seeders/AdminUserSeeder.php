<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Seeds the single admin user for the board (registration is disabled).
 *
 * Credentials come from TOWER_ADMIN_EMAIL / TOWER_ADMIN_PASSWORD. In
 * production the seeder REFUSES to run without them (no default password
 * ever ships). Idempotent: updates the password if the admin already exists.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) (config('tower.admin.email') ?? '');
        $password = (string) (config('tower.admin.password') ?? '');

        if ($email === '' || $password === '') {
            if (app()->environment('production')) {
                throw new RuntimeException(
                    'TOWER_ADMIN_EMAIL and TOWER_ADMIN_PASSWORD must be set to seed the admin in production.'
                );
            }

            $this->command?->warn('AdminUserSeeder skipped: TOWER_ADMIN_EMAIL/PASSWORD not set.');

            return;
        }

        User::query()->updateOrCreate(
            ['email' => $email],
            ['name' => 'Tower Admin', 'password' => Hash::make($password)],
        );

        $this->command?->info("Admin user seeded: {$email}");
    }
}
