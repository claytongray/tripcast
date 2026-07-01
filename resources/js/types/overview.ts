import type { TrendSeries } from '@/types/metrics';

/** A count-based KPI: current value, % change vs the prior period, sparkline. */
export type OverviewKpi = {
    value: number;
    delta_pct: number | null;
    series: number[];
};

/** A rate KPI: percentage value, percentage-point change, sparkline. */
export type OverviewRateKpi = {
    value: number;
    delta_pp: number | null;
    series: number[];
};

/** Current (point-in-time) distribution of trips by status. */
export type StatusMix = {
    active: number;
    paused: number;
    completed: number;
};

/** Today's send health. */
export type SendsToday = {
    total: number;
    sent: number;
    failed: number;
    success_rate: number | null;
    series: number[];
};

export type OverviewPayload = {
    window: number;
    windows: number[];
    dates: string[];
    kpis: {
        signups: OverviewKpi;
        confirmation_rate: OverviewRateKpi;
        trips_created: OverviewKpi;
        status_mix: StatusMix;
        sends_today: SendsToday;
        promo_ctr: OverviewRateKpi & { clicks: number; impressions: number };
        sample_requests: OverviewKpi;
    };
    charts: {
        signups: TrendSeries[];
        sends: TrendSeries[];
        ctr: TrendSeries[];
        samples: TrendSeries[];
    };
};
