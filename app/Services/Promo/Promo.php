<?php

namespace App\Services\Promo;

/**
 * A selected affiliate promo unit (AD-18). The `slug` is the stable attribution
 * key (promo_events, Story 5.4); `url` is the tagged Amazon URL. Config-derived
 * and not separately persisted beyond the event rows.
 */
final class Promo
{
    public function __construct(
        public readonly string $slug,
        public readonly string $label,
        public readonly string $imageUrl,
        public readonly string $url,
    ) {}
}
