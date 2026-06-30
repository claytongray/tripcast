<?php

namespace App\Services\Narration;

/**
 * One notable day-over-day change for a single trip day, computed from the
 * stored snapshots. The grounded fact a narrator phrases — both adapters share
 * these, so neither ever invents a figure (AD-17).
 */
final class NarrationDelta
{
    public const METRIC_RAIN = 'rain';

    public const METRIC_HIGH = 'high';

    public function __construct(
        public readonly string $date,      // Y-m-d
        public readonly string $dayLabel,  // e.g. "Thursday"
        public readonly string $metric,    // METRIC_RAIN | METRIC_HIGH
        public readonly int $from,
        public readonly int $to,
    ) {}

    public function magnitude(): int
    {
        return abs($this->to - $this->from);
    }
}
