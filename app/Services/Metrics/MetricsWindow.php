<?php

namespace App\Services\Metrics;

use Carbon\CarbonImmutable;

/**
 * A resolved admin-metrics window (Epic 7, FR-22). Captures the current period
 * `[start, end]` (inclusive, app tz) plus the immediately-preceding equal-length
 * period `[previousStart, previousEnd]` used for KPI-tile deltas. `dates()` is the
 * zero-fill spine every daily series is projected onto.
 */
final readonly class MetricsWindow
{
    public function __construct(
        public int $days,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public CarbonImmutable $previousStart,
        public CarbonImmutable $previousEnd,
    ) {}

    /**
     * The ordered `Y-m-d` string for every day in `[start, end]` — the spine daily
     * series are zero-filled against, so a day with no rows renders as `0`.
     *
     * @return list<string>
     */
    public function dates(): array
    {
        $dates = [];
        $cursor = $this->start->startOfDay();
        $last = $this->end->startOfDay();

        while ($cursor->lessThanOrEqualTo($last)) {
            $dates[] = $cursor->toDateString();
            $cursor = $cursor->addDay();
        }

        return $dates;
    }
}
