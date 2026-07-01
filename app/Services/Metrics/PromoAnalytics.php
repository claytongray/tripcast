<?php

namespace App\Services\Metrics;

use App\Models\PromoEvent;

/**
 * Composes the Promo analytics section (Story 7.6, FR-25): impressions, clicks,
 * and CTR by `promo_slug` and by weather profile, from `promo_events` (AD-18).
 * Weather profile isn't stored on the event — it's derived by inverting the
 * `config('tripcast.promo.catalog')` the provider selects from. Read-only.
 */
class PromoAnalytics
{
    private const UNKNOWN_PROFILE = 'unknown';

    /**
     * @return array<string, mixed>
     */
    public function build(MetricsWindow $window): array
    {
        // One grouped query: per (slug, event) counts within the window.
        $rows = PromoEvent::query()
            ->whereBetween('send_date', [$window->start->toDateString(), $window->end->toDateString()])
            ->groupBy('promo_slug', 'event')
            ->selectRaw('promo_slug, event, count(*) as aggregate')
            ->get();

        $slugToProfile = $this->slugToProfileMap();

        /** @var array<string, array{impressions: int, clicks: int}> $perSlug */
        $perSlug = [];
        /** @var array<string, array{impressions: int, clicks: int}> $perProfile */
        $perProfile = [];
        $totalImpressions = 0;
        $totalClicks = 0;

        foreach ($rows as $row) {
            $slug = (string) $row->promo_slug;
            $count = (int) $row->getAttribute('aggregate');
            $isClick = $row->event === PromoEvent::EVENT_CLICK;
            $profile = $slugToProfile[$slug] ?? self::UNKNOWN_PROFILE;

            $perSlug[$slug] ??= ['impressions' => 0, 'clicks' => 0];
            $perProfile[$profile] ??= ['impressions' => 0, 'clicks' => 0];

            if ($isClick) {
                $perSlug[$slug]['clicks'] += $count;
                $perProfile[$profile]['clicks'] += $count;
                $totalClicks += $count;
            } else {
                $perSlug[$slug]['impressions'] += $count;
                $perProfile[$profile]['impressions'] += $count;
                $totalImpressions += $count;
            }
        }

        return [
            'totals' => [
                'impressions' => $totalImpressions,
                'clicks' => $totalClicks,
                'ctr' => $this->ctr($totalClicks, $totalImpressions),
            ],
            'by_slug' => $this->rows($perSlug, 'slug'),
            'by_profile' => $this->rows($perProfile, 'profile'),
        ];
    }

    /**
     * Invert the weather-keyed catalog into a slug → profile lookup.
     *
     * @return array<string, string>
     */
    private function slugToProfileMap(): array
    {
        /** @var array<string, list<array<string, string>>> $catalog */
        $catalog = config('tripcast.promo.catalog', []);
        $map = [];

        foreach ($catalog as $profile => $items) {
            foreach ($items as $item) {
                if (isset($item['slug'])) {
                    $map[$item['slug']] = $profile;
                }
            }
        }

        return $map;
    }

    /**
     * Shape a keyed impressions/clicks map into a sorted list of rows with CTR.
     *
     * @param  array<string, array{impressions: int, clicks: int}>  $grouped
     * @return list<array<string, int|float|string>>
     */
    private function rows(array $grouped, string $keyName): array
    {
        $rows = [];

        foreach ($grouped as $key => $counts) {
            $rows[] = [
                $keyName => $key,
                'impressions' => $counts['impressions'],
                'clicks' => $counts['clicks'],
                'ctr' => $this->ctr($counts['clicks'], $counts['impressions']),
            ];
        }

        // Most-shown first, then alphabetical for stable ordering.
        usort($rows, fn (array $a, array $b) => $b['impressions'] <=> $a['impressions']
            ?: strcmp((string) $a[$keyName], (string) $b[$keyName]));

        return $rows;
    }

    private function ctr(int $clicks, int $impressions): float
    {
        return $impressions > 0 ? round($clicks / $impressions * 100, 1) : 0.0;
    }
}
