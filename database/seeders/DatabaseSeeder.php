<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL');
        $name = env('ADMIN_NAME', 'Admin');
        $password = env('ADMIN_PASSWORD');

        if (! $email || ! $password) {
            $this->command->warn('ADMIN_EMAIL and ADMIN_PASSWORD must be set in .env to seed the admin user.');
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
