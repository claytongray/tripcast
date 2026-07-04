<?php

use App\Services\Weather\WeatherKit\WeatherKitProvider;
use App\Services\Weather\WeatherKit\WeatherKitToken;
use App\Services\Weather\WeatherProviderFailedException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Bind the token with a throwaway EC key so the provider auto-resolves
    // without real credentials (the container wires DestinationTimezone itself).
    $res = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    openssl_pkey_export($res, $pem);
    app()->bind(WeatherKitToken::class, fn () => new WeatherKitToken('T', 'S', 'K', $pem));
});

function fakeWeatherKit(string $fixture = 'kennett'): void
{
    Http::fake([
        'weatherkit.apple.com/*' => Http::response(
            json_decode(file_get_contents(base_path("tests/Fixtures/weatherkit/{$fixture}.json")), true)
        ),
    ]);
}

it('maps the daily high to air-temp Fahrenheit (not heat index)', function () {
    fakeWeatherKit();

    $forecast = app(WeatherKitProvider::class)->fetchForecast(39.8467, -75.7116, 'America/New_York');
    $day = $forecast->days[0];

    expect($day->date)->toBe('2026-07-04')
        ->and((int) round($day->highF))->toBe(97)          // 36.23°C → 97°F, not the old 105
        ->and((int) round($day->lowF))->toBe(74)           // 23.41°C → 74°F
        ->and($day->precipChance)->toBe(52)                // 0.52 → 52%
        ->and($day->humidity)->toBe(51)                    // daytimeForecast.humidity 0.51 → 51%
        ->and($day->conditionText)->toBe('Thunderstorms')
        ->and((int) round($day->feelsLikeHighF))->toBe(100) // peak temperatureApparent 37.9°C → 100°F
        ->and($day->isLimited())->toBeFalse();
});

it('sends the bearer token and timezone param', function () {
    fakeWeatherKit();

    app(WeatherKitProvider::class)->fetchForecast(39.8467, -75.7116, 'America/New_York');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'weatherkit.apple.com/api/v1/weather/en/39.8467/-75.7116')
            && str_contains($request->url(), 'timezone=America%2FNew_York')
            && $request->hasHeader('Authorization');
    });
});

it('derives the local date from the IANA zone, incl. no-DST zones like Phoenix', function () {
    fakeWeatherKit(); // forecastStart 2026-07-04T04:00:00Z (a UTC instant)

    // Same UTC instant, different IANA zones → different local dates. Proves the
    // date is computed from the passed zone (tzdata DST rules applied), not a
    // hardcoded offset. America/Phoenix is UTC-7 year-round (no DST) and flows
    // through cleanly — WeatherKit + Carbon honor it.
    $ny = app(WeatherKitProvider::class)->fetchForecast(39.8467, -75.7116, 'America/New_York')->days[0]->date;
    $phx = app(WeatherKitProvider::class)->fetchForecast(33.4484, -112.0740, 'America/Phoenix')->days[0]->date;

    expect($ny)->toBe('2026-07-04')       // 04:00Z in ET (−04) = Jul 4 00:00
        ->and($phx)->toBe('2026-07-03');  // 04:00Z in MST (−07) = Jul 3 21:00
});

it('throws WeatherProviderFailedException on an HTTP error', function () {
    Http::fake(['weatherkit.apple.com/*' => Http::response('nope', 401)]);

    app(WeatherKitProvider::class)->fetchForecast(39.8467, -75.7116, 'America/New_York');
})->throws(WeatherProviderFailedException::class);
