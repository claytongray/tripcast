<?php

use App\Services\Promo\AffiliatePromoProvider;

function pday(string $date, ?string $condition = 'Sunny', ?int $precip = 10, ?float $highF = 65.0): array
{
    return [
        'date' => $date, 'conditionText' => $condition, 'precipChance' => $precip,
        'highF' => $highF, 'highC' => 18.0, 'lowF' => 50.0, 'lowC' => 10.0,
    ];
}

function psnap(array $days): array
{
    return ['days' => $days, 'limited' => false];
}

function provider(): AffiliatePromoProvider
{
    return app(AffiliatePromoProvider::class);
}

it('maps a snowy snapshot to the snow profile', function () {
    $promo = provider()->select(psnap([pday('2026-07-03', 'Heavy snow', 80, 30.0)]), '2026-07-03');

    expect($promo)->not->toBeNull()
        ->and($promo->slug)->toBeIn(['snow-traction-cleats', 'insulated-gloves']);
});

it('maps a hot snapshot to the hot profile', function () {
    $promo = provider()->select(psnap([pday('2026-07-03', 'Sunny', 5, 92.0)]), '2026-07-03');

    expect($promo->slug)->toBeIn(['packable-sun-hat', 'mineral-sunscreen']);
});

it('maps a cool, wet snapshot to the cold-wet profile', function () {
    $promo = provider()->select(psnap([pday('2026-07-03', 'Rain', 80, 52.0)]), '2026-07-03');

    expect($promo->slug)->toBeIn(['compact-travel-umbrella', 'packable-rain-jacket']);
});

it('maps a mild snapshot to the mild profile', function () {
    $promo = provider()->select(psnap([pday('2026-07-03', 'Partly cloudy', 10, 68.0)]), '2026-07-03');

    expect($promo->slug)->toBe('packing-cubes');
});

it('selects deterministically by send_date', function () {
    $snap = psnap([pday('2026-07-03', 'Heavy snow', 80, 30.0)]); // 2-item profile
    $a = provider()->select($snap, '2026-07-03');
    $b = provider()->select($snap, '2026-07-03');

    expect($a->slug)->toBe($b->slug); // same send_date → same item, always
});

it('falls back to travel-essentials when the mapped profile is empty', function () {
    config(['tripcast.promo.catalog.mild' => []]); // mild now empty
    $promo = provider()->select(psnap([pday('2026-07-03', 'Sunny', 10, 68.0)]), '2026-07-03');

    expect($promo->slug)->toBeIn(['universal-adapter', 'travel-power-bank']);
});

it('returns null when the entire catalog is empty', function () {
    config(['tripcast.promo.catalog' => []]);

    expect(provider()->select(psnap([pday('2026-07-03')]), '2026-07-03'))->toBeNull();
});

it('appends the configured associate tag to the url', function () {
    config(['tripcast.promo.amazon_tag' => 'mytag-99']);
    $promo = provider()->select(psnap([pday('2026-07-03', 'Sunny', 10, 68.0)]), '2026-07-03');

    expect($promo->url)->toContain('tag=mytag-99')
        ->and($promo->url)->toStartWith('https://www.amazon.com/');
});
