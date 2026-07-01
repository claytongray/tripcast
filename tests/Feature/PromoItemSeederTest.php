<?php

use App\Models\PromoItem;
use Database\Seeders\PromoItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array<string, list<array<string, string>>>
 */
function promoCatalog(): array
{
    /** @var array<string, list<array<string, string>>> $catalog */
    $catalog = config('tripcast.promo.catalog', []);

    return $catalog;
}

it('seeds exactly the config catalog items', function () {
    $this->seed(PromoItemSeeder::class);

    $expected = 0;
    foreach (promoCatalog() as $items) {
        $expected += count($items);
    }

    expect(PromoItem::count())->toBe($expected);
});

it('maps each item to its config profile, merchant, and active flag', function () {
    $this->seed(PromoItemSeeder::class);

    foreach (promoCatalog() as $profile => $items) {
        foreach ($items as $index => $item) {
            $row = PromoItem::query()->where('slug', $item['slug'])->firstOrFail();

            expect($row->weather_profile)->toBe($profile)
                ->and($row->label)->toBe($item['label'])
                ->and($row->image_url)->toBe($item['image'])
                ->and($row->url)->toBe($item['url'])
                ->and($row->merchant)->toBe(PromoItem::MERCHANT_AMAZON)
                ->and($row->is_active)->toBeTrue()
                ->and($row->sort_order)->toBe($index);
        }
    }
});

it('preserves the mild and travel-essentials profiles', function () {
    $this->seed(PromoItemSeeder::class);

    expect(PromoItem::query()->where('weather_profile', PromoItem::PROFILE_MILD)->exists())->toBeTrue()
        ->and(PromoItem::query()->where('weather_profile', PromoItem::PROFILE_ESSENTIALS)->count())->toBe(2);
});

it('seeds unique slugs', function () {
    $this->seed(PromoItemSeeder::class);

    $slugs = PromoItem::query()->pluck('slug');

    expect($slugs->count())->toBe($slugs->unique()->count());
});

it('is idempotent across a re-run', function () {
    $this->seed(PromoItemSeeder::class);

    $countBefore = PromoItem::count();
    $before = PromoItem::query()->where('slug', 'packing-cubes')->firstOrFail()->only([
        'weather_profile', 'label', 'image_url', 'url', 'merchant', 'is_active', 'sort_order',
    ]);

    $this->seed(PromoItemSeeder::class);

    $after = PromoItem::query()->where('slug', 'packing-cubes')->firstOrFail()->only([
        'weather_profile', 'label', 'image_url', 'url', 'merchant', 'is_active', 'sort_order',
    ]);

    expect(PromoItem::count())->toBe($countBefore)
        ->and($after)->toBe($before);
});
