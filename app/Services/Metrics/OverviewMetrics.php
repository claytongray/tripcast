<?php

namespace App\Services\Metrics;

use App\Models\EmailLog;
use App\Models\PromoEvent;
use App\Models\SampleRequest;
use App\Models\Trip;
use App\Models\User;

/**
 * Composes the Overview dashboard payload (Story 7.3, FR-22) from the shared
 * {@see MetricsService} primitives: acquisition (signups, confirmation rate),
 * activation (trips created, active-trip status mix), deliverability (sends
 * today + success rate), monetization (promo CTR), and samples — each as a
 * KPI (with sparkline) plus the four trend series. Read-only; bounded, grouped
 * queries only.
 */
class OverviewMetrics
{
    public function __construct(private readonly MetricsService $metrics) {}

    /**
     * Build the full Overview payload for a resolved window.
     *
     * @return array<string, mixed>
     */
    public function build(MetricsWindow $window): array
    {
        $dates = $window->dates();

        // --- Acquisition: signups + confirmation rate (bucketed by created_at) ---
        $signupSeries = $this->counts($this->metrics->dailyCountsByTimestamp(User::query(), 'created_at', $window));
        $signups = array_sum($signupSeries);
        $previousSignups = $this->metrics->count(User::query(), 'created_at', $window->previousStart, $window->previousEnd);

        // Of the users who signed up in the window, how many are confirmed — same
        // created_at axis, so the rate can never exceed 100%.
        $confirmedSeries = $this->counts($this->metrics->dailyCountsByTimestamp(
            User::query()->whereNotNull('email_verified_at'), 'created_at', $window
        ));
        $confirmed = array_sum($confirmedSeries);
        $previousConfirmed = $this->metrics->count(
            User::query()->whereNotNull('email_verified_at'), 'created_at', $window->previousStart, $window->previousEnd
        );

        // --- Activation: trips created + current status mix ---
        $tripSeries = $this->counts($this->metrics->dailyCountsByTimestamp(Trip::query(), 'created_at', $window));
        $tripsCreated = array_sum($tripSeries);
        $previousTrips = $this->metrics->count(Trip::query(), 'created_at', $window->previousStart, $window->previousEnd);

        $statusMix = Trip::query()
            ->groupBy('status')
            ->selectRaw('status, count(*) as aggregate')
            ->pluck('aggregate', 'status');

        // --- Deliverability: sends today + success rate + daily sends (AD-9) ---
        $today = $window->end->toDateString();
        $todayByStatus = EmailLog::query()
            ->where('send_date', $today)
            ->groupBy('status')
            ->selectRaw('status, count(*) as aggregate')
            ->pluck('aggregate', 'status');
        $sentToday = (int) ($todayByStatus[EmailLog::STATUS_SENT] ?? 0);
        $failedToday = (int) ($todayByStatus[EmailLog::STATUS_FAILED] ?? 0);
        $totalToday = (int) $todayByStatus->sum();

        $dailySends = $this->counts($this->metrics->dailyCountsByDate(EmailLog::query(), 'send_date', $window));
        $dailySent = $this->counts($this->metrics->dailyCountsByDate(
            EmailLog::query()->where('status', EmailLog::STATUS_SENT), 'send_date', $window
        ));
        $dailyFailed = $this->counts($this->metrics->dailyCountsByDate(
            EmailLog::query()->where('status', EmailLog::STATUS_FAILED), 'send_date', $window
        ));

        // --- Monetization: promo CTR (clicks / impressions) (AD-18) ---
        $dailyImpressions = $this->counts($this->metrics->dailyCountsByDate(
            PromoEvent::query()->where('event', PromoEvent::EVENT_IMPRESSION), 'send_date', $window
        ));
        $dailyClicks = $this->counts($this->metrics->dailyCountsByDate(
            PromoEvent::query()->where('event', PromoEvent::EVENT_CLICK), 'send_date', $window
        ));
        $impressions = array_sum($dailyImpressions);
        $clicks = array_sum($dailyClicks);
        $previousImpressions = $this->metrics->count(
            PromoEvent::query()->where('event', PromoEvent::EVENT_IMPRESSION), 'send_date', $window->previousStart, $window->previousEnd, true
        );
        $previousClicks = $this->metrics->count(
            PromoEvent::query()->where('event', PromoEvent::EVENT_CLICK), 'send_date', $window->previousStart, $window->previousEnd, true
        );

        // --- Samples ---
        $sampleSeries = $this->counts($this->metrics->dailyCountsByTimestamp(SampleRequest::query(), 'created_at', $window));
        $samples = array_sum($sampleSeries);
        $previousSamples = $this->metrics->count(SampleRequest::query(), 'created_at', $window->previousStart, $window->previousEnd);

        return [
            'window' => $window->days,
            'windows' => MetricsService::ALLOWED_WINDOWS,
            'dates' => $dates,
            'kpis' => [
                'signups' => [
                    'value' => $signups,
                    'delta_pct' => $this->metrics->tile($signups, $previousSignups)['delta_pct'],
                    'series' => $signupSeries,
                ],
                'confirmation_rate' => $this->rate($confirmed, $signups, $previousConfirmed, $previousSignups, $confirmedSeries),
                'trips_created' => [
                    'value' => $tripsCreated,
                    'delta_pct' => $this->metrics->tile($tripsCreated, $previousTrips)['delta_pct'],
                    'series' => $tripSeries,
                ],
                'status_mix' => [
                    'active' => (int) ($statusMix[Trip::STATUS_ACTIVE] ?? 0),
                    'paused' => (int) ($statusMix[Trip::STATUS_PAUSED] ?? 0),
                    'completed' => (int) ($statusMix[Trip::STATUS_COMPLETED] ?? 0),
                ],
                'sends_today' => [
                    'total' => $totalToday,
                    'sent' => $sentToday,
                    'failed' => $failedToday,
                    'success_rate' => $totalToday > 0 ? round($sentToday / $totalToday * 100, 1) : null,
                    'series' => $dailySends,
                ],
                'promo_ctr' => [
                    ...$this->rate($clicks, $impressions, $previousClicks, $previousImpressions, $dailyClicks),
                    'clicks' => $clicks,
                    'impressions' => $impressions,
                ],
                'sample_requests' => [
                    'value' => $samples,
                    'delta_pct' => $this->metrics->tile($samples, $previousSamples)['delta_pct'],
                    'series' => $sampleSeries,
                ],
            ],
            'charts' => [
                'signups' => [['label' => 'Signups', 'data' => $signupSeries]],
                'sends' => [
                    ['label' => 'Sent', 'data' => $dailySent],
                    ['label' => 'Failed', 'data' => $dailyFailed],
                ],
                'ctr' => [['label' => 'CTR %', 'data' => $this->dailyCtr($dailyClicks, $dailyImpressions)]],
                'samples' => [['label' => 'Samples', 'data' => $sampleSeries]],
            ],
        ];
    }

