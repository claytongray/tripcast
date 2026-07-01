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
        $daily = $this->counts($this->metrics->dailyCountsByTimestamp(SampleRequest::query(), 'created_at', $window));
        $requests = array_sum($daily);

        // Distinct requesters (a user with several requests counts once) and how
        // many of them are confirmed signups.
        $requesters = SampleRequest::query()
            ->whereBetween('created_at', [$window->start, $window->end])
            ->distinct()
            ->count('user_id');

        $confirmedRequesters = SampleRequest::query()
            ->join('users', 'users.id', '=', 'sample_requests.user_id')
            ->whereBetween('sample_requests.created_at', [$window->start, $window->end])
            ->whereNotNull('users.email_verified_at')
            ->distinct()
            ->count('sample_requests.user_id');

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

    /**
     * @param  list<array{date: string, count: int}>  $series
     * @return list<int>
     */
    private function counts(array $series): array
    {
        return array_column($series, 'count');
    }
}
