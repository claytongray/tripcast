<?php

namespace App\Services\Metrics;

use App\Models\SampleRequest;

/**
 * Composes the Sample activity & acquisition section (Story 7.7, FR-25): sample
 * requests over time, top destinations, and sample→confirmed-signup conversion
 * (joining `sample_requests.user_id` → `users.email_verified_at`). Read-only;
 * bounded, grouped queries.
 */
class SampleFunnelMetrics
{
    private const TOP_DESTINATIONS_LIMIT = 10;

    public function __construct(private readonly MetricsService $metrics) {}

    /**
     * @return array<string, mixed>
     */
    public function build(MetricsWindow $window): array
    {
        // The funnel is acquisition-only: landing rows. Dashboard self-sends are
        // signed-in engagement — surfaced as their own total below, never mixed
        // into requests/requesters/conversion (they'd inflate all three).
        $daily = $this->metrics->counts($this->metrics->dailyCountsByTimestamp(
            SampleRequest::query()->where('source', SampleRequest::SOURCE_LANDING),
            'created_at',
            $window,
        ));
        $requests = array_sum($daily);

        // Distinct requesters (a user with several requests counts once) and how
        // many of them are confirmed signups.
        $requesters = SampleRequest::query()
            ->where('source', SampleRequest::SOURCE_LANDING)
            ->whereBetween('created_at', [$window->start, $window->end])
            ->distinct()
            ->count('user_id');

        $confirmedRequesters = SampleRequest::query()
            ->where('source', SampleRequest::SOURCE_LANDING)
            ->join('users', 'users.id', '=', 'sample_requests.user_id')
            ->whereBetween('sample_requests.created_at', [$window->start, $window->end])
            ->whereNotNull('users.email_verified_at')
            ->distinct()
            ->count('sample_requests.user_id');

        $dashboardRequests = SampleRequest::query()
            ->where('source', SampleRequest::SOURCE_DASHBOARD)
            ->whereBetween('created_at', [$window->start, $window->end])
            ->count();

        return [
            'requests' => [
                ['label' => 'Sample requests', 'data' => $daily],
            ],
            'totals' => [
                'requests' => $requests,
                'requesters' => $requesters,
                'confirmed_requesters' => $confirmedRequesters,
                'conversion_rate' => $requesters > 0
                    ? round($confirmedRequesters / $requesters * 100, 1)
                    : null,
                'dashboard_requests' => $dashboardRequests,
            ],
            'top_destinations' => $this->topDestinations($window),
        ];
    }

    /**
     * The most-requested destinations in the window.
     *
     * @return list<array{destination: string, count: int}>
     */
    private function topDestinations(MetricsWindow $window): array
    {
        $grouped = SampleRequest::query()
            ->where('source', SampleRequest::SOURCE_LANDING)
            ->whereBetween('created_at', [$window->start, $window->end])
            ->groupBy('destination')
            ->selectRaw('destination, count(*) as aggregate')
            ->orderByDesc('aggregate')
            ->orderBy('destination')
            ->limit(self::TOP_DESTINATIONS_LIMIT)
            ->get();

        $rows = [];

        foreach ($grouped as $row) {
            $rows[] = [
                'destination' => (string) $row->destination,
                'count' => (int) $row->getAttribute('aggregate'),
            ];
        }

        return $rows;
    }
}
