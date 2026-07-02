<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark', 'light' => ($appearance ?? 'system') == 'light'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">

        {{-- Link previews (FR-24): one global product preview — every tripcast
             URL shares it, so the tags live here rather than per-page. --}}
        <meta name="description" content="Stop checking the weather for your trip. tripcast watches your destination and sends one calm morning email — starting 7 days out, stopping after you're home.">
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="tripcast">
        <meta property="og:title" content="tripcast — the weather app you never have to open">
        <meta property="og:description" content="Stop checking the weather for your trip. tripcast watches your destination and sends one calm morning email — starting 7 days out, stopping after you're home.">
        <meta property="og:url" content="{{ url('/') }}">
        <meta property="og:image" content="{{ url('/og-image.png') }}">
        <meta property="og:image:width" content="2400">
        <meta property="og:image:height" content="1260">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="tripcast — the weather app you never have to open">
        <meta name="twitter:description" content="Stop checking the weather for your trip. tripcast watches your destination and sends one calm morning email — starting 7 days out, stopping after you're home.">
        <meta name="twitter:image" content="{{ url('/og-image.png') }}">

        {{-- Resolve the appearance to a concrete class immediately to avoid a flash --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                    document.documentElement.classList.add(prefersDark ? 'dark' : 'light');
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: #F6F9FC;
            }

            html.dark {
                background-color: #0E1822;
            }

            @media (prefers-color-scheme: dark) {
                html:not(.light) {
                    background-color: #0E1822;
                }
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="48x48 32x32 16x16">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <link rel="manifest" href="/site.webmanifest">
        <link rel="mask-icon" href="/safari-pinned-tab.svg" color="#2563A6">
        {{-- Must match the inline html background colors above --}}
        <meta name="theme-color" media="(prefers-color-scheme: light)" content="#F6F9FC">
        <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#0E1822">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
