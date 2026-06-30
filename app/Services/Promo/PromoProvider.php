<?php

namespace App\Services\Promo;

/**
 * The promo selection port (AD-18, exactly like AD-1/AD-17). Code depends on
 * this interface; the concrete adapter (and all vendor specifics — the Amazon
 * host, the associate tag) lives behind it. Returns one selected promo for the
 * secured snapshot + send_date, or null when even the fallback catalog is empty.
 */
interface PromoProvider
{
    /**
     * @param  array<string, mixed>  $snapshot  the secured weather snapshot
     */
    public function select(array $snapshot, string $sendDate): ?Promo;

    /**
     * Resolve a promo by its stable slug (the click-redirect target lookup), or
     * null when the slug is unknown. Returns the tagged Amazon URL.
     */
    public function findBySlug(string $slug): ?Promo;
}
