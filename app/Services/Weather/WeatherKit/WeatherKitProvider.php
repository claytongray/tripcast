<?php

namespace App\Services\Weather\WeatherKit;

use App\Services\Weather\DestinationTimezone;
use App\Services\Weather\Forecast;
use App\Services\Weather\ForecastDay;
use App\Services\Weather\WeatherProvider;
use App\Services\Weather\WeatherProviderFailedException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Apple WeatherKit adapter (AD-1) — the only place its HTTP/JSON contract
 * appears. WeatherKit is metric-only, so highs/lows convert °C→°F and the 0–1
 * `precipitationChance`/`humidity` scale to int percent. Feels-like is the peak
 * hourly `temperatureApparent`, mirroring the WeatherAPI adapter. Missing values
 * stay null (limited), never fabricated (FR-7).
 */
class WeatherKitProvider implements WeatherProvider
{
    private const BASE = 'https://weatherkit.apple.com/api/v1/weather/en';

    public function __construct(
        private WeatherKitToken $token,
        private DestinationTimezone $timezones,
    ) {}

    public function fetchForecast(float $latitude, float $longitude, ?string $timezone = null): Forecast
    {
        // `?:` (not `??`) so a blank passed/resolved/config zone can't slip
        // through and crash setTimezone(); UTC is the last-ditch guard.
        $zone = $timezone
            ?: ($this->timezones->resolve($latitude, $longitude)
            ?: (config('tripcast.forecast.default_timezone') ?: 'UTC'));

        try {
            $response = Http::withToken($this->token->bearer())
                ->timeout(10)
                ->get(self::BASE."/{$latitude}/{$longitude}", [
                    'dataSets' => 'forecastDaily,forecastHourly',
                    'timezone' => $zone,
                ]);
        } catch (Throwable $e) {
            throw new WeatherProviderFailedException("WeatherKit request failed for [{$latitude},{$longitude}].", 0, $e);
        }

        if ($response->failed()) {
            throw new WeatherProviderFailedException("WeatherKit HTTP error [{$response->status()}].");
        }

        $data = $response->json();
        $days = $data['forecastDaily']['days'] ?? null;

        if (! is_array($days)) {
            throw new WeatherProviderFailedException("WeatherKit response missing forecastDaily for [{$latitude},{$longitude}].");
        }

        try {
            return $this->mapDays($days, $data['forecastHourly']['hours'] ?? [], $zone);
        } catch (Throwable $e) {
            // A malformed `forecastStart` or an unusable zone must surface as the
            // port's own exception (AD-1) — SendTripDigest catches only this one,
            // so a raw Carbon exception would kill the job and strand the send.
            throw new WeatherProviderFailedException("WeatherKit response could not be mapped for [{$latitude},{$longitude}].", 0, $e);
        }
    }

    /**
     * Map WeatherKit days/hours to the DTO. Temps are stored as raw floats in
     * both units, exactly like the WeatherAPI adapter — the renderer rounds for
     * display and NarrationDiffer compares the stored values. Only the integer
     * percentages (precip, humidity) are rounded here.
     *
     * @param  array<int, mixed>  $days
     * @param  array<int, mixed>  $hours
     */
    private function mapDays(array $days, array $hours, string $zone): Forecast
    {
        $apparentPeaks = $this->peakApparentByDate($hours, $zone);
        $mapped = [];

        foreach ($days as $day) {
            if (! is_array($day) || empty($day['forecastStart'])) {
                continue;
            }

            $date = CarbonImmutable::parse($day['forecastStart'])->setTimezone($zone)->toDateString();
            $maxC = isset($day['temperatureMax']) ? (float) $day['temperatureMax'] : null;
            $minC = isset($day['temperatureMin']) ? (float) $day['temperatureMin'] : null;
            $peakC = $apparentPeaks[$date] ?? null;
            $humidity = $day['daytimeForecast']['humidity'] ?? null;

            // A present-but-blank conditionCode must read as a missing core value
            // (limited), not a "complete" day with empty text (FR-7).
            $label = isset($day['conditionCode']) ? ConditionCode::label((string) $day['conditionCode']) : null;

            $mapped[] = new ForecastDay(
                date: $date,
                conditionText: ($label === null || $label === '') ? null : $label,
                precipChance: isset($day['precipitationChance']) ? (int) round($day['precipitationChance'] * 100) : null,
                highC: $maxC,
                highF: $maxC !== null ? $this->toF($maxC) : null,
                lowC: $minC,
                lowF: $minC !== null ? $this->toF($minC) : null,
                humidity: $humidity !== null ? (int) round($humidity * 100) : null,
                feelsLikeHighC: $peakC,
                feelsLikeHighF: $peakC !== null ? $this->toF($peakC) : null,
            );
        }

        // Match the WeatherAPI adapter's day count (today + horizon); WeatherKit
        // returns a longer superset by default, which would bloat the snapshot.
        return new Forecast(array_slice($mapped, 0, (int) config('tripcast.forecast.horizon_days') + 1));
    }

    /**
     * Highest hourly `temperatureApparent` (°C) per destination-local date.
     *
     * @param  array<int, mixed>  $hours
     * @return array<string, float>
     */
    private function peakApparentByDate(array $hours, string $zone): array
    {
        $peaks = [];

        foreach ($hours as $hour) {
            if (! is_array($hour) || ! isset($hour['temperatureApparent'], $hour['forecastStart'])) {
                continue;
            }

            $date = CarbonImmutable::parse($hour['forecastStart'])->setTimezone($zone)->toDateString();
            $apparent = (float) $hour['temperatureApparent'];

            if (! isset($peaks[$date]) || $apparent > $peaks[$date]) {
                $peaks[$date] = $apparent;
            }
        }

        return $peaks;
    }

    private function toF(float $celsius): float
    {
        return $celsius * 9 / 5 + 32;
    }
}
