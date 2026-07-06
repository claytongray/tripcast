<?php

use App\Mail\DigestMail;
use App\Models\Trip;

/**
 * Story 11.3 (CAP-8) — the Apple Weather attribution is a WeatherKit license
 * mandate: its mark + a link to Apple's legal data-source page must appear
 * wherever WeatherKit data is shown, and only then. The gate is the active
 * provider flag, so the attribution can never be on with the data absent (or
 * vice-versa). It renders as text, never an image — Gmail/Outlook strip inlined
 * data-URI images, so an image mark would silently vanish in most inboxes.
 */
const APPLE_LEGAL_URL = 'https://developer.apple.com/weatherkit/data-source-attribution/';

function attributionDigest(): DigestMail
{
    $trip = Trip::factory()->create();

    return new DigestMail($trip, ['days' => [], 'limited' => false], '2026-06-29');
}

it('renders the Apple Weather text mark linked to the legal page under WeatherKit', function () {
    config()->set('tripcast.forecast.provider', 'weatherkit');

    $html = attributionDigest()->render();

    // The trademark, as text — never an image (data-URI images get stripped inbox-side).
    expect($html)->toContain('Apple Weather')
        ->and($html)->not->toContain('src="data:image')
        ->and($html)->not->toContain('src="https://weatherkit.apple.com')
        // Linked to Apple's legal data-source attribution page.
        ->and($html)->toContain(APPLE_LEGAL_URL);
});

it('shows the Apple Weather source line in the plain-text twin under WeatherKit', function () {
    config()->set('tripcast.forecast.provider', 'weatherkit');

    $mail = attributionDigest();

    $mail->assertSeeInText('Weather data by Apple Weather — '.APPLE_LEGAL_URL);
});

it('renders no Apple attribution under WeatherAPI (HTML or text)', function () {
    config()->set('tripcast.forecast.provider', 'weatherapi');

    $mail = attributionDigest();
    $html = $mail->render();

    expect($html)->not->toContain('alt="Apple Weather"')
        ->and($html)->not->toContain(APPLE_LEGAL_URL);
    $mail->assertDontSeeInText('Apple Weather');
});
