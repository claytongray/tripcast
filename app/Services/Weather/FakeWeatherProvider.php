<?php

namespace App\Services\Weather;

/**
 * Deterministic weather provider for local dev (no key) and tests. A sentinel
 * latitude of 0.0 returns a partial forecast so the limited-data path (FR-7) is
 * exercisable without the real API.
 */
class FakeWeatherProvider implements WeatherProvider
{
    public function fetchForecast(float $latitude, float $longitude): Forecast
    {
        if ($latitude === 0.0) {
            return new Forecast([
                new ForecastDay('2026-07-01', 'Sunny', 0, 20.0, 68.0, 12.0, 53.6, 55),
                new ForecastDay('2026-07-02'), // limited day — values null, not fabricated
                new ForecastDay('2026-07-03', 'Cloudy', 10, 18.0, 64.4, 11.0, 51.8, 70),
            ]);
        }

        $days = [];

        for ($i = 0; $i < 7; $i++) {
            $days[] = new ForecastDay(
                date: sprintf('2026-07-%02d', $i + 1),
                conditionText: 'Partly cloudy',
                precipChance: 20,
                highC: 18.0,
                highF: 64.4,
                lowC: 11.0,
                lowF: 51.8,
                humidity: 62,
            );
        }

        return new Forecast($days);
    }
}
