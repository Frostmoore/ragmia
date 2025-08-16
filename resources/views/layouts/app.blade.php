<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <!-- Favicon / App Icons -->
        <link rel="icon" type="image/png"
            href="{{ asset('img/bt-monogram-light.png') }}"
            media="(prefers-color-scheme: light)">
        <link rel="icon" type="image/png"
            href="{{ asset('img/bt-monogram-dark.png') }}"
            media="(prefers-color-scheme: dark)">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Dark mode auto (prima dei CSS per evitare flash) -->
        <script>
            try {
                if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    document.documentElement.classList.add('dark');
                }
            } catch (_) {}
        </script>

        <!-- Assets -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="h-screen overflow-hidden font-sans antialiased
                 bg-gray-100 text-gray-900
                 dark:bg-gray-900 dark:text-gray-100">
        <!-- Shell a piena altezza -->
        <div class="h-full flex flex-col">
            @include('layouts.navigation')

            @isset($header)
                <header class="shrink-0 bg-white shadow dark:bg-gray-900 dark:shadow-none border-b border-gray-200 dark:border-gray-700">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <!-- Area centrale flessibile (gli interni gestiscono lo scroll) -->
            <main class="flex-1 min-h-0 flex flex-col">
                {{ $slot }}
            </main>
        </div>
    </body>
</html>
