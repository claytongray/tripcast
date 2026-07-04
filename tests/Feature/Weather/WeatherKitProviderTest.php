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

    // A key so the DestinationTimezone resolver reaches the faked HTTP (the
    // resolver short-circuits to null when no key is configured).
    config()->set('services.google.geocoding_key', 'test-key');
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

it('sends a Bearer token and the timezone param', function () {
    fakeWeatherKit();

    app(WeatherKitProvider::class)->fetchForecast(39.8467, -75.7116, 'America/New_York');

    Http::assertSent(function ($request) {
        $auth = $request->header('Authorization')[0] ?? '';

        return str_contains($request->url(), 'weatherkit.apple.com/api/v1/weather/en/39.8467/-75.7116')
            && str_contains($request->url(), 'timezone=America%2FNew_York')
            && str_starts_with($auth, 'Bearer ')
            && substr_count(substr($auth, 7), '.') === 2; // header.payload.signature
    });
});

it('resolves the timezone via the resolver when the caller passes none', function () {
    Http::fake([
        'maps.googleapis.com/*' => Http::response(['status' => 'OK', 'timeZoneId' => 'America/Los_Angeles']),
        'weatherkit.apple.com/*' => Http::response(
            json_decode(file_get_contents(base_path('tests/Fixtures/weatherkit/kennett.json')), true)
        ),
    ]);

    app(WeatherKitProvider::class)->fetchForecast(34.0522, -118.2437); // no timezone passed

    Http::assertSent(fn ($request) => str_contains($request->url(), 'weatherkit.apple.com')
        && str_contains($request->url(), 'timezone=America%2FLos_Angeles'));
});

it('handles a real no-DST (Phoenix) payload — local date and feels-like align', function () {
    fakeWeatherKit('phoenix'); // day forecastStart 07:00Z = Phoenix (−07) midnight; hours Phoenix-local

    $day = app(WeatherKitProvider::class)->fetchForecast(33.4484, -112.0740, 'America/Phoenix')->days[0];

    expect($day->date)->toBe('2026-07-04')                  // 07:00Z in MST = Jul 4 00:00
        ->and((int) round($day->highF))->toBe(108)          // 42.0°C
        ->and($day->feelsLikeHighF)->not->toBeNull()        // hours bucket under the same local date
        ->and((int) round($day->feelsLikeHighF))->toBe(109); // 43.0°C
});

it('derives the local date from the passed IANA zone, not a fixed offset', function () {
    fakeWeatherKit(); // kennett forecastStart 2026-07-04T04:00:00Z (a UTC instant)

    // Same UTC instant, different IANA zones → different local dates.
    $ny = app(WeatherKitProvider::class)->fetchForecast(39.8467, -75.7116, 'America/New_York')->days[0]->date;
    $phx = app(WeatherKitProvider::class)->fetchForecast(33.4484, -112.0740, 'America/Phoenix')->days[0]->date;

    expect($ny)->toBe('2026-07-04')       // 04:00Z in ET (−04) = Jul 4 00:00
        ->and($phx)->toBe('2026-07-03');  // 04:00Z in MST (−07) = Jul 3 21:00
});

it('marks a day limited (never fabricated) when core values are missing or blank', function () {
    Http::fake(['weatherkit.apple.com/*' => Http::response([
        'forecastDaily' => ['days' => [[
            'forecastStart' => '2026-07-04T04:00:00Z',
            'conditionCode' => '   ',        // blank → must read as null, not "complete"
            'temperatureMin' => 20.0,
            'precipitationChance' => 0.3,
            // temperatureMax intentionally absent → high null → limited
        ]]],
        'forecastHourly' => ['hours' => []],
    ])]);

    $day = app(WeatherKitProvider::class)->fetchForecast(39.8467, -75.7116, 'America/New_York')->days[0];

    expect($day->isLimited())->toBeTrue()
        ->and($day->conditionText)->toBeNull()
        ->and($day->highF)->toBeNull();
});

