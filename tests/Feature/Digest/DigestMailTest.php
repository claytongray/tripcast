<?php

use App\Mail\DigestMail;
use App\Models\Trip;
use App\Models\User;

function digestTrip(string $place = 'Edinburgh, United Kingdom'): Trip
{
    // Persisted with an owner: DigestMail builds signed footer URLs from
    // trip->id and trip->user->id (Story 2.5).
    return User::factory()->confirmed()->create()->trips()->create([
        'destination_raw' => 'Edinburgh',
        'canonical_place_name' => $place,
        'latitude' => 55.9533,
        'longitude' => -3.1883,
        'departure_date' => '2026-07-04', // send_date 2026-06-29 → 5 days until
        'return_date' => '2026-07-11',
        'status' => Trip::STATUS_ACTIVE,
    ]);
}

/**
 * A trip whose window spans the entire snapshot (06-29…07-05), so every
 * snapshot day is a trip day and the forecast renders all of them — the case
 * the forecast-content assertions below exercise.
 */
function digestTripSpanningSnapshot(): Trip
{
    $trip = digestTrip();
    $trip->update(['departure_date' => '2026-06-29', 'return_date' => '2026-07-05']);

    return $trip->fresh();
}

/**
 * An all-full forecast snapshot for the given destination-local dates — the
 * shape the renderer reads after a clean fetch (no per-day limited markers).
 *
 * @param  list<string>  $dates
 * @return array{days: list<array<string, mixed>>, limited: bool}
 */
function fullForecastSnapshot(array $dates): array
{
    return [
        'days' => array_map(fn (string $date): array => [
            'date' => $date, 'conditionText' => 'Sunny', 'precipChance' => 10,
            'highC' => 20.0, 'highF' => 68.0, 'lowC' => 12.0, 'lowF' => 54.0,
        ], $dates),
        'limited' => false,
    ];
}

/**
 * Six full days + one limited day (all-null) → a limited forecast.
 *
 * @return array{days: list<array<string, mixed>>, limited: bool}
 */
function digestSnapshot(): array
{
    return [
        'days' => [
            ['date' => '2026-06-29', 'conditionText' => 'Sunny', 'precipChance' => 10, 'highC' => 20.0, 'highF' => 68.0, 'lowC' => 12.0, 'lowF' => 54.0],
            ['date' => '2026-06-30', 'conditionText' => 'Cloudy', 'precipChance' => 30, 'highC' => 18.0, 'highF' => 64.0, 'lowC' => 11.0, 'lowF' => 52.0],
            ['date' => '2026-07-01', 'conditionText' => 'Rain', 'precipChance' => 80, 'highC' => 15.0, 'highF' => 59.0, 'lowC' => 9.0, 'lowF' => 48.0],
            ['date' => '2026-07-02', 'conditionText' => 'Clear', 'precipChance' => 5, 'highC' => 22.0, 'highF' => 72.0, 'lowC' => 13.0, 'lowF' => 55.0],
            ['date' => '2026-07-03', 'conditionText' => 'Windy', 'precipChance' => 20, 'highC' => 17.0, 'highF' => 63.0, 'lowC' => 10.0, 'lowF' => 50.0],
            ['date' => '2026-07-04', 'conditionText' => 'Fog', 'precipChance' => 40, 'highC' => 14.0, 'highF' => 57.0, 'lowC' => 8.0, 'lowF' => 46.0],
            ['date' => '2026-07-05', 'conditionText' => null, 'precipChance' => null, 'highC' => null, 'highF' => null, 'lowC' => null, 'lowF' => null],
        ],
        'limited' => true,
    ];
}

it('subjects with place + countdown and never the weather verdict', function () {
    $mail = new DigestMail(digestTrip(), digestSnapshot(), '2026-06-29');

    $mail->assertHasSubject('Edinburgh — 5 days to go');
    // The weather verdict (a condition word) must never leak into the subject.
    expect($mail->envelope()->subject)->not->toContain('Sunny');
});

it('renders the canonical place and the position line in HTML and text', function () {
    $mail = new DigestMail(digestTrip(), digestSnapshot(), '2026-06-29');

    $mail->assertSeeInHtml('Edinburgh');
    $mail->assertSeeInHtml('5 days until Edinburgh');
    $mail->assertSeeInText('5 days until Edinburgh');
});

