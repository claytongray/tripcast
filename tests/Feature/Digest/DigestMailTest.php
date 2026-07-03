<?php

use App\Mail\DigestMail;
use App\Models\Trip;
use App\Models\User;
use App\Services\Promo\Promo;

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
            // 06-29 & 07-02 have a wide high↔feels-like gap (≥5°F / ≥3°C) → humidity shows.
            ['date' => '2026-06-29', 'conditionText' => 'Sunny', 'precipChance' => 10, 'highC' => 20.0, 'highF' => 68.0, 'lowC' => 12.0, 'lowF' => 54.0, 'humidity' => 55, 'feelsLikeHighC' => 24.0, 'feelsLikeHighF' => 75.0],
            ['date' => '2026-06-30', 'conditionText' => 'Cloudy', 'precipChance' => 30, 'highC' => 18.0, 'highF' => 64.0, 'lowC' => 11.0, 'lowF' => 52.0, 'humidity' => 60, 'feelsLikeHighC' => 19.0, 'feelsLikeHighF' => 66.0],
            ['date' => '2026-07-01', 'conditionText' => 'Rain', 'precipChance' => 80, 'highC' => 15.0, 'highF' => 59.0, 'lowC' => 9.0, 'lowF' => 48.0, 'humidity' => 85, 'feelsLikeHighC' => 16.0, 'feelsLikeHighF' => 61.0],
            ['date' => '2026-07-02', 'conditionText' => 'Clear', 'precipChance' => 5, 'highC' => 22.0, 'highF' => 72.0, 'lowC' => 13.0, 'lowF' => 55.0, 'humidity' => 45, 'feelsLikeHighC' => 27.0, 'feelsLikeHighF' => 80.0],
            ['date' => '2026-07-03', 'conditionText' => 'Windy', 'precipChance' => 20, 'highC' => 17.0, 'highF' => 63.0, 'lowC' => 10.0, 'lowF' => 50.0, 'humidity' => 50, 'feelsLikeHighC' => 18.0, 'feelsLikeHighF' => 64.0],
            ['date' => '2026-07-04', 'conditionText' => 'Fog', 'precipChance' => 40, 'highC' => 14.0, 'highF' => 57.0, 'lowC' => 8.0, 'lowF' => 46.0, 'humidity' => 65, 'feelsLikeHighC' => 15.0, 'feelsLikeHighF' => 58.0],
            ['date' => '2026-07-05', 'conditionText' => null, 'precipChance' => null, 'highC' => null, 'highF' => null, 'lowC' => null, 'lowF' => null, 'humidity' => null, 'feelsLikeHighC' => null, 'feelsLikeHighF' => null],
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

it('renders the place heading, the countdown sub-line, and the trip dates without repeating the place', function () {
    $mail = new DigestMail(digestTrip(), digestSnapshot(), '2026-06-29');

    $mail->assertSeeInHtml('Edinburgh');           // the heading
    $mail->assertSeeInHtml('5 days to go!');       // countdown sub-line
    $mail->assertSeeInHtml('Jul 4–11');            // trip dates below it
    $mail->assertSeeInText('5 days to go!');
    $mail->assertSeeInText('Jul 4–11');

    // The old place-repeating position line is gone.
    $mail->assertDontSeeInHtml('5 days until Edinburgh');
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

    // Top line: glyph + high/low + feels-like (06-29 → ☀️ 68° / 54° • feels like 75°).
    $mail->assertSeeInHtml('☀️ 68° / 54° • feels like 75°');
    $mail->assertSeeInText('☀️ 68° / 54° • feels like 75°');

    // Bottom line: condition label + spelled-out precipitation (07-01 → Rain • 80% precipitation).
    $mail->assertSeeInHtml('Rain • 80% precipitation');
    $mail->assertSeeInText('Rain • 80% precipitation');

    // Humidity rides along only on the wide-gap days (06-29: 68→75°F, 07-02: 72→80°F);
    // the near-equal days (e.g. 06-30 humidity 60, 07-01 humidity 85) drop it.
    $mail->assertSeeInHtml('55% humidity');
    $mail->assertSeeInText('45% humidity');
    $mail->assertDontSeeInHtml('60% humidity');
    $mail->assertDontSeeInHtml('85% humidity');

    // Feels-like (peak apparent temp) renders in the owner unit alongside the high/low.
    $mail->assertSeeInHtml('feels like 75°');
    $mail->assertSeeInText('feels like 80°');

    // No raw Blade directives leak into the output (a glued @if isn't compiled).
    expect($mail->render())->not->toContain('@if')
        ->and($mail->render())->not->toContain('@endif');
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

    // Feels-like also follows the Celsius unit (the 75°F peak is 24°C).
    $mail->assertSeeInHtml('feels like 24°');
    $mail->assertDontSeeInHtml('feels like 75°');

    // The humidity gate uses the Celsius threshold (~3°C): 06-29 (20→24°C) shows it,
    // the near-equal 06-30 (18→19°C) drops it.
    $mail->assertSeeInHtml('55% humidity');
    $mail->assertDontSeeInHtml('60% humidity');
});