it('slices to horizon+1 days (keeping the earliest) with feels-like across days', function () {
    $days = [];
    $hours = [];
    for ($i = 0; $i < 10; $i++) {
        $d = sprintf('2026-07-%02d', 4 + $i);
        $days[] = [
            'forecastStart' => "{$d}T04:00:00Z", 'conditionCode' => 'Clear',
            'temperatureMax' => 30.0 + $i, 'temperatureMin' => 20.0,
            'precipitationChance' => 0.1, 'daytimeForecast' => ['humidity' => 0.5],
        ];
        $hours[] = ['forecastStart' => "{$d}T18:00:00Z", 'temperatureApparent' => 31.0 + $i]; // 14:00 ET
    }
    // Reverse to prove the sort keeps the earliest days regardless of payload order.
    Http::fake(['weatherkit.apple.com/*' => Http::response([
        'forecastDaily' => ['days' => array_reverse($days)],
        'forecastHourly' => ['hours' => $hours],
    ])]);

    $forecast = app(WeatherKitProvider::class)->fetchForecast(39.8467, -75.7116, 'America/New_York');

    expect($forecast->days)->toHaveCount(config('tripcast.forecast.horizon_days') + 1) // 8
        ->and($forecast->days[0]->date)->toBe('2026-07-04')            // earliest kept, not the last
        ->and($forecast->days[5]->feelsLikeHighF)->not->toBeNull();     // multi-day feels-like populated
});

it('falls back to the config-default timezone when the resolver returns null', function () {
    config()->set('tripcast.forecast.default_timezone', 'America/New_York');
    Http::fake([
        'maps.googleapis.com/*' => Http::response(['status' => 'ZERO_RESULTS']), // resolver → null
        'weatherkit.apple.com/*' => Http::response(
            json_decode(file_get_contents(base_path('tests/Fixtures/weatherkit/kennett.json')), true)
        ),
    ]);

    app(WeatherKitProvider::class)->fetchForecast(0.0, 0.0); // no timezone → resolve() null → config default

    Http::assertSent(fn ($request) => str_contains($request->url(), 'weatherkit.apple.com')
        && str_contains($request->url(), 'timezone=America%2FNew_York'));
});

it('skips a malformed hourly timestamp instead of failing the whole fetch', function () {
    Http::fake(['weatherkit.apple.com/*' => Http::response([
        'forecastDaily' => ['days' => [[
            'forecastStart' => '2026-07-04T04:00:00Z', 'conditionCode' => 'Clear',
            'temperatureMax' => 30.0, 'temperatureMin' => 20.0, 'precipitationChance' => 0.1,
        ]]],
        'forecastHourly' => ['hours' => [
            ['forecastStart' => 'garbage', 'temperatureApparent' => 99.0], // must be skipped, not fatal
            ['forecastStart' => '2026-07-04T18:00:00Z', 'temperatureApparent' => 33.0],
        ]],
    ])]);

    $day = app(WeatherKitProvider::class)->fetchForecast(39.8467, -75.7116, 'America/New_York')->days[0];

    expect($day->isLimited())->toBeFalse()
        ->and((int) round($day->feelsLikeHighF))->toBe(91); // 33.0°C, not the 99.0 garbage-hour
});

it('throws WeatherProviderFailedException on an HTTP error', function () {
    Http::fake(['weatherkit.apple.com/*' => Http::response('nope', 401)]);

    app(WeatherKitProvider::class)->fetchForecast(39.8467, -75.7116, 'America/New_York');
})->throws(WeatherProviderFailedException::class);

it('wraps a malformed forecastStart as WeatherProviderFailedException, not a raw Carbon error', function () {
    Http::fake(['weatherkit.apple.com/*' => Http::response([
        'forecastDaily' => ['days' => [['forecastStart' => 'not-a-date', 'temperatureMax' => 30.0]]],
        'forecastHourly' => ['hours' => []],
    ])]);

    app(WeatherKitProvider::class)->fetchForecast(39.8467, -75.7116, 'America/New_York');
})->throws(WeatherProviderFailedException::class);
