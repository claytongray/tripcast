<?php

namespace App\Services\Promo;

/**
 * Appends the Amazon associate tag to a base product URL (AD-18). The one place
 * the vendor tag lives — shared by AffiliatePromoProvider (config catalog) and
 * DatabasePromoProvider (amazon-merchant items).
 */
class AmazonAffiliateTagger
{
    public function tag(string $url): string
    {
        $tag = (string) config('tripcast.promo.amazon_tag');
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.'tag='.urlencode($tag);
    }
}
