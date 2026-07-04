<?php

use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/**
 * SSR smoke test. `resources/js/app.ts` is the shared client + SSR entry, so its
 * trailing init calls (theme, flash, analytics) run in the Node SSR render too —
 * where `window`/`document` don't exist. An unguarded access there throws on
 * every render, kills the Inertia SSR daemon, and silently drops production to
 * client-side rendering while the deploy fails at `inertia:stop-ssr` (the GA4
 * analytics regression, 2026-07-04). This renders a real page through the SSR
 * server and fails if the bundle throws.
 *
 * Skipped when the SSR bundle isn't built (e.g. bare CI) — it never false-fails,
 * and guards every environment that has run `npm run build:ssr`.
 */
beforeEach(function () {
    if (! file_exists(base_path('bootstrap/ssr/app.js'))) {
        $this->markTestSkipped('SSR bundle not built — run `npm run build:ssr`.');
    }
});

function withSsrServer(callable $body): void
{
    $server = Process::fromShellCommandline('php artisan inertia:start-ssr', base_path());
    $server->start();

    try {
        // Wait for the server to accept connections (or crash on bundle eval).
        $up = false;
        for ($i = 0; $i < 40 && $server->isRunning(); $i++) {
            try {
                if (Http::timeout(1)->get('http://127.0.0.1:13714/health')->successful()) {
                    $up = true;
                    break;
                }
            } catch (\Throwable) {
                // not listening yet
            }
            usleep(250_000);
        }

        expect($up)->toBeTrue('SSR server never became ready (bundle likely threw on eval).');

        $body();
    } finally {
        try {
            Http::timeout(1)->get('http://127.0.0.1:13714/shutdown');
        } catch (\Throwable) {
            // fall through to a hard stop
        }
        $server->stop(2);
    }
}

it('renders a page through the Inertia SSR server without throwing', function () {
    withSsrServer(function () {
        $response = Http::timeout(5)->post('http://127.0.0.1:13714/render', [
            'component' => 'Landing',
            // The shape Laravel's Inertia middleware always supplies (auth.user,
            // flash, errors) — a real render, not a bare page object.
            'props' => ['errors' => (object) [], 'auth' => ['user' => null], 'flash' => (object) []],
            'url' => '/',
            'version' => '',
            'clearHistory' => false,
            'encryptHistory' => false,
        ]);

        $payload = $response->json();

        expect($response->successful())->toBeTrue()
            ->and($payload)->toHaveKeys(['head', 'body'])
            ->and($payload['error'] ?? null)->toBeNull();
    });
});
