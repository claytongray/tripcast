<?php

use App\Services\Sample\SampleForecast;
use App\Services\Weather\Forecast;
use App\Services\Weather\ForecastDay;
use App\Services\Weather\WeatherProvider;
use App\Services\Weather\WeatherProviderFailedException;
use Illuminate\Support\Carbon;

afterEach(fn () => Carbon::setTestNow());

it('caches the live forecast per day (a second call does not re-fetch)', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-30 09:00', 'America/New_York'));

    $calls = 0;
    $this->mock(WeatherProvider::class, function ($mock) use (&$calls) {
        $mock->shouldReceive('fetchForecast')->andReturnUsing(function () use (&$calls) {
            $calls++;

            return new Forecast([new ForecastDay(date: '2026-06-30', conditionText: 'Sunny', precipChance: 5, highC: 10.0, highF: 50.0, lowC: 4.0, lowF: 39.0)]);
        });
    });

    $service = app(SampleForecast::class);
    $service->forecast();
    $service->forecast();

    expect($calls)->toBe(1);
});

it('falls back to a synthetic forecast when the provider fails, without caching it', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-30 09:00', 'America/New_York'));

    $calls = 0;
    $this->mock(WeatherProvider::class, function ($mock) use (&$calls) {
        $mock->shouldReceive('fetchForecast')->andReturnUsing(function () use (&$calls) {
            $calls++;
            throw new WeatherProviderFailedException('down');
        });
    });

    $service = app(SampleForecast::class);

    $first = $service->forecast();
    $second = $service->forecast();

    expect($first->days)->not->toBeEmpty()
        ->and($calls)->toBe(2); // fallback not cached → retried live
});

// Story 9.5 (FR-25) — the synthetic fallback spans the live fetch's shape
// (today..today+7) so a provider outage still renders the full 7-day window.
it('spans the fallback across the full forecast reach', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-30 09:00', 'America/New_York'));

    $this->mock(WeatherProvider::class, function ($mock) {
        $mock->shouldReceive('fetchForecast')->andThrow(new WeatherProviderFailedException('down'));
    });

    $forecast = app(SampleForecast::class)->forecast();

    expect($forecast->days)->toHaveCount(8)
        ->and($forecast->days[0]->date)->toBe('2026-06-30')
        ->and($forecast->days[7]->date)->toBe('2026-07-07')
        ->and($forecast->isLimited())->toBeFalse();
});
