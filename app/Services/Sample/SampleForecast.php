<?php

namespace App\Services\Sample;

use App\Services\Weather\Forecast;
use App\Services\Weather\ForecastDay;
use App\Services\Weather\WeatherProvider;
use App\Services\Weather\WeatherProviderFailedException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The forecast behind the public sample tripcast: the configured demo city's
 * real forecast, fetched live once per America/New_York day and cached. If the
 * provider is down on a cold cache, a baked-in synthetic forecast is returned
 * (and not cached, so the next request retries live) — the public sample never
 * shows broken or empty weather.
 */
class SampleForecast
{
    public function __construct(private WeatherProvider $weather) {}

    public function forecast(): Forecast
    {
        $destination = config('tripcast.sample.destination');
        $today = CarbonImmutable::now('America/New_York');
        $key = "sample-forecast:{$destination['key']}:{$today->toDateString()}";

        try {
            return Cache::remember(
                $key,
                $today->endOfDay(),
                fn (): Forecast => $this->weather->fetchForecast(
                    (float) $destination['latitude'],
                    (float) $destination['longitude'],
                ),
            );
        } catch (WeatherProviderFailedException) {
            return $this->fallback($today);
        }
    }

    /**
     * A calm, pleasant synthetic forecast spanning today..today+7 — the same
     * shape as the live fetch — so an outage still covers the full sample trip
     * window (tomorrow..tomorrow+6, FR-25) and never reads as limited data.
     * Both units provided.
     */
    private function fallback(CarbonImmutable $today): Forecast
    {
        $days = [];

        for ($offset = 0; $offset <= 7; $offset++) {
            $days[] = new ForecastDay(
                date: $today->addDays($offset)->toDateString(),
                conditionText: 'Partly cloudy',
                precipChance: 20,
                highC: 9.0,
                highF: 48.0,
                lowC: 3.0,
                lowF: 37.0,
                humidity: 70,
                feelsLikeHighC: 7.0,
                feelsLikeHighF: 45.0,
            );
        }

        return new Forecast($days);
    }
}