it('renders every full day in the owner unit (Fahrenheit default) and never the other', function () {
    $mail = new DigestMail(digestTripSpanningSnapshot(), digestSnapshot(), '2026-06-29');

    // Bare degrees in the owner's single unit (Fahrenheit values here).
    foreach (['68', '64', '59', '72', '63', '57'] as $highF) {
        $mail->assertSeeInHtml($highF.'°');
        $mail->assertSeeInText($highF.'°');
    }

    // No unit letter is printed, and the Celsius figure (20°) never renders.
    $mail->assertDontSeeInHtml('°F');
    $mail->assertDontSeeInHtml('°C');
    $mail->assertDontSeeInHtml('20°');

    // Condition text (legible with images blocked) + a decorative weather emoji + precip.
    $mail->assertSeeInHtml('Sunny');
    $mail->assertSeeInHtml('☀️');
    $mail->assertSeeInHtml('80% precip');
    $mail->assertSeeInText('Rain');
    $mail->assertSeeInText('80% precip');
});

it('renders Celsius values for a Celsius-preferring owner and never the Fahrenheit figure', function () {
    $trip = digestTripSpanningSnapshot();
    $trip->user->update(['temperature_unit' => 'celsius']);
    $mail = new DigestMail($trip->fresh(), digestSnapshot(), '2026-06-29');

    foreach (['20', '18', '15', '22', '17', '14'] as $highC) {
        $mail->assertSeeInHtml($highC.'°');
        $mail->assertSeeInText($highC.'°');
    }

    $mail->assertDontSeeInHtml('°F');
    $mail->assertDontSeeInHtml('°C');
    $mail->assertDontSeeInHtml('68°'); // the Fahrenheit high never appears
});

it('renders the limited marker and never fabricates values for a limited day', function () {
    $mail = new DigestMail(digestTripSpanningSnapshot(), digestSnapshot(), '2026-06-29');

    // The forecast-level calm line and the per-day marker both appear.
    $mail->assertSeeInHtml("Limited data today — we'll have the full picture tomorrow.");
    $mail->assertSeeInHtml('Limited data');
    $mail->assertSeeInText('Limited data');

    // The limited day fabricates nothing: only the 6 full days carry a temp line.
    expect(substr_count($mail->render(), '% precip'))->toBe(6);
});

it('clips the forecast to the trip window, showing only the trip days', function () {
    // A 3-day trip wholly inside the snapshot window (06-29…07-05): the digest
    // renders exactly its own days — never pre-departure or post-return days.
    $trip = digestTrip();
    $trip->update(['departure_date' => '2026-06-30', 'return_date' => '2026-07-02']);

    $mail = new DigestMail($trip->fresh(), digestSnapshot(), '2026-06-29');

    // Exactly the three trip days carry a temp line (Cloudy / Rain / Clear).
    expect(substr_count($mail->render(), '% precip'))->toBe(3);
    $mail->assertSeeInHtml('Cloudy');
    $mail->assertSeeInHtml('Rain');
    $mail->assertSeeInHtml('Clear');

    // Days outside the trip window never render.
    $mail->assertDontSeeInHtml('Sunny'); // 06-29, before departure
    $mail->assertDontSeeInHtml('Windy'); // 07-03, after return
    $mail->assertDontSeeInHtml('Fog');   // 07-04, after return

    // Every shown day is complete → no forecast-level limited line.
    $mail->assertDontSeeInHtml("Limited data today — we'll have the full picture tomorrow.");
});

it('shows the departure day only with the more-data-soon line when the rest of the trip is beyond the horizon', function () {
    // First cadence email (7 days out): the forecast horizon (06-29…07-06)
    // reaches only the departure day of a 5-day trip; the rest arrives as the
    // trip nears, so the calm "full picture tomorrow" line shows.
    $snapshot = fullForecastSnapshot([
        '2026-06-29', '2026-06-30', '2026-07-01', '2026-07-02',
        '2026-07-03', '2026-07-04', '2026-07-05', '2026-07-06',
    ]);
    $trip = digestTrip();
    $trip->update(['departure_date' => '2026-07-06', 'return_date' => '2026-07-10']);

    $mail = new DigestMail($trip->fresh(), $snapshot, '2026-06-29');

    // Only the departure day is in range yet.
    expect(substr_count($mail->render(), '% precip'))->toBe(1);
    $mail->assertSeeInHtml('✈️ The start of your trip!');
    $mail->assertSeeInHtml("Limited data today — we'll have the full picture tomorrow.");
});

