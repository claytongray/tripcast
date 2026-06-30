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
            [$feelsLikeHighC, $feelsLikeHighF] = $this->peakFeelsLike($entry);

            $days[] = new ForecastDay(
                date: (string) ($entry['date'] ?? ''),
                conditionText: isset($day['condition']['text']) ? (string) $day['condition']['text'] : null,
                precipChance: isset($day['daily_chance_of_rain']) ? (int) $day['daily_chance_of_rain'] : null,
                highC: isset($day['maxtemp_c']) ? (float) $day['maxtemp_c'] : null,
                highF: isset($day['maxtemp_f']) ? (float) $day['maxtemp_f'] : null,
                lowC: isset($day['mintemp_c']) ? (float) $day['mintemp_c'] : null,
                lowF: isset($day['mintemp_f']) ? (float) $day['mintemp_f'] : null,
                humidity: isset($day['avghumidity']) ? (int) round((float) $day['avghumidity']) : null,
                feelsLikeHighC: $feelsLikeHighC,
                feelsLikeHighF: $feelsLikeHighF,
            );
        }

        return new Forecast($days);
    }

    /**
     * The apparent temperature at the day's peak, read from the hourly array:
     * the hour with the highest `feelslike_f`, returned in both units from that
     * same hour. Null when no hour carries a feels-like (older/partial payloads)
     * — optional enrichment, so a miss never makes the day limited (FR-7).
     *
     * @param  array<string, mixed>  $entry
     * @return array{0: ?float, 1: ?float}
     */
    private function peakFeelsLike(array $entry): array
    {
        $hours = is_array($entry['hour'] ?? null) ? $entry['hour'] : [];

        $peakC = null;
        $peakF = null;

        foreach ($hours as $hour) {
            if (! is_array($hour) || ! isset($hour['feelslike_f'])) {
                continue;
            }

            $feelsLikeF = (float) $hour['feelslike_f'];

            if ($peakF === null || $feelsLikeF > $peakF) {
                $peakF = $feelsLikeF;
                $peakC = isset($hour['feelslike_c']) ? (float) $hour['feelslike_c'] : null;
            }
        }

        return [$peakC, $peakF];
    }
}
