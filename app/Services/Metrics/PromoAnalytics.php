<?php

namespace App\Services\Metrics;

use App\Models\PromoEvent;
use App\Models\PromoItem;
use Illuminate\Database\Eloquent\Collection;

/**
 * Composes the Promo analytics section (Story 7.6, FR-25): impressions, clicks,
 * and CTR by `promo_slug` and by weather profile, from `promo_events` (AD-18).
 * Weather profile isn't stored on the event — it's resolved by slug lookup
 * against the DB-backed `PromoItem` catalog (`withTrashed`, so retired items'
 * historical events still bucket), with `config('tripcast.promo.catalog')` kept
 * as a bake-period fallback for slugs not yet in `promo_items` (Story 8.5).
 * `perSlug()` exposes the same fold to the catalog UI's per-item performance
 * (Story 8.5). Read-only.
 */
class PromoAnalytics
{
    private const UNKNOWN_PROFILE = 'unknown';

    /**
     * @return array<string, mixed>
     */
    public function build(MetricsWindow $window): array
    {
        $perSlug = $this->foldPerSlug($window);
        $slugToProfile = $this->slugToProfileMap();

        /** @var array<string, array{impressions: int, clicks: int}> $perProfile */
        $perProfile = [];
        $totalImpressions = 0;
        $totalClicks = 0;

        // Profile is a per-slug property, so roll the per-slug counts up by profile.
        foreach ($perSlug as $slug => $counts) {
            $profile = $slugToProfile[$slug] ?? self::UNKNOWN_PROFILE;
            $perProfile[$profile] ??= ['impressions' => 0, 'clicks' => 0];
            $perProfile[$profile]['impressions'] += $counts['impressions'];
            $perProfile[$profile]['clicks'] += $counts['clicks'];
            $totalImpressions += $counts['impressions'];
            $totalClicks += $counts['clicks'];
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
     * Per-slug impressions/clicks/CTR within the window, keyed by slug — the fold
     * the catalog list (Story 8.5) attaches to each item.
     *
     * @return array<string, array{impressions: int, clicks: int, ctr: float}>
     */
    public function perSlug(MetricsWindow $window): array
    {
        $out = [];

        foreach ($this->foldPerSlug($window) as $slug => $counts) {
            $out[$slug] = [
                'impressions' => $counts['impressions'],
                'clicks' => $counts['clicks'],
                'ctr' => $this->ctr($counts['clicks'], $counts['impressions']),
            ];
        }

        return $out;
    }

    /**
     * One grouped query, folded into per-slug impression/click counts within the
     * window. The single source of the per-slug numbers (build + perSlug).
     *
     * @return array<string, array{impressions: int, clicks: int}>
     */
    private function foldPerSlug(MetricsWindow $window): array
    {
        $rows = PromoEvent::query()
            ->whereBetween('send_date', [$window->start->toDateString(), $window->end->toDateString()])
            ->groupBy('promo_slug', 'event')
            ->selectRaw('promo_slug, event, count(*) as aggregate')
            ->get();

        /** @var array<string, array{impressions: int, clicks: int}> $perSlug */
        $perSlug = [];

        foreach ($rows as $row) {
            $slug = (string) $row->promo_slug;
            $count = (int) $row->getAttribute('aggregate');

            $perSlug[$slug] ??= ['impressions' => 0, 'clicks' => 0];

            if ($row->event === PromoEvent::EVENT_CLICK) {
                $perSlug[$slug]['clicks'] += $count;
            } else {
                $perSlug[$slug]['impressions'] += $count;
            }
        }

        return $perSlug;
    }

    /**
     * Slug → weather-profile lookup. DB-backed catalog wins (`PromoItem`,
     * `withTrashed` so retired items still resolve); the config catalog is a
     * bake-period fallback for slugs not yet in `promo_items` (Story 8.5).
     *
     * @return array<string, string>
     */
    private function slugToProfileMap(): array
    {
        /** @var array<string, list<array<string, string>>> $catalog */
        $catalog = config('tripcast.promo.catalog', []);
        $configMap = [];

        foreach ($catalog as $profile => $items) {
            foreach ($items as $item) {
                if (isset($item['slug'])) {
                    $configMap[$item['slug']] = $profile;
                }
            }
        }

        /** @var Collection<int, PromoItem> $items */
        $items = PromoItem::withTrashed()->get(['slug', 'weather_profile']);
        $dbMap = [];

        foreach ($items as $item) {
            $dbMap[$item->slug] = $item->weather_profile;
        }

        // DB catalog overrides the config fallback for any shared slug.
        return array_merge($configMap, $dbMap);
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
