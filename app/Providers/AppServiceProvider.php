<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // May borrow the loaders' Ancestry sessions through the Ancestry
        // Switch extension, and download it. See config/ancestry.php.
        Gate::define('switch-ancestry', fn (User $user) => in_array(
            strtolower((string) $user->email),
            config('ancestry.switch_emails'),
            true,
        ));
    }
}
