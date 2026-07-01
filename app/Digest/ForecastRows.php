<?php

namespace App\Digest;

use Carbon\CarbonImmutable;

/**
 * Projects a stored weather snapshot into render-ready day rows for the trip's
 * own window [departure, return], in a single temperature unit. Shared by the
 * daily digest and the public sample so both render identically (FR-7): a day
 * missing any core value is `limited` (calm marker, never fabricated); humidity
 * and feels-like show only when the feels-like delta makes them meaningful.
 */
class ForecastRows
{
    /**
     * @param  array{days: list<array<string, mixed>>}  $snapshot
     * @return list<array{label: string, limited: bool, isDeparture: bool, conditionText: ?string, emoji: string, precipChance: ?int, high: ?int, low: ?int, humidity: ?int, feelsLike: ?int}>
     */
    public function project(array $snapshot, string $departureDate, string $returnDate, bool $celsius): array
    {
        $tripDays = array_values(array_filter(
            $snapshot['days'],
            fn (array $day): bool => $day['date'] >= $departureDate && $day['date'] <= $returnDate,
        ));

        return array_map(function (array $day) use ($celsius, $departureDate): array {
            $limited = $day['conditionText'] === null
                || ($day['precipChance'] ?? null) === null
                || ($day['highF'] ?? null) === null
                || ($day['highC'] ?? null) === null
                || ($day['lowF'] ?? null) === null
                || ($day['lowC'] ?? null) === null;

            $high = $celsius ? ($day['highC'] ?? null) : ($day['highF'] ?? null);
            $low = $celsius ? ($day['lowC'] ?? null) : ($day['lowF'] ?? null);
            $feelsLikeHigh = $celsius ? ($day['feelsLikeHighC'] ?? null) : ($day['feelsLikeHighF'] ?? null);

            $highInt = $limited ? null : (int) round((float) $high);
            $feelsLike = $limited || $feelsLikeHigh === null ? null : (int) round((float) $feelsLikeHigh);

            // Humidity earns its place in the row only when it's "doing work":
            // when the peak feels-like pulls away from the high by enough to
            // notice (≥5°F, ~3°C). When they're close, or there's no feels-like to
            // compare (older snapshot), the humidity figure is just noise — except
            // we keep showing it on those older snapshots to avoid losing data.
            $humidityThreshold = $celsius ? 3 : 5;
            $showHumidity = ! $limited
                && ($day['humidity'] ?? null) !== null
                && ($feelsLike === null || abs($highInt - $feelsLike) >= $humidityThreshold);

            return [
                // Destination-local calendar date exactly as stored (AD-7); naive
                // date string, so no timezone is applied.
                'label' => CarbonImmutable::parse($day['date'])->format('D M j'),
                'limited' => $limited,
                'isDeparture' => $day['date'] === $departureDate,
                'conditionText' => $day['conditionText'] ?? null,
                'emoji' => $limited ? '' : WeatherEmoji::for($day['conditionText'] ?? null),
                'precipChance' => $limited ? null : (int) $day['precipChance'],
                'high' => $highInt,
                'low' => $limited ? null : (int) round((float) $low),
                'humidity' => $showHumidity ? (int) $day['humidity'] : null,
                'feelsLike' => $feelsLike,
            ];
        }, $tripDays);
    }
}
