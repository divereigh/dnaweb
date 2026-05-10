<?php

// Seeded admin user — read from .env at config-load time so it survives
// `php artisan config:cache` (env() returns null in cached production builds).
return [
    'email' => env('ADMIN_EMAIL'),
    'name' => env('ADMIN_NAME', 'Admin'),
    'password' => env('ADMIN_PASSWORD'),
];
