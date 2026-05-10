<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'DNAWeb') }}</title>

        <!-- Fonts: EB Garamond (display), Source Serif 4 (body), Inter Tight (UI), JetBrains Mono (data) -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=eb-garamond:400,500,600,700,400i,500i|source-serif-4:400,500,600,400i|inter-tight:400,500,600,700|jetbrains-mono:400,500&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-serif antialiased">
        @inertia
    </body>
</html>
