<?php

use App\Mail\SampleDigestMail;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Carbon;

afterEach(fn () => Carbon::setTestNow());

function sampleTrip(): Trip
{
    Carbon::setTestNow(Carbon::parse('2026-06-30 09:00', 'America/New_York'));

    $user = new User(['email' => 'sampler@example.com', 'temperature_unit' => User::UNIT_FAHRENHEIT]);
    $user->id = 90001;
    $user->plan = User::PLAN_FREE;

    $trip = new Trip([
        'destination_raw' => 'Reykjavik, Iceland',
        'canonical_place_name' => 'Reykjavik, Iceland',
        'latitude' => 64.1466,
        'longitude' => -21.9426,
        'departure_date' => '2026-07-01',
        'return_date' => '2026-07-07',
        'status' => Trip::STATUS_ACTIVE,
    ]);
    $trip->id = 80001;
    $trip->setRelation('user', $user);

    return $trip;
}

function sampleSnapshot(): array
{
    return ['days' => [
        ['date' => '2026-07-01', 'conditionText' => 'Cloudy', 'precipChance' => 30, 'highC' => 9.0, 'highF' => 48.0, 'lowC' => 3.0, 'lowF' => 37.0, 'humidity' => 70, 'feelsLikeHighC' => 7.0, 'feelsLikeHighF' => 45.0],
        ['date' => '2026-07-02', 'conditionText' => 'Sunny', 'precipChance' => 10, 'highC' => 11.0, 'highF' => 52.0, 'lowC' => 4.0, 'lowF' => 39.0, 'humidity' => 60, 'feelsLikeHighC' => 11.0, 'feelsLikeHighF' => 52.0],
        ['date' => '2026-07-03', 'conditionText' => 'Rain', 'precipChance' => 80, 'highC' => 8.0, 'highF' => 46.0, 'lowC' => 3.0, 'lowF' => 37.0, 'humidity' => 85, 'feelsLikeHighC' => 6.0, 'feelsLikeHighF' => 43.0],
        ['date' => '2026-07-04', 'conditionText' => 'Overcast', 'precipChance' => 40, 'highC' => 10.0, 'highF' => 50.0, 'lowC' => 4.0, 'lowF' => 39.0, 'humidity' => 75, 'feelsLikeHighC' => 9.0, 'feelsLikeHighF' => 48.0],
        ['date' => '2026-07-05', 'conditionText' => 'Sunny', 'precipChance' => 5, 'highC' => 12.0, 'highF' => 54.0, 'lowC' => 5.0, 'lowF' => 41.0, 'humidity' => 55, 'feelsLikeHighC' => 12.0, 'feelsLikeHighF' => 54.0],
        ['date' => '2026-07-06', 'conditionText' => 'Partly cloudy', 'precipChance' => 15, 'highC' => 11.0, 'highF' => 52.0, 'lowC' => 4.0, 'lowF' => 39.0, 'humidity' => 65, 'feelsLikeHighC' => 10.0, 'feelsLikeHighF' => 50.0],
        ['date' => '2026-07-07', 'conditionText' => 'Light rain', 'precipChance' => 60, 'highC' => 9.0, 'highF' => 48.0, 'lowC' => 3.0, 'lowF' => 37.0, 'humidity' => 80, 'feelsLikeHighC' => 8.0, 'feelsLikeHighF' => 46.0],
    ]];
}

// Story 9.5 (FR-25) — the sample shows the product at full strength.
it('renders seven forecast day-rows', function () {
    $mail = new SampleDigestMail(sampleTrip(), sampleSnapshot(), 'https://tripcast.test/auth/magic/abc123');

    expect(substr_count($mail->render(), '% precip'))->toBe(7);
});

it('renders the Get started CTA with the magic-link url', function () {
    $mail = new SampleDigestMail(sampleTrip(), sampleSnapshot(), 'https://tripcast.test/auth/magic/abc123');

    $mail->assertSeeInHtml('Get started');
    $mail->assertSeeInHtml('https://tripcast.test/auth/magic/abc123');
    $mail->assertSeeInHtml('Reykjavik, Iceland');
});

it('omits unsubscribe and feedback (a sample is not a subscription)', function () {
    $mail = new SampleDigestMail(sampleTrip(), sampleSnapshot(), 'https://tripcast.test/auth/magic/abc123');

    $mail->assertDontSeeInHtml('Unsubscribe');
    $mail->assertDontSeeInHtml('unsubscribe');
});

// Story 9.1 (FR-26) — the sample carries the legal footer (Privacy/Terms +
// postal address) in both twins; still no unsubscribe/feedback/promo.
it('renders privacy and terms links and the postal address in the footer', function () {
    config(['tripcast.postal_address' => 'Tripcast, 123 Main St, Anytown']);
    $mail = new SampleDigestMail(sampleTrip(), sampleSnapshot(), 'https://tripcast.test/auth/magic/abc123');

    $mail->assertSeeInHtml(route('privacy'), false);
    $mail->assertSeeInHtml(route('terms'), false);
    $mail->assertSeeInHtml('Tripcast, 123 Main St, Anytown');

    $mail->assertSeeInText('Privacy: '.route('privacy'));
    $mail->assertSeeInText('Terms: '.route('terms'));
    $mail->assertSeeInText('Tripcast, 123 Main St, Anytown');
});
