<?php

namespace App\Services\Weather;

/**
 * One day of a forecast (AD-7), keyed to the destination's local calendar date
 * exactly as the provider returns it. Temperatures are carried in both units as
 * provided — conversion is a render concern. Missing values are null (limited),
 * never fabricated (FR-7).
 */
final class ForecastDay
{
    public function __construct(
        public string $date,        // Y-m-d, destination-local
        public ?string $conditionText = null,
        public ?int $precipChance = null,   // percent
        public ?float $highC = null,
        public ?float $highF = null,
        public ?float $lowC = null,
        public ?float $lowF = null,
        public ?int $humidity = null,       // percent — optional enrichment, not a core/limited value
        // Apparent temperature at the day's peak (max feels-like across the
        // hourly array). Optional enrichment in both units, like the highs —
        // conversion is a render concern; never makes a day limited (FR-7).
        public ?float $feelsLikeHighC = null,
        public ?float $feelsLikeHighF = null,
    ) {}

    /**
     * Rebuild a day from its toArray() shape (cache/snapshot rehydration).
     *
     * @param  array<string, mixed>  $day
     */
    public static function fromArray(array $day): self
    {
        return new self(
            date: (string) $day['date'],
            conditionText: $day['conditionText'] ?? null,
            precipChance: $day['precipChance'] ?? null,
            highC: $day['highC'] ?? null,
            highF: $day['highF'] ?? null,
            lowC: $day['lowC'] ?? null,
            lowF: $day['lowF'] ?? null,
            humidity: $day['humidity'] ?? null,
            feelsLikeHighC: $day['feelsLikeHighC'] ?? null,
            feelsLikeHighF: $day['feelsLikeHighF'] ?? null,
        );
    }

    /**
     * A day is limited when any core value is missing. Humidity is optional
     * enrichment and never makes a day limited.
     */
    public function isLimited(): bool
    {
        return $this->conditionText === null
            || $this->precipChance === null
            || $this->highC === null
            || $this->highF === null
            || $this->lowC === null
            || $this->lowF === null;
    }

    /**
     * Stable, JSON-safe shape persisted in email_logs.weather_snapshot (AD-9) and
     * read back by the renderer (2.4) and history/narration (4.x).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'conditionText' => $this->conditionText,
            'precipChance' => $this->precipChance,
            'highC' => $this->highC,
            'highF' => $this->highF,
            'lowC' => $this->lowC,
            'lowF' => $this->lowF,
            'humidity' => $this->humidity,
            'feelsLikeHighC' => $this->feelsLikeHighC,
            'feelsLikeHighF' => $this->feelsLikeHighF,
        ];
    }
}
