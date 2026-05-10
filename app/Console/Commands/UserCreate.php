<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

class UserCreate extends Command
{
    protected $signature = 'user:create
        {email   : Email address (must be unique)}
        {name?   : Display name (defaults to email local-part)}
        {--password= : Password (will prompt securely if omitted)}
        {--update : If a user with this email exists, update name/password instead of erroring}';

    protected $description = 'Create (or update) a user who can log in to DNAWeb.';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        $name = $this->argument('name') ?: explode('@', $email, 2)[0];
        $password = $this->option('password') ?: $this->secret('Password');
        $update = (bool) $this->option('update');

        $emailRule = ['required', 'email', 'max:255'];
        if (!$update) {
            $emailRule[] = Rule::unique('users', 'email');
        }

        $v = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $password],
            [
                'email' => $emailRule,
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', 'min:8'],
            ],
        );

        if ($v->fails()) {
            foreach ($v->errors()->all() as $err) {
                $this->error($err);
            }
            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ],
        );

        $verb = $user->wasRecentlyCreated ? 'created' : 'updated';
        $this->info("✓ User {$verb}: {$user->email} ({$user->name})");

        return self::SUCCESS;
    }
}