it('shows humidity only when the high and feels-like diverge past the threshold', function () {
    // Two full Fahrenheit days at the boundary: a 5°F gap (shows) and a 4°F gap (hidden).
    $snapshot = [
        'days' => [
            ['date' => '2026-06-29', 'conditionText' => 'Sunny', 'precipChance' => 10, 'highC' => 26.0, 'highF' => 80.0, 'lowC' => 16.0, 'lowF' => 60.0, 'humidity' => 70, 'feelsLikeHighC' => 29.0, 'feelsLikeHighF' => 85.0],
            ['date' => '2026-06-30', 'conditionText' => 'Sunny', 'precipChance' => 10, 'highC' => 21.0, 'highF' => 70.0, 'lowC' => 13.0, 'lowF' => 55.0, 'humidity' => 40, 'feelsLikeHighC' => 23.0, 'feelsLikeHighF' => 74.0],
        ],
        'limited' => false,
    ];
    $trip = digestTrip();
    $trip->update(['departure_date' => '2026-06-29', 'return_date' => '2026-06-30']);

    $mail = new DigestMail($trip->fresh(), $snapshot, '2026-06-29');

    // 80→85°F is a 5°F gap → humidity shows; 70→74°F is a 4°F gap → dropped.
    $mail->assertSeeInHtml('70% humidity');
    $mail->assertSeeInText('70% humidity');
    $mail->assertDontSeeInHtml('40% humidity');
    $mail->assertDontSeeInText('40% humidity');
});

