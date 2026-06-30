<?php

namespace App\Services\Weather;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * WeatherAPI.com adapter (AD-1) — the only place the vendor HTTP contract
 * appears. Fetches fresh, by coordinates only (FR-11); maps each forecast day
 * faithfully and leaves missing fields null (limited, FR-7).
 */
class WeatherApiProvider implements WeatherProvider
{
    private const ENDPOINT = 'https://api.weatherapi.com/v1/forecast.json';

    public function __construct(private string $apiKey) {}

    public function fetchForecast(float $latitude, float $longitude): Forecast
    {
        try {
            $response = Http::timeout(10)->get(self::ENDPOINT, [
                'key' => $this->apiKey,
                'q' => $latitude.','.$longitude,
                // Today + the configured horizon, so a trip whose departure is
                // `horizon` days out (the first cadence day) already has its
                // departure-day forecast (FR-7, AD-11).
                'days' => (int) config('tripcast.forecast.horizon_days') + 1,
                'aqi' => 'no',
                'alerts' => 'no',
            ]);
        } catch (Throwable $e) {
            throw new WeatherProviderFailedException("Weather request failed for [{$latitude},{$longitude}].", 0, $e);
        }

        if ($response->failed()) {
            throw new WeatherProviderFailedException("Weather HTTP error [{$response->status()}].");
        }

        $data = $response->json();
        $forecastDays = $data['forecast']['forecastday'] ?? null;

        if (! is_array($forecastDays)) {
            throw new WeatherProviderFailedException("Weather response missing forecast for [{$latitude},{$longitude}].");
        }

        $days = [];

        foreach ($forecastDays as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $day = is_array($entry['day'] ?? null) ? $entry['day'] : [];

            $days[] = new ForecastDay(
                date: (string) ($entry['date'] ?? ''),
                conditionText: isset($day['condition']['text']) ? (string) $day['condition']['text'] : null,
                precipChance: isset($day['daily_chance_of_rain']) ? (int) $day['daily_chance_of_rain'] : null,
                highC: isset($day['maxtemp_c']) ? (float) $day['maxtemp_c'] : null,
                highF: isset($day['maxtemp_f']) ? (float) $day['maxtemp_f'] : null,
                lowC: isset($day['mintemp_c']) ? (float) $day['mintemp_c'] : null,
                lowF: isset($day['mintemp_f']) ? (float) $day['mintemp_f'] : null,
                humidity: isset($day['avghumidity']) ? (int) round((float) $day['avghumidity']) : null,
            );
        }

        return new Forecast($days);
    }
}
