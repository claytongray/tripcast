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
     * Compact header sub-line: the place is the heading above it, so this never
     * repeats it (UX). Pre-trip leans into the countdown — "5 days to go!" — and
     * keeps the in-trip wording ("Day N", "Last day", "Today"). The trip dates
     * render below it via {@see self::dateRange()}.
     */
    public function headerLine(Trip $trip, CarbonInterface $today): string
    {
        $days = $this->cadence->daysUntilDeparture($trip, $today);

        if ($days > 1) {
            return "{$days} days to go!";
        }

        if ($days === 1) {
            return '1 day to go!';
        }

        if ($days === 0) {
            return 'Today';
        }

        if ($this->isLastDay($trip, $today)) {
            return 'Last day';
        }

        return 'Day '.(abs($days) + 1); // departure day = Day 1
    }

    /**
     * The trip's date span for the header, month-first to read unambiguously:
     * "Jul 1–7", "Jun 28 – Jul 3", "Dec 28 2026 – Jan 3 2027". The year shows
     * only when the span crosses one. A single-day trip collapses to the one date.
     */
    public function dateRange(Trip $trip): string
    {
        return $this->formatRange($trip->departure_date, $trip->return_date);
    }

    /**
     * Format a span between two destination-local dates the same way the header
     * does, reused for the collapsed "still beyond the horizon" itinerary line.
     */
    public function formatRange(CarbonInterface $start, CarbonInterface $end): string
    {
        if ($start->isSameDay($end)) {
            return $start->format('M j');
        }

        if ($start->year !== $end->year) {
            return $start->format('M j Y').' – '.$end->format('M j Y');
        }

        if ($start->month === $end->month) {
            return $start->format('M j').'–'.$end->format('j');
        }

        return $start->format('M j').' – '.$end->format('M j');
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