it('renders the limited marker and never fabricates values for a limited day', function () {
    $mail = new DigestMail(digestTripSpanningSnapshot(), digestSnapshot(), '2026-06-29');

    // The forecast-level calm line and the per-day marker both appear.
    $mail->assertSeeInHtml("Limited data today — we'll have the full picture tomorrow.");
    $mail->assertSeeInHtml('Limited data');
    $mail->assertSeeInText('Limited data');

    // The limited day fabricates nothing: only the 6 full days carry a temp line,
    // and feels-like rides along on exactly those 6 — never the limited day.
    expect(substr_count($mail->render(), '% precip'))->toBe(6)
        ->and(substr_count($mail->render(), 'feels like'))->toBe(6);
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

it('shows the departure day plus a collapsed future line when the rest of the trip is beyond the horizon', function () {
    // First cadence email (7 days out): the forecast horizon (06-29…07-06)
    // reaches only the departure day of a 5-day trip; the rest of the itinerary
    // (07-07…07-10) stays visible as one calm collapsed line, never a data-gap.
    $snapshot = fullForecastSnapshot([
        '2026-06-29', '2026-06-30', '2026-07-01', '2026-07-02',
        '2026-07-03', '2026-07-04', '2026-07-05', '2026-07-06',
    ]);
    $trip = digestTrip();
    $trip->update(['departure_date' => '2026-07-06', 'return_date' => '2026-07-10']);

    $mail = new DigestMail($trip->fresh(), $snapshot, '2026-06-29');

    // Only the departure day carries a forecast; the rest collapse into one line.
    expect(substr_count($mail->render(), '% precip'))->toBe(1);
    $mail->assertSeeInHtml('The start of your trip!');
    $mail->assertSeeInHtml('Jul 7–10');
    $mail->assertSeeInHtml('Forecast appears once these days are within 7 days');
    $mail->assertSeeInText('Jul 7–10 — Forecast appears once these days are within 7 days');

    // Beyond-horizon days are no longer treated as a data gap.
    $mail->assertDontSeeInHtml("Limited data today — we'll have the full picture tomorrow.");
});

it('collapses a wholly-beyond-horizon trip into a single future line with no forecast rows', function () {
    // A trip starting 10 days out: nothing is in the 7-day horizon yet, so no
    // day rows render — just the full itinerary span as one calm line.
    $snapshot = fullForecastSnapshot([
        '2026-06-29', '2026-06-30', '2026-07-01', '2026-07-02',
        '2026-07-03', '2026-07-04', '2026-07-05',
    ]);
    $trip = digestTrip();
    $trip->update(['departure_date' => '2026-07-09', 'return_date' => '2026-07-12']);

    $mail = new DigestMail($trip->fresh(), $snapshot, '2026-06-29');

    expect(substr_count($mail->render(), '% precip'))->toBe(0);
    $mail->assertSeeInHtml('Jul 9–12');
    $mail->assertSeeInHtml('Forecast appears once these days are within 7 days');
    $mail->assertDontSeeInHtml("Limited data today — we'll have the full picture tomorrow.");
});

it('uses the singular phrasing when only one trip day is beyond the horizon', function () {
    // Horizon reaches 07-06; a trip 07-05…07-07 leaves exactly one pending day.
    $snapshot = fullForecastSnapshot([
        '2026-06-29', '2026-06-30', '2026-07-01', '2026-07-02',
        '2026-07-03', '2026-07-04', '2026-07-05', '2026-07-06',
    ]);
    $trip = digestTrip();
    $trip->update(['departure_date' => '2026-07-05', 'return_date' => '2026-07-07']);

    $mail = new DigestMail($trip->fresh(), $snapshot, '2026-06-29');

    $mail->assertSeeInHtml('Forecast appears once this day is within 7 days');
});

it('drives the future-line horizon number from config', function () {
    config(['tripcast.forecast.horizon_days' => 14]);

    $snapshot = fullForecastSnapshot([
        '2026-06-29', '2026-06-30', '2026-07-01', '2026-07-02',
        '2026-07-03', '2026-07-04', '2026-07-05', '2026-07-06',
    ]);
    $trip = digestTrip();
    $trip->update(['departure_date' => '2026-07-06', 'return_date' => '2026-07-10']);

    $mail = new DigestMail($trip->fresh(), $snapshot, '2026-06-29');

    $mail->assertSeeInHtml('Forecast appears once these days are within 14 days');
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

    // This snapshot carries no feels-like (older/partial) → the row omits it gracefully.
    $mail->assertDontSeeInHtml('feels like');
});

it('tags the departure-day row when it falls inside the forecast window', function () {
    // digestTrip departs 2026-07-04, which is inside the snapshot (06-29…07-05).
    $mail = new DigestMail(digestTrip(), digestSnapshot(), '2026-06-29');

    $mail->assertSeeInHtml('The start of your trip!');
    $mail->assertDontSeeInHtml('✈️'); // the airplane emoji is gone
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

// Story 9.1 (FR-26) — legal footer: absolute Privacy/Terms links in both twins.
it('renders absolute privacy and terms links in the footer HTML and text twin', function () {
    $mail = new DigestMail(digestTrip(), digestSnapshot(), '2026-06-29');

    $mail->assertSeeInHtml(route('privacy'), false);
    $mail->assertSeeInHtml(route('terms'), false);

    $mail->assertSeeInText('Privacy: '.route('privacy'));
    $mail->assertSeeInText('Terms: '.route('terms'));
});

// Story 2.5 added custom List-Unsubscribe one-click headers; Story 9.9
// (2026-07-02) removed them — custom headers are Professional/Enterprise-only
// on MailerSend and 422 every production send (#MS42235), while MailerSend
// manages its own List-Unsubscribe header on every plan. This guard pins the
// ABSENCE at the built-message level (what MailerSend actually sees): re-adding
// the headers on the current plan re-breaks the daily digest.
it('sets no custom List-Unsubscribe headers', function () {
    Mail::to('traveler@example.com')
        ->send(new DigestMail(digestTrip(), digestSnapshot(), '2026-06-29'));

    // phpunit.xml forces MAIL_MAILER=array: pull the actually-built message
    // off the array transport rather than asserting on mailable internals.
    $headers = Mail::mailer()->getSymfonyTransport()->messages()
        ->sole()
        ->getOriginalMessage()
        ->getHeaders();

    expect($headers->has('List-Unsubscribe'))->toBeFalse()
        ->and($headers->has('List-Unsubscribe-Post'))->toBeFalse();
});

// Story 10.1 — free-text feedback nudge: digests send from the hello@ address,
// so a plain reply reaches the team inbox.
it('renders the reply-to-us feedback nudge in HTML and the text twin', function () {
    $mail = new DigestMail(digestTrip(), digestSnapshot(), '2026-06-29');

    $mail->assertSeeInHtml("How's tripcast working? Have an idea? Simply reply to this email and tell us.", false);
    $mail->assertSeeInText("How's tripcast working? Have an idea? Simply reply to this email and tell us.");
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

// Story 4.2 — the narration line renders in both HTML and text when present.
it('renders the narration line in HTML and text when present', function () {
    $line = "Since yesterday, Friday's rain chance dropped from 60% to 20%.";
    $mail = new DigestMail(digestTrip(), digestSnapshot(), '2026-06-29', $line);

    $mail->assertSeeInHtml('Overview'); // the narration section title
    $mail->assertSeeInHtml($line);
    $mail->assertSeeInText('Overview');
    $mail->assertSeeInText($line);
});

// Story 4.2 — the slot is omitted entirely when there is no line.
it('omits the narration slot when null', function () {
    $mail = new DigestMail(digestTrip(), digestSnapshot(), '2026-06-29', null);

    expect($mail->render())->not->toContain('Since yesterday,');
});

// Story 5.3/5.4 — the promo unit + disclosure render; the link is the SIGNED
// redirect, never a raw affiliate URL in the body (FR-18).
it('renders the promo unit, the disclosure, and a signed redirect link (not a raw affiliate URL)', function () {
    $promo = new Promo('packing-cubes', 'Compression packing cubes', 'https://img.example/cubes.png', 'https://www.amazon.com/dp/X?tag=mytag-99');
    $mail = new DigestMail(digestTrip(), digestSnapshot(), '2026-06-29', null, $promo);

    config(['tripcast.promo.cta' => 'View price']);

    $mail->assertSeeInHtml('Sponsored');
    $mail->assertSeeInHtml('Compression packing cubes');
    $mail->assertSeeInHtml('View price'); // CTA link
    $mail->assertSeeInHtml('As an Amazon Associate, tripcast earns from qualifying purchases');
    $mail->assertSeeInText('Sponsored');
    $mail->assertSeeInText('Compression packing cubes');
    $mail->assertSeeInText('View price');
    $mail->assertSeeInText('As an Amazon Associate, tripcast earns from qualifying purchases');

    // The link is the signed promo.click redirect; the raw Amazon URL is absent.
    $html = $mail->render();
    expect($html)->toContain('email/promo/')
        ->and($html)->toContain('signature=')
        ->and($html)->not->toContain('amazon.com');
});

// Story 5.3 — no promo → no slot, no disclosure, subject unchanged.
it('omits the promo slot and disclosure when there is no promo', function () {
    $mail = new DigestMail(digestTrip(), digestSnapshot(), '2026-06-29', null, null);

    expect($mail->render())->not->toContain('As an Amazon Associate');
});

// Story 5.3 — the promo never appears in the subject line.
it('never puts the promo in the subject', function () {
    $promo = new Promo('packing-cubes', 'Compression packing cubes', 'https://img.example/cubes.png', 'https://www.amazon.com/dp/X?tag=t');
    $mail = new DigestMail(digestTrip(), digestSnapshot(), '2026-06-29', null, $promo);

    expect($mail->envelope()->subject)->not->toContain('packing cubes');
});
