<?php

namespace App\Services\Weather;

/**
 * A fetched, by-coordinates forecast (FR-11) — a self-contained, serializable
 * structure suitable for snapshotting later (AD-9). Faithful to the provider:
 * however many days it returned, with per-day limited markers (FR-7).
 */
final class Forecast
{
    /**
     * @param  list<ForecastDay>  $days
     */
    public function __construct(public array $days) {}

    /**
     * The forecast is limited if it has fewer than a full week or any day is limited.
     */
    public function isLimited(): bool
    {
        if (count($this->days) < 7) {
            return true;
        }

        foreach ($this->days as $day) {
            if ($day->isLimited()) {
                return true;
            }
        }

        return false;
    }
}
