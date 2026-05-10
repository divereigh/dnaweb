<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Read via config() not env() — env() returns null in cached production builds.
        $email = config('admin.email');
        $name = config('admin.name') ?: 'Admin';
        $password = config('admin.password');

        if (! $email || ! $password) {
            $this->command->warn('ADMIN_EMAIL and ADMIN_PASSWORD must be set in .env to seed the admin user.');
            $this->command->warn('If you just edited .env, run `php artisan config:clear` first.');
            return;
        }

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ],
        );

        $this->command->info("Admin user {$email} ready.");
    }
}
