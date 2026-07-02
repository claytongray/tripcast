<?php

use function Pest\Laravel\get;

// FR-24 — link previews: app.blade.php serves a meta description + OG/Twitter
// tags globally, so any shared tripcast URL previews correctly.
it('serves a meta description and Open Graph tags on the landing page', function () {
    $response = get('/');

    $response->assertOk();
    $response->assertSee('name="description"', false);
    $response->assertSee('property="og:title"', false);
    $response->assertSee('property="og:description"', false);
    $response->assertSee('property="og:image"', false);
    $response->assertSee(url('/og-image.png'), false);
    $response->assertSee('name="twitter:card"', false);
});

// The preview assets are committed files — they must not silently vanish.
it('ships the og-image and digest screenshot assets', function () {
    expect(file_exists(public_path('og-image.png')))->toBeTrue()
        ->and(file_exists(public_path('images/digest-sample.png')))->toBeTrue();
});
