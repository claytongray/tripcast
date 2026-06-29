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
    ) {}

    /**
     * A day is limited when any core value is missing.
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
}
