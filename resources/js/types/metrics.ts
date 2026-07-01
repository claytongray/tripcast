// Contracts mirroring App\Services\Metrics\MetricsService output, so the admin
// sections (Story 7.3+) are typed end-to-end from PHP aggregate to Vue chart.

/** One zero-filled daily bucket of a metric series. */
export type MetricPoint = {
    date: string;
    count: number;
};

/** An ascending, zero-filled daily series over a window. */
export type MetricSeries = MetricPoint[];

/** KPI-tile shape: current value, prior period, and the change. */
export type KpiTileData = {
    value: number;
    previous: number;
    delta: number;
    delta_pct: number | null;
};

/** A single labelled dataset for a TrendChart. */
export type TrendSeries = {
    label: string;
    data: number[];
};