    /**
     * A rate KPI: `numerator / denominator` as a percentage, with the
     * percentage-point delta vs the previous period (null when the previous
     * period had no denominator — no baseline to compare against).
     *
     * @param  list<int>  $series
     * @return array{value: float, delta_pp: float|null, series: list<int>}
     */
    private function rate(int $numerator, int $denominator, int $previousNumerator, int $previousDenominator, array $series): array
    {
        $rate = $denominator > 0 ? round($numerator / $denominator * 100, 1) : 0.0;
        $previousRate = $previousDenominator > 0 ? round($previousNumerator / $previousDenominator * 100, 1) : null;

        return [
            'value' => $rate,
            'delta_pp' => $previousRate === null ? null : round($rate - $previousRate, 1),
            'series' => $series,
        ];
    }

    /**
     * Per-day CTR percentage from aligned daily clicks/impressions series.
     *
     * @param  list<int>  $clicks
     * @param  list<int>  $impressions
     * @return list<float>
     */
    private function dailyCtr(array $clicks, array $impressions): array
    {
        $ctr = [];

        foreach ($clicks as $index => $dayClicks) {
            $dayImpressions = $impressions[$index] ?? 0;
            $ctr[] = $dayImpressions > 0 ? round($dayClicks / $dayImpressions * 100, 1) : 0.0;
        }

        return $ctr;
    }

    /**
     * Pluck the `count` column out of a daily series into a bare list.
     *
     * @param  list<array{date: string, count: int}>  $series
     * @return list<int>
     */
    private function counts(array $series): array
    {
        return array_column($series, 'count');
    }
}
