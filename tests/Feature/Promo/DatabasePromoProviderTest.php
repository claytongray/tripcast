<?php

use App\Models\PromoItem;
use App\Services\Promo\DatabasePromoProvider;
use App\Services\Promo\PromoProvider;

beforeEach(function () {
    $this->provider = app(DatabasePromoProvider::class);
});

// Snapshot shapes (see WeatherProfiler): each day has highF/precipChance/conditionText.
function promoSnap(array $days): array
{
    return ['days' => $days, 'limited' => false];
}
function promoHotDays(): array
{
    return [['highF' => 85.0, 'precipChance' => 10, 'conditionText' => 'Sunny'], ['highF' => 88.0, 'precipChance' => 5, 'conditionText' => 'Clear']];
}
function promoMildDays(): array
{
    return [['highF' => 60.0, 'precipChance' => 10, 'conditionText' => 'Cloudy'], ['highF' => 65.0, 'precipChance' => 10, 'conditionText' => 'Cloudy']];
}

it('binds DatabasePromoProvider by default', function () {
    expect(app(PromoProvider::class))->toBeInstanceOf(DatabasePromoProvider::class);
});

it('prefers a Featured item over the weather-profile pool', function () {
    PromoItem::factory()->forProfile('hot')->create(['slug' => 'hot-item']);
    PromoItem::factory()->featured('2026-06-01', null)->create(['slug' => 'featured-item']);

    expect($this->provider->select(promoSnap(promoHotDays()), '2026-07-03')->slug)->toBe('featured-item');
});

it('uses the weather-profile pool over Essentials, and Essentials when the profile pool is empty', function () {
    PromoItem::factory()->forProfile('hot')->create(['slug' => 'hot-item']);
    PromoItem::factory()->essentials()->create(['slug' => 'ess-item']);
    expect($this->provider->select(promoSnap(promoHotDays()), '2026-07-03')->slug)->toBe('hot-item');

    PromoItem::query()->where('slug', 'hot-item')->delete();
    expect($this->provider->select(promoSnap(promoHotDays()), '2026-07-03')->slug)->toBe('ess-item');
});

it('routes neutral and low-signal weather to Essentials', function () {
    PromoItem::factory()->forProfile('hot')->create(['slug' => 'hot-item']);
    PromoItem::factory()->essentials()->create(['slug' => 'ess-item']);

    // mild → profile null → Essentials
    expect($this->provider->select(promoSnap(promoMildDays()), '2026-07-03')->slug)->toBe('ess-item');
    // < 2 usable days → Essentials
    expect($this->provider->select(promoSnap([['highF' => 85.0, 'precipChance' => 5, 'conditionText' => 'Sunny']]), '2026-07-03')->slug)->toBe('ess-item');
});

it('picks deterministically by send_date over (sort_order, slug)', function () {
    PromoItem::factory()->essentials()->create(['slug' => 'ess-a', 'sort_order' => 0]);
    PromoItem::factory()->essentials()->create(['slug' => 'ess-b', 'sort_order' => 1]);
    PromoItem::factory()->essentials()->create(['slug' => 'ess-c', 'sort_order' => 2]);

    $sendDate = '2026-07-03';
    $expected = ['ess-a', 'ess-b', 'ess-c'][crc32($sendDate) % 3];

    expect($this->provider->select(promoSnap(promoMildDays()), $sendDate)->slug)->toBe($expected)
        ->and($this->provider->select(promoSnap(promoMildDays()), $sendDate)->slug)->toBe($expected); // stable
});

it('tags amazon urls and leaves other merchants verbatim', function () {
    PromoItem::factory()->essentials()->create(['slug' => 'amz', 'url' => 'https://www.amazon.com/dp/B01']);
    expect($this->provider->select(promoSnap(promoMildDays()), '2026-07-03')->url)->toContain('tag=');

    PromoItem::query()->delete();
    PromoItem::factory()->essentials()->other('https://www.rei.com/product/x')->create(['slug' => 'other']);
    expect($this->provider->select(promoSnap(promoMildDays()), '2026-07-03')->url)->toBe('https://www.rei.com/product/x');
});

it('honors an open-ended Featured window into the future', function () {
    PromoItem::factory()->featured('2026-06-01', null)->create(['slug' => 'pinned']);
    expect($this->provider->select(promoSnap(promoMildDays()), '2027-01-01')->slug)->toBe('pinned');

    // A closed window that does not cover the date is not Featured. Pin the
    // lapsed item to a non-essentials profile so it can only reach the slot via
    // the (expired) Featured window — otherwise a random travel-essentials
    // profile would legitimately place it in the Essentials pool.
    PromoItem::query()->delete();
    PromoItem::factory()->featured('2026-06-01', '2026-06-10')->forProfile(PromoItem::PROFILE_SNOW)->create(['slug' => 'expired']);
    PromoItem::factory()->essentials()->create(['slug' => 'ess']);
    expect($this->provider->select(promoSnap(promoMildDays()), '2026-07-03')->slug)->toBe('ess');
});

it('returns null when the table is non-empty but no pool has an active match', function () {
    // One inactive row keeps the table non-empty (so the config fallback does
    // NOT fire), yet every pool is empty → null (not a fabricated slot).
    PromoItem::factory()->inactive()->essentials()->create(['slug' => 'dormant']);

    expect($this->provider->select(promoSnap(promoMildDays()), '2026-07-03'))->toBeNull();
});

it('excludes an inactive Featured pin, falling through to Essentials', function () {
    PromoItem::factory()->featured('2026-06-01', null)->inactive()->create(['slug' => 'pinned-off']);
    PromoItem::factory()->essentials()->create(['slug' => 'ess']);

    expect($this->provider->select(promoSnap(promoMildDays()), '2026-07-03')->slug)->toBe('ess');
});

it('excludes an inactive weather-profile item, falling through to Essentials', function () {
    PromoItem::factory()->forProfile(PromoItem::PROFILE_HOT)->inactive()->create(['slug' => 'hot-off']);
    PromoItem::factory()->essentials()->create(['slug' => 'ess']);

    // A hot snapshot maps to the hot profile, but the only hot item is inactive.
    expect($this->provider->select(promoSnap(promoHotDays()), '2026-07-03')->slug)->toBe('ess');
});

it('resolves a soft-deleted item for the click redirect (findBySlug withTrashed)', function () {
    PromoItem::factory()->trashed()->create(['slug' => 'gone', 'label' => 'Retired thing']);

    $promo = $this->provider->findBySlug('gone');
    expect($promo)->not->toBeNull()->and($promo->slug)->toBe('gone');
});

it('falls back to the config catalog while promo_items is empty', function () {
    // No promo_items seeded → select + findBySlug delegate to the config adapter.
    expect($this->provider->select(promoSnap(promoHotDays()), '2026-07-03'))->not->toBeNull()
        ->and($this->provider->findBySlug('universal-adapter'))->not->toBeNull();
});
