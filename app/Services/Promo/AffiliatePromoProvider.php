<?php

namespace App\Services\Promo;

/**
 * The v1 promo adapter (AD-18): a weather-keyed Amazon affiliate config. Pure —
 * no I/O, affiliate links are plain tagged URLs (no SDK) — so the Story 5.3
 * timebox is trivially met. Maps the secured snapshot to a weather profile and
 * picks one item via deterministic rotation keyed by send_date (a re-render
 * picks the same item — no idempotency hazard), with a generic fallback. Vendor
 * specifics (the Amazon host, the associate tag) appear only here.
 */
class AffiliatePromoProvider implements PromoProvider
{
    private const FALLBACK_PROFILE = 'travel-essentials';

    // Placeholder thresholds (°F / precip %), tunable with the catalog.
    private const HOT_HIGH = 80;

    private const COLD_HIGH = 45;

    private const WET_PRECIP = 50;

    private const WET_HIGH = 60;

    public function select(array $snapshot, string $sendDate): ?Promo
    {
        $profile = $this->profileFor($snapshot);

        /** @var array<string, list<array<string, string>>> $catalog */
        $catalog = config('tripcast.promo.catalog', []);

        // A missing OR empty mapped profile falls back to travel-essentials.
        $items = $catalog[$profile] ?? [];

        if ($items === []) {
            $items = $catalog[self::FALLBACK_PROFILE] ?? [];
        }

        if ($items === []) {
            return null; // empty catalog — slot stays absent
        }

        // Deterministic rotation: stable for a given send_date, so a re-render
        // selects the same item (AD-3/AD-18).
        $item = $items[crc32($sendDate) % count($items)];

        return new Promo(
            slug: $item['slug'],
            label: $item['label'],
            imageUrl: $item['image'],
            url: $this->tagged($item['url']),
        );
    }

    /**
     * Map the snapshot's days to a weather profile (documented, deterministic
     * heuristic). No usable days → the fallback profile.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function profileFor(array $snapshot): string
    {
        $days = $snapshot['days'] ?? null;

        if (! is_array($days)) {
            return self::FALLBACK_PROFILE;
        }

        $highs = [];
        $maxPrecip = null;
        $snow = false;

        foreach ($days as $day) {
            if (! is_array($day)) {
                continue;
            }

            $condition = $day['conditionText'] ?? null;
            if (is_string($condition) && str_contains(strtolower($condition), 'snow')) {
                $snow = true;
            }

            if (isset($day['highF']) && is_numeric($day['highF'])) {
                $highs[] = (float) $day['highF'];
            }

            if (isset($day['precipChance']) && is_numeric($day['precipChance'])) {
                $maxPrecip = max($maxPrecip ?? 0, (int) $day['precipChance']);
            }
        }

        if ($snow) {
            return 'snow';
        }

        if ($highs === []) {
            return self::FALLBACK_PROFILE; // nothing to key on
        }

        $avgHigh = array_sum($highs) / count($highs);

        return match (true) {
            $avgHigh >= self::HOT_HIGH => 'hot',
            ($maxPrecip ?? 0) >= self::WET_PRECIP && $avgHigh < self::WET_HIGH => 'cold-wet',
            $avgHigh < self::COLD_HIGH => 'cold',
            default => 'mild',
        };
    }

    /**
     * Append the associate tag to a base product URL (vendor specifics here only).
     */
    private function tagged(string $url): string
    {
        $tag = (string) config('tripcast.promo.amazon_tag');
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.'tag='.urlencode($tag);
    }
}
