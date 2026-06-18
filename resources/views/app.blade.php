<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <!-- CSRF Token -->
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title inertia>{{ config('app.name', 'TAS') }}</title>

        <!-- Favicon -->
        <link rel="icon" type="image/x-icon" href="{{ asset('images/favicon_96x96.ico') }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        <script>
            window.config = {
                google_maps_api_key: "{{ \App\Models\Setting::get('google_maps_api_key', config('services.google.maps_api_key')) }}"
            };
        </script>
        @routes
        @vite(['resources/js/app.js'])
        @inertiaHead

        <!-- Theme (dark/light) anti-flash: applied before paint to avoid FOUC -->
        <script>
            (function () {
                try {
                    var key = 'ghelpdesk.theme';
                    var stored = window.localStorage.getItem(key);
                    var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                    var useDark = stored === 'dark' || ((stored === null || stored === 'system') && prefersDark);
                    document.documentElement.classList.toggle('dark', useDark);
                } catch (e) {
                    // Ignore storage/media failures so the page still renders.
                }
            })();
        </script>
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
