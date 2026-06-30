<?php

use App\Services\Weather\Forecast;
use App\Services\Weather\WeatherApiProvider;
use App\Services\Weather\WeatherProviderFailedException;
use Illuminate\Support\Facades\Http;

/**
 * @param  list<array{date: string}>  $days
 */
function weatherPayload(array $days): array
{
    return ['forecast' => ['forecastday' => $days]];
}

function fullDay(string $date): array
{
    return [
        'date' => $date,
        'day' => [
            'maxtemp_c' => 17.0,
            'maxtemp_f' => 62.6,
            'mintemp_c' => 9.0,
            'mintemp_f' => 48.2,
            'daily_chance_of_rain' => 40,
            'condition' => ['text' => 'Light rain', 'code' => 1183],
        ],
    ];
}

// AC1 — a full payload maps to a faithful 7-day forecast, requested by coordinates.
it('maps a full WeatherAPI payload to a forecast by coordinates', function () {
    $days = collect(range(1, 7))
        ->map(fn (int $d) => fullDay(sprintf('2026-07-%02d', $d)))
        ->all();

    Http::fake(['*' => Http::response(weatherPayload($days))]);

    $forecast = (new WeatherApiProvider('test-key'))->fetchForecast(55.9533, -3.1883);

    expect($forecast)->toBeInstanceOf(Forecast::class)
        ->and($forecast->days)->toHaveCount(7)
        ->and($forecast->isLimited())->toBeFalse();

    $first = $forecast->days[0];
    expect($first->date)->toBe('2026-07-01')
        ->and($first->highC)->toBe(17.0)
        ->and($first->highF)->toBe(62.6)
        ->and($first->lowC)->toBe(9.0)
        ->and($first->lowF)->toBe(48.2)
        ->and($first->precipChance)->toBe(40)
        ->and($first->conditionText)->toBe('Light rain');

    // Fetches today + the API horizon (so the departure day is reachable on the
    // first cadence day, which opens `horizon` days before departure).
    Http::assertSent(fn ($request) => str_contains($request->url(), 'forecast.json')
        && $request['q'] === '55.9533,-3.1883'
        && (int) $request['days'] === config('tripcast.forecast.horizon_days') + 1);
});

// AC2 — fewer days marks the forecast limited (no fabrication).
it('marks a short forecast as limited', function () {
    $days = [fullDay('2026-07-01'), fullDay('2026-07-02'), fullDay('2026-07-03')];

    Http::fake(['*' => Http::response(weatherPayload($days))]);

    $forecast = (new WeatherApiProvider('test-key'))->fetchForecast(1.0, 2.0);

    expect($forecast->days)->toHaveCount(3)
        ->and($forecast->isLimited())->toBeTrue();
});

// AC2 — a day missing values is limited with null values, not invented.
it('leaves a day with missing values null and limited', function () {
    $days = collect(range(1, 7))->map(fn (int $d) => fullDay(sprintf('2026-07-%02d', $d)))->all();
    unset($days[3]['day']['maxtemp_c'], $days[3]['day']['maxtemp_f']); // day 4 partial

    Http::fake(['*' => Http::response(weatherPayload($days))]);

    $forecast = (new WeatherApiProvider('test-key'))->fetchForecast(1.0, 2.0);

    expect($forecast->isLimited())->toBeTrue()
        ->and($forecast->days[3]->highC)->toBeNull()
        ->and($forecast->days[3]->isLimited())->toBeTrue()
        ->and($forecast->days[0]->isLimited())->toBeFalse();
});

// AC3 — non-200 becomes a typed failure (vendor error not leaked).
it('throws on an HTTP error', function () {
    Http::fake(['*' => Http::response('nope', 500)]);

    (new WeatherApiProvider('test-key'))->fetchForecast(1.0, 2.0);
})->throws(WeatherProviderFailedException::class);

// AC3 — a body without forecast.forecastday becomes a typed failure.
it('throws when the response has no forecast', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'bad key']], 200)]);

    (new WeatherApiProvider('test-key'))->fetchForecast(1.0, 2.0);
})->throws(WeatherProviderFailedException::class);
