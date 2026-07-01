<?php

namespace App\Services\Metrics;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;

/**
 * The one aggregation service behind the admin observability panel (Epic 7,
 * FR-22). Every section (Overview 7.3, Users 7.4, Emails 7.5, Promos 7.6,
 * Samples 7.7) computes its metrics through these primitives, so bucketing,
 * zero-fill, and tile-delta shapes stay identical everywhere.
 *
 * Read-only: only SELECT/aggregate queries. Windows are the 7/30/90 allowlist —
 * arbitrary N is rejected, which is the "no unbounded scans" guard.
 *
 * Injection safety: the `$column` arguments are caller-supplied *identifiers*,
 * never request input — every Epic-7 call site passes a hardcoded column name.
 * They flow into `selectRaw`/`groupByRaw`; do not pass untrusted values.
 */
class MetricsService
{
    /** @var list<int> */
    public const ALLOWED_WINDOWS = [7, 30, 90];

    /**
     * Resolve a window to concrete bounds, anchored on "now" in the app timezone.
     * The previous period is the equal-length block ending the day before `start`.
     *
     * @throws InvalidArgumentException when $days is not one of {7, 30, 90}
     */
    public function resolveWindow(int $days): MetricsWindow
    {
        if (! in_array($days, self::ALLOWED_WINDOWS, true)) {
            throw new InvalidArgumentException(
                "Unsupported metrics window [{$days}]; allowed: ".implode(', ', self::ALLOWED_WINDOWS).'.'
            );
        }

        /** @var CarbonImmutable $now */
        $now = CarbonImmutable::now(config('app.timezone'));

        $end = $now->endOfDay();
        $start = $now->subDays($days - 1)->startOfDay();

        $previousEnd = $start->subDay()->endOfDay();
        $previousStart = $start->subDays($days)->startOfDay();

        return new MetricsWindow($days, $start, $end, $previousStart, $previousEnd);
    }

    /**
     * Daily counts for a **`date`-typed** column (e.g. `email_logs.send_date`,
     * `promo_events.send_date`) as a zero-filled, ascending series. One grouped
     * query; the window's date range bounds the scan.
     *
     * The column is constrained to the known Epic-7 date columns: this both keeps
     * the API injection-proof (callers can only pass a known identifier) and lets
     * PHPStan preserve `literal-string` through the raw expression.
     *
     * @param  EloquentBuilder<*>|QueryBuilder  $query
     * @param  'send_date'  $dateColumn
     * @return list<array{date: string, count: int}>
     */
    public function dailyCountsByDate(EloquentBuilder|QueryBuilder $query, string $dateColumn, MetricsWindow $window): array
    {
        $rows = $query
            ->whereBetween($dateColumn, [$window->start->toDateString(), $window->end->toDateString()])
            ->groupBy($dateColumn)
            ->selectRaw("{$dateColumn} as bucket, count(*) as aggregate")
            ->pluck('aggregate', 'bucket');

        return $this->zeroFill($rows->all(), $window);
    }

    /**
     * Daily counts for a **timestamp** column (e.g. `users.created_at`,
     * `sample_requests.created_at`) bucketed by calendar day (`DATE(col)`), as a
     * zero-filled, ascending series. One grouped query; app tz is UTC so `DATE()`
     * is the true calendar day.
     *
     * The column is constrained to the known Epic-7 timestamp columns: injection-
     * proof and `literal-string`-preserving through the raw `DATE(...)` expression.
     *
     * @param  EloquentBuilder<*>|QueryBuilder  $query
     * @param  'created_at'|'email_verified_at'  $timestampColumn
     * @return list<array{date: string, count: int}>
     */
    public function dailyCountsByTimestamp(EloquentBuilder|QueryBuilder $query, string $timestampColumn, MetricsWindow $window): array
    {
        $rows = $query
            ->whereBetween($timestampColumn, [$window->start, $window->end])
            ->groupByRaw("DATE({$timestampColumn})")
            ->selectRaw("DATE({$timestampColumn}) as bucket, count(*) as aggregate")
            ->pluck('aggregate', 'bucket');

        return $this->zeroFill($rows->all(), $window);
    }

    /**
     * A single bounded `COUNT(*)` between two instants (or dates), for building
     * tile current-vs-previous totals.
     *
     * @param  EloquentBuilder<*>|QueryBuilder  $query
     */
    public function count(EloquentBuilder|QueryBuilder $query, string $column, CarbonInterface $from, CarbonInterface $to, bool $isDate = false): int
    {
        $bounds = $isDate
            ? [$from->toDateString(), $to->toDateString()]
            : [$from, $to];

        return $query->whereBetween($column, $bounds)->count();
    }

    /**
     * KPI-tile shape: current value, prior-period value, absolute delta, and the
     * percentage change (null when there is no prior baseline — the frontend
     * renders "—" rather than a divide-by-zero).
     *
     * @return array{value: int, previous: int, delta: int, delta_pct: float|null}
     */
    public function tile(int $current, int $previous): array
    {
        return [
            'value' => $current,
            'previous' => $previous,
            'delta' => $current - $previous,
            'delta_pct' => $previous === 0
                ? null
                : round(($current - $previous) / $previous * 100, 1),
        ];
    }

    /**
     * Pluck the `count` column out of a daily series into a bare list — the shape
     * the frontend sparkline/chart components consume.
     *
     * @param  list<array{date: string, count: int}>  $series
     * @return list<int>
     */
    public function counts(array $series): array
    {
        return array_column($series, 'count');
    }

    /**
     * Project `{bucket => count}` onto every day in the window, defaulting missing
     * days to 0 and preserving ascending order. Buckets arrive keyed by `Y-m-d`
     * (date columns) or `DATE()` output (both `Y-m-d`).
     *
     * @param  array<array-key, int|string>  $bucketedCounts
     * @return list<array{date: string, count: int}>
     */
    private function zeroFill(array $bucketedCounts, MetricsWindow $window): array
    {
        $series = [];

        foreach ($window->dates() as $date) {
            $series[] = [
                'date' => $date,
                'count' => (int) ($bucketedCounts[$date] ?? 0),
            ];
        }

        return $series;
    }
}
