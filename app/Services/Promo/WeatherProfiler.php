<?php

namespace App\Services\Promo;

/**
 * Maps a secured weather snapshot to a catalog weather profile for the DB promo
 * provider (Epic 8). Returns null — routing to the Essentials pool per FR-26 —
 * when the signal is neutral (mild) or too early (< 2 usable forecast days);
 * otherwise one of snow/hot/cold-wet/cold via the placeholder thresholds.
 * (AffiliatePromoProvider keeps its own string-returning heuristic as the frozen
 * rollback adapter.)
 */
class WeatherProfiler
{
    // Placeholder thresholds (°F / precip %), tunable with the catalog.
    private const HOT_HIGH = 80;

    private const COLD_HIGH = 45;

    private const WET_PRECIP = 50;

    private const WET_HIGH = 60;

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function profile(array $snapshot): ?string
    {
        $days = $snapshot['days'] ?? null;

        if (! is_array($days)) {
            return null;
        }

        $highs = [];
        $maxPrecip = null;
        $snow = false;

        foreach ($days as $day) {
            if (! is_array($day)) {
                continue;
            }

            $condition = $day['conditionText'] ?? null;
            if (is_string($condition) && str_contains(strtolower($condition), 'snow')) {
                $snow = true;
            }

            if (isset($day['highF']) && is_numeric($day['highF'])) {
                $highs[] = (float) $day['highF'];
            }

            if (isset($day['precipChance']) && is_numeric($day['precipChance'])) {
                $maxPrecip = max($maxPrecip ?? 0, (int) $day['precipChance']);
            }
        }

        // Early/low-signal (< 2 usable forecast days) routes to Essentials —
        // checked BEFORE the snow short-circuit (FR-26).
        if (count($highs) < 2) {
            return null;
        }

        if ($snow) {
            return 'snow';
        }

        $avgHigh = array_sum($highs) / count($highs);

        return match (true) {
            $avgHigh >= self::HOT_HIGH => 'hot',
            ($maxPrecip ?? 0) >= self::WET_PRECIP && $avgHigh < self::WET_HIGH => 'cold-wet',
            $avgHigh < self::COLD_HIGH => 'cold',
            default => null, // neutral (mild) → Essentials
        };
    }
}
