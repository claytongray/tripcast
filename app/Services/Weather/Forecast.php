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
     * Rebuild a forecast from its toArray() shape (cache/snapshot rehydration).
     * `limited` is derived, not read back — isLimited() stays the one authority.
     *
     * @param  array{days: list<array<string, mixed>>}  $snapshot
     */
    public static function fromArray(array $snapshot): self
    {
        return new self(array_map(
            fn (array $day): ForecastDay => ForecastDay::fromArray($day),
            $snapshot['days'],
        ));
    }

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

    /**
     * Stable, JSON-safe snapshot persisted in email_logs.weather_snapshot (AD-9).
     *
     * @return array{days: list<array<string, mixed>>, limited: bool}
     */
    public function toArray(): array
    {
        return [
            'days' => array_map(fn (ForecastDay $day): array => $day->toArray(), $this->days),
            'limited' => $this->isLimited(),
        ];
    }
}
