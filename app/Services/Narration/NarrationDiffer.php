<?php

namespace App\Services\Narration;

use Carbon\CarbonImmutable;

/**
 * The shared, deterministic detector behind every narrator (AD-17). Diffs the
 * prior vs current snapshot for trip-window days present in both and returns the
 * notable changes (precip swing, high-temp swing) sorted by magnitude. This is
 * the single grounded source of figures — adapters phrase these, never invent.
 */
class NarrationDiffer
{
    /**
     * @return list<NarrationDelta> notable deltas, largest magnitude first
     */
    public function diff(NarrationContext $context): array
    {
        if ($context->priorSnapshot === null) {
            return [];
        }

        $prior = $this->byDate($context->priorSnapshot);
        $current = $this->byDate($context->currentSnapshot);

        $precipThreshold = (int) config('tripcast.narration.notable.precip');
        $tempThreshold = (int) config('tripcast.narration.notable.temp');
        $highKey = $context->celsius ? 'highC' : 'highF';

        $deltas = [];

        foreach ($current as $date => $today) {
            // Only days the trip actually covers, present in both snapshots.
            if ($date < $context->departureDate || $date > $context->returnDate) {
                continue;
            }

            if (! isset($prior[$date])) {
                continue;
            }

            $yesterday = $prior[$date];
            $dayLabel = CarbonImmutable::parse($date)->format('l');

            $rain = $this->delta($yesterday, $today, 'precipChance');
            if ($rain !== null && abs($rain[1] - $rain[0]) >= $precipThreshold) {
                $deltas[] = new NarrationDelta($date, $dayLabel, NarrationDelta::METRIC_RAIN, $rain[0], $rain[1]);
            }

            $high = $this->delta($yesterday, $today, $highKey);
            if ($high !== null && abs($high[1] - $high[0]) >= $tempThreshold) {
                $deltas[] = new NarrationDelta($date, $dayLabel, NarrationDelta::METRIC_HIGH, $high[0], $high[1]);
            }
        }

        usort($deltas, fn (NarrationDelta $a, NarrationDelta $b): int => $b->magnitude() <=> $a->magnitude());

        return $deltas;
    }

    /**
     * Index a snapshot's days by their calendar date.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, array<string, mixed>>
     */
    private function byDate(array $snapshot): array
    {
        $days = $snapshot['days'] ?? null;

        if (! is_array($days)) {
            return [];
        }

        $byDate = [];

        foreach ($days as $day) {
            if (is_array($day) && isset($day['date']) && is_string($day['date'])) {
                $byDate[$day['date']] = $day;
            }
        }

        return $byDate;
    }

    /**
     * The [from, to] integer pair for a metric, or null if either side is absent.
     *
     * @param  array<string, mixed>  $yesterday
     * @param  array<string, mixed>  $today
     * @return array{0: int, 1: int}|null
     */
    private function delta(array $yesterday, array $today, string $key): ?array
    {
        $from = $yesterday[$key] ?? null;
        $to = $today[$key] ?? null;

        if ($from === null || $to === null) {
            return null;
        }

        return [(int) round((float) $from), (int) round((float) $to)];
    }
}
