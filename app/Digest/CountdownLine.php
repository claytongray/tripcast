<?php

namespace App\Digest;

use App\Models\Trip;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * The digest's countdown / position line + subject suffix (UX-DR16 locked copy).
 * Derives entirely from the one cadence authority (`CadencePredicate`, AD-11) and
 * the America/New_York "today" (AD-7) — never a second day-math implementation.
 */
class CountdownLine
{
    public function __construct(private CadencePredicate $cadence) {}

    /**
     * Header line: pre-trip "N days until {place}", departure day "Today: {place}",
     * in-trip "Day N in {place}", final day "Last day in {place}".
     */
    public function positionLine(Trip $trip, CarbonInterface $today): string
    {
        $place = $this->placeShort($trip);
        $days = $this->cadence->daysUntilDeparture($trip, $today);

        if ($days > 1) {
            return "{$days} days until {$place}";
        }

        if ($days === 1) {
            return "1 day until {$place}";
        }

        if ($days === 0) {
            return "Today: {$place}";
        }

        if ($this->isLastDay($trip, $today)) {
            return "Last day in {$place}";
        }

        return 'Day '.(abs($days) + 1)." in {$place}"; // departure day = Day 1
    }

    /**
     * Subject hook (place leads in the subject; weather verdict never appears).
     */
    public function subjectSuffix(Trip $trip, CarbonInterface $today): string
    {
        $days = $this->cadence->daysUntilDeparture($trip, $today);

        if ($days > 1) {
            return "{$days} days to go";
        }

        if ($days === 1) {
            return '1 day to go';
        }

        if ($days === 0) {
            return 'today';
        }

        if ($this->isLastDay($trip, $today)) {
            return 'last day';
        }

        return 'day '.(abs($days) + 1);
    }

    /**
     * City portion of the canonical name (text before the first comma).
     */
    public function placeShort(Trip $trip): string
    {
        return Str::of($trip->canonical_place_name)->before(',')->trim()->value();
    }

    private function isLastDay(Trip $trip, CarbonInterface $today): bool
    {
        return $today->toDateString() === $trip->return_date->toDateString();
    }
}