it('shows every trip day and drops the more-data-soon line once the forecast reaches the return date', function () {
    // The same horizon (06-29…07-06) now fully covers a 5-day trip ending 07-03:
    // all five days render and the calm line is gone.
    $snapshot = fullForecastSnapshot([
        '2026-06-29', '2026-06-30', '2026-07-01', '2026-07-02',
        '2026-07-03', '2026-07-04', '2026-07-05', '2026-07-06',
    ]);
    $trip = digestTrip();
    $trip->update(['departure_date' => '2026-06-29', 'return_date' => '2026-07-03']);

    $mail = new DigestMail($trip->fresh(), $snapshot, '2026-06-29');

    expect(substr_count($mail->render(), '% precip'))->toBe(5);
    $mail->assertDontSeeInHtml("Limited data today — we'll have the full picture tomorrow.");
});

it('tags the departure-day row when it falls inside the forecast window', function () {
    // digestTrip departs 2026-07-04, which is inside the snapshot (06-29…07-05).
    $mail = new DigestMail(digestTrip(), digestSnapshot(), '2026-06-29');

    $mail->assertSeeInHtml('✈️ The start of your trip!');
    $mail->assertSeeInText('The start of your trip!');
});

it('omits the trip-start tag when departure is outside the forecast window', function () {
    $trip = digestTrip();
    $trip->update(['departure_date' => '2026-08-15', 'return_date' => '2026-08-22']);

    $mail = new DigestMail($trip->fresh(), digestSnapshot(), '2026-06-29');

    $mail->assertDontSeeInHtml('The start of your trip!');
    $mail->assertDontSeeInText('The start of your trip!');
});

it('is legible with images blocked (no image-only meaning, no background-image)', function () {
    $mail = new DigestMail(digestTrip(), digestSnapshot(), '2026-06-29');

    $mail->assertDontSeeInHtml('background-image');
    $mail->assertDontSeeInHtml('<img');
});

it('renders the postal address only when configured', function () {
    config(['tripcast.postal_address' => 'Tripcast, 123 Main St, Anytown']);
    $mail = new DigestMail(digestTrip(), digestSnapshot(), '2026-06-29');

    $mail->assertSeeInHtml('Tripcast, 123 Main St, Anytown');
    $mail->assertSeeInText('Tripcast, 123 Main St, Anytown');
});

// Story 2.5 — footer action links + List-Unsubscribe one-click headers.
it('carries the List-Unsubscribe one-click headers', function () {
    config(['tripcast.unsubscribe_mailto' => 'unsubscribe@tripcast.test']);
    $headers = (new DigestMail(digestTrip(), digestSnapshot(), '2026-06-29'))->headers();

    expect($headers->text['List-Unsubscribe'])
        ->toContain('/unsubscribe/one-click')   // signed HTTPS one-click target
        ->toContain('signature=')
        ->toContain('mailto:unsubscribe@tripcast.test')
        ->and($headers->text['List-Unsubscribe-Post'])->toBe('List-Unsubscribe=One-Click');
});

it('renders signed End-trip + Unsubscribe footer links in HTML and the text twin', function () {
    $mail = new DigestMail(digestTrip(), digestSnapshot(), '2026-06-29');

    // HTML footer: text links (legible with images blocked), pointing at signed routes.
    $mail->assertSeeInHtml('End this trip');
    $mail->assertSeeInHtml('Unsubscribe');
    $mail->assertSeeInHtml('/end?signature=', false);
    $mail->assertSeeInHtml('/unsubscribe?signature=', false);

    // Plain-text twin: literal tappable URLs (deliverability / UX-DR17).
    $mail->assertSeeInText('End this trip:');
    $mail->assertSeeInText('Unsubscribe:');
    $mail->assertSeeInText('/end?signature=');
    $mail->assertSeeInText('/unsubscribe?signature=');
});

// Story 2.6 — one-tap feedback chips.
it('renders signed feedback chips with text labels in HTML and the text twin', function () {
    $mail = new DigestMail(digestTrip(), digestSnapshot(), '2026-06-29');

    // Visible text labels (not emoji-only), linking to the signed feedback routes.
    $mail->assertSeeInHtml('This helped');
    $mail->assertSeeInHtml('Not helpful');
    $mail->assertSeeInHtml('/feedback/helped?', false);
    $mail->assertSeeInHtml('/feedback/not_helpful?', false);
    $mail->assertSeeInHtml('send_date=2026-06-29', false);

    // Plain-text twin carries both literal feedback URLs.
    $mail->assertSeeInText('This helped:');
    $mail->assertSeeInText('Not helpful:');
    $mail->assertSeeInText('/feedback/helped?');
    $mail->assertSeeInText('/feedback/not_helpful?');
});
