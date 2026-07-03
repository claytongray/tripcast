<?php

namespace App\Services\Promo;

/**
 * A selected affiliate promo unit (AD-18). The `slug` is the stable attribution
 * key (promo_events, Story 5.4); `url` is the tagged Amazon URL. `imageUrl` is
 * legacy-nullable (the admin form stopped collecting images, 2026-07-03 spec)
 * and `description` is the optional one-line editorial copy under the label.
 */
final class Promo
{
    public function __construct(
        public readonly string $slug,
        public readonly string $label,
        public readonly ?string $imageUrl,
        public readonly string $url,
        public readonly ?string $description = null,
    ) {}
}
