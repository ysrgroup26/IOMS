<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Fixes the favicon.ico 404 (v1.6.5 QA) -- browsers only fall back
         to auto-requesting /favicon.ico when no icon link is declared at
         all. Reuses the existing brand icon asset; PNG favicons are
         fully supported by every modern browser, no .ico conversion
         needed. --}}
    <link rel="icon" type="image/png" href="{{ asset('branding/icon.png') }}">

    {{-- Applies the saved theme (or OS preference) before first paint, so
         there's no flash of the wrong theme while React hydrates. Kept as
         a tiny inline script (not bundled JS) specifically so it runs
         synchronously, before the page renders anything.

         Final Bug Fix Before Beta: Dark Mode temporarily disabled
         app-wide (matches DARK_MODE_ENABLED in resources/js/lib/useTheme.js
         -- this plain script can't import that constant, so the same
         `false` is duplicated here; flip both together when re-enabling).
         The detection logic below is left intact, just short-circuited,
         so restoring it later is uncommenting one line, not rewriting. --}}
    <script>
        (function () {
            var DARK_MODE_ENABLED = false;
            var stored = localStorage.getItem('ioms-theme');
            var theme = (DARK_MODE_ENABLED && (stored === 'light' || stored === 'dark'))
                ? stored
                : (DARK_MODE_ENABLED && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            if (theme === 'dark') document.documentElement.classList.add('dark');
        })();
    </script>

    <title inertia>{{ config('app.name', 'Integrated Operations Management System') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    @routes
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
    @inertiaHead
</head>
<body class="h-full font-sans">
    @inertia
</body>
</html>
