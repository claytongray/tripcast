<?php

namespace App\Services\Metrics;

use App\Models\EmailLog;

/**
 * Composes the Email health section payload (Story 7.5, FR-24) from `email_logs`
 * (AD-9): daily sent/failed series, sent-vs-failed rate, failures grouped by the
 * `weather:`/`delivery:` reason prefix, and the stuck-`sending` count (stale
 * lease, AD-3). Read-only; bounded, grouped queries.
 */
class EmailHealthMetrics
{
    public function __construct(private readonly MetricsService $metrics) {}

    /**
     * @return array<string, mixed>
     */
    public function build(MetricsWindow $window): array
    {
        $dailySent = $this->metrics->counts($this->metrics->dailyCountsByDate(
            EmailLog::query()->where('status', EmailLog::STATUS_SENT), 'send_date', $window
        ));
        $dailyFailed = $this->metrics->counts($this->metrics->dailyCountsByDate(
            EmailLog::query()->where('status', EmailLog::STATUS_FAILED), 'send_date', $window
        ));

        $sent = array_sum($dailySent);
        $failed = array_sum($dailyFailed);
        $total = $sent + $failed;

        return [
            'sends' => [
                ['label' => 'Sent', 'data' => $dailySent],
                ['label' => 'Failed', 'data' => $dailyFailed],
            ],
            'totals' => [
                'sent' => $sent,
                'failed' => $failed,
                'total' => $total,
                'success_rate' => $total > 0 ? round($sent / $total * 100, 1) : null,
            ],
            'failures_by_reason' => $this->failuresByReason($window, $failed),
            'stuck_sending' => $this->stuckSendingCount(),
        ];
    }

    /**
     * Failed sends in the window bucketed by the reason prefix set in
     * SendTripDigest (`weather: …` / `delivery: …`); anything else is "other".
     *
     * @return array{weather: int, delivery: int, other: int}
     */
    private function failuresByReason(MetricsWindow $window, int $failedTotal): array
    {
        $inWindow = fn () => EmailLog::query()
            ->where('status', EmailLog::STATUS_FAILED)
            ->whereBetween('send_date', [$window->start->toDateString(), $window->end->toDateString()]);

        $weather = $inWindow()->where('failure_reason', 'like', 'weather:%')->count();
        $delivery = $inWindow()->where('failure_reason', 'like', 'delivery:%')->count();

        return [
            'weather' => $weather,
            'delivery' => $delivery,
            'other' => max(0, $failedTotal - $weather - $delivery),
        ];
    }

    /**
     * Sends whose lease went stale (AD-3): still `sending` past the stale-lease
     * threshold. A point-in-time count (not windowed).
     */
    private function stuckSendingCount(): int
    {
        $threshold = now()->subMinutes((int) config('tripcast.send.stale_lease_minutes'));

        return EmailLog::query()
            ->where('status', EmailLog::STATUS_SENDING)
            ->where('claimed_at', '<', $threshold)
            ->count();
    }
}
