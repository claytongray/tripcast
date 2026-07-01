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
        $signupSeries = $this->metrics->counts($this->metrics->dailyCountsByTimestamp(User::query(), 'created_at', $window));
        $signups = array_sum($signupSeries);
        $previousSignups = $this->metrics->count(User::query(), 'created_at', $window->previousStart, $window->previousEnd);

        // Of the users who signed up in the window, how many are confirmed — same
        // created_at axis, so the rate can never exceed 100%.
        $confirmedSeries = $this->metrics->counts($this->metrics->dailyCountsByTimestamp(
            User::query()->whereNotNull('email_verified_at'), 'created_at', $window
        ));
        $confirmed = array_sum($confirmedSeries);
        $previousConfirmed = $this->metrics->count(
            User::query()->whereNotNull('email_verified_at'), 'created_at', $window->previousStart, $window->previousEnd
        );

        // --- Activation: trips created + current status mix ---
        $tripSeries = $this->metrics->counts($this->metrics->dailyCountsByTimestamp(Trip::query(), 'created_at', $window));
        $tripsCreated = array_sum($tripSeries);
        $previousTrips = $this->metrics->count(Trip::query(), 'created_at', $window->previousStart, $window->previousEnd);

        $statusMix = Trip::query()
            ->groupBy('status')
            ->selectRaw('status, count(*) as aggregate')
            ->pluck('aggregate', 'status');

        // --- Deliverability: sends today + success rate + daily sent/failed (AD-9) ---
        $today = $window->end->toDateString();
        $todayByStatus = EmailLog::query()
            ->where('send_date', $today)
            ->groupBy('status')
            ->selectRaw('status, count(*) as aggregate')
            ->pluck('aggregate', 'status');
        $sentToday = (int) ($todayByStatus[EmailLog::STATUS_SENT] ?? 0);
        $failedToday = (int) ($todayByStatus[EmailLog::STATUS_FAILED] ?? 0);
        $totalToday = (int) $todayByStatus->sum();

        // One grouped query yields both the daily Sent and Failed series.
        [$dailySent, $dailyFailed] = $this->dailySentFailed($window);

        // --- Monetization: promo CTR (clicks / impressions) (AD-18) ---
        $dailyImpressions = $this->metrics->counts($this->metrics->dailyCountsByDate(
            PromoEvent::query()->where('event', PromoEvent::EVENT_IMPRESSION), 'send_date', $window
        ));
        $dailyClicks = $this->metrics->counts($this->metrics->dailyCountsByDate(
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
        $dailyCtr = $this->dailyCtr($dailyClicks, $dailyImpressions);

        // --- Samples ---
        $sampleSeries = $this->metrics->counts($this->metrics->dailyCountsByTimestamp(SampleRequest::query(), 'created_at', $window));
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
                    // Rate over terminal outcomes only — in-progress `sending` rows
                    // must not depress it mid-run (matches the Emails page).
                    'success_rate' => ($sentToday + $failedToday) > 0
                        ? round($sentToday / ($sentToday + $failedToday) * 100, 1)
                        : null,
                ],
                'promo_ctr' => [
                    ...$this->rate($clicks, $impressions, $previousClicks, $previousImpressions, $dailyCtr),
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
                'ctr' => [['label' => 'CTR %', 'data' => $dailyCtr]],
                'samples' => [['label' => 'Samples', 'data' => $sampleSeries]],
            ],
        ];
    }

    /**
     * A rate KPI: `numerator / denominator` as a percentage, with the
     * percentage-point delta vs the previous period (null when the previous
     * period had no denominator — no baseline to compare against).
     *
     * @param  list<int|float>  $series
     * @return array{value: float, delta_pp: float|null, series: list<int|float>}
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
     * The daily Sent and Failed series over the window from ONE grouped query
     * (`send_date, status`), each zero-filled against the window's dates.
     *
     * @return array{0: list<int>, 1: list<int>}
     */
    private function dailySentFailed(MetricsWindow $window): array
    {
        $rows = EmailLog::query()
            ->whereBetween('send_date', [$window->start->toDateString(), $window->end->toDateString()])
            ->whereIn('status', [EmailLog::STATUS_SENT, EmailLog::STATUS_FAILED])
            ->groupBy('send_date', 'status')
            ->selectRaw('send_date, status, count(*) as aggregate')
            ->get();

        $sentByDate = [];
        $failedByDate = [];

        foreach ($rows as $row) {
            $date = $row->send_date->toDateString();
            $count = (int) $row->getAttribute('aggregate');

            if ($row->status === EmailLog::STATUS_SENT) {
                $sentByDate[$date] = $count;
            } else {
                $failedByDate[$date] = $count;
            }
        }

        $dates = $window->dates();

        return [
            array_map(fn (string $date): int => $sentByDate[$date] ?? 0, $dates),
            array_map(fn (string $date): int => $failedByDate[$date] ?? 0, $dates),
        ];
    }
}
