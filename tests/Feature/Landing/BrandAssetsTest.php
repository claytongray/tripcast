<?php

use function Pest\Laravel\get;

// FR-28 — go-live brand assets: app.blade.php wires the favicon set, manifest,
// mask-icon, and theme-color pair on every page.
it('serves the brand icon links, manifest, and theme-color metas', function () {
    $response = get('/');

    $response->assertOk();
    $response->assertSee('rel="manifest"', false);
    $response->assertSee('href="/site.webmanifest"', false);
    $response->assertSee('rel="mask-icon"', false);
    $response->assertSee('rel="apple-touch-icon"', false);
    $response->assertSee('href="/favicon.svg"', false);
    $response->assertSee('href="/favicon.ico"', false);
    $response->assertSee('content="#F6F9FC"', false);
    $response->assertSee('content="#0E1822"', false);
});

// The brand assets are committed files — they must not silently vanish.
it('ships the full brand asset set at the public root', function () {
    foreach ([
        'favicon.ico',
        'favicon.svg',
        'favicon-local.svg',
        'apple-touch-icon.png',
        'icon-192.png',
        'icon-512.png',
        'safari-pinned-tab.svg',
        'site.webmanifest',
    ] as $asset) {
        expect(file_exists(public_path($asset)))->toBeTrue("missing public/{$asset}");
    }
});

// In local, the SVG favicon swaps to a grayed-out mark so a local tab is easy to
// distinguish from prod. Non-local environments keep the full-color mark.
it('serves the grayed-out favicon only in the local environment', function () {
    app()->detectEnvironment(fn () => 'local');

    $response = get('/');

    $response->assertOk();
    $response->assertSee('href="/favicon-local.svg"', false);
    $response->assertDontSee('href="/favicon.svg"', false);
});

it('serves the full-color favicon outside local', function () {
    $response = get('/');

    $response->assertOk();
    $response->assertSee('href="/favicon.svg"', false);
    $response->assertDontSee('href="/favicon-local.svg"', false);
});

// FR-28 — the manifest is valid JSON, lowercase-branded, and none of its icons
// claims maskable (the mark isn't padded for the safe zone; a raw re-export of
// brand-assets/site.webmanifest would regress this).
it('serves a valid webmanifest without maskable icon claims', function () {
    $manifest = json_decode(
        file_get_contents(public_path('site.webmanifest')),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($manifest['name'])->toBe('tripcast')
        ->and($manifest['short_name'])->toBe('tripcast')
        ->and(array_column($manifest['icons'], 'src'))
        ->toBe(['/icon-192.png', '/icon-512.png']);

    foreach ($manifest['icons'] as $icon) {
        expect($icon['purpose'] ?? 'any')->not->toContain('maskable');
    }
});
