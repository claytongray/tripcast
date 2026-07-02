<?php

use App\Models\PromoItem;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-07-01 12:00:00');
});

it('casts is_active, dates, and sort_order', function () {
    $item = PromoItem::factory()->create([
        'is_active' => 1,
        'sort_order' => '3',
        'featured_from' => '2026-07-01',
        'featured_to' => '2026-07-10',
    ]);

    $fresh = $item->fresh();

    expect($fresh->is_active)->toBeBool()->toBeTrue()
        ->and($fresh->sort_order)->toBeInt()->toBe(3)
        ->and($fresh->featured_from)->toBeInstanceOf(CarbonInterface::class)
        ->and($fresh->featured_to)->toBeInstanceOf(CarbonInterface::class);
});

it('scopeActive excludes inactive items', function () {
    $active = PromoItem::factory()->create();
    PromoItem::factory()->inactive()->create();

    $ids = PromoItem::query()->active()->pluck('id');

    expect($ids->all())->toBe([$active->id]);
});

it('scopeForProfile filters by weather profile', function () {
    $snow = PromoItem::factory()->forProfile(PromoItem::PROFILE_SNOW)->create();
    PromoItem::factory()->forProfile(PromoItem::PROFILE_HOT)->create();

    $ids = PromoItem::query()->forProfile(PromoItem::PROFILE_SNOW)->pluck('id');

    expect($ids->all())->toBe([$snow->id]);
});

it('scopeFeaturedOn matches a bounded pin covering the date', function () {
    $item = PromoItem::factory()->featured('2026-06-25', '2026-07-10')->create();

    $ids = PromoItem::query()->featuredOn('2026-07-01')->pluck('id');

    expect($ids->all())->toBe([$item->id]);
});

it('scopeFeaturedOn matches an open-ended pin covering the date', function () {
    $item = PromoItem::factory()->featured('2026-06-25', null)->create();

    $ids = PromoItem::query()->featuredOn('2026-07-01')->pluck('id');

    expect($ids->all())->toBe([$item->id]);
});

it('scopeFeaturedOn excludes a lapsed pin', function () {
    PromoItem::factory()->featured('2026-06-01', '2026-06-20')->create();

    expect(PromoItem::query()->featuredOn('2026-07-01')->exists())->toBeFalse();
});

it('scopeFeaturedOn excludes a not-yet-started pin', function () {
    PromoItem::factory()->featured('2026-07-15', null)->create();

    expect(PromoItem::query()->featuredOn('2026-07-01')->exists())->toBeFalse();
});

it('other() state uses a verbatim non-amazon url', function () {
    $item = PromoItem::factory()->other('https://rei.com/product/123')->create();

    expect($item->merchant)->toBe(PromoItem::MERCHANT_OTHER)
        ->and($item->url)->toBe('https://rei.com/product/123');
});

it('reserves a soft-deleted slug against reuse', function () {
    $item = PromoItem::factory()->create(['slug' => 'retired-item']);
    $item->delete();

    expect($item->trashed())->toBeTrue();

    PromoItem::factory()->create(['slug' => 'retired-item']);
})->throws(QueryException::class);
