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
        // The neutral/legacy `mild` bucket is re-bucketed into Essentials so no
        // seeded item is unreachable under DatabasePromoProvider (Story 8.4).
        $expectedProfile = $profile === PromoItem::PROFILE_MILD
            ? PromoItem::PROFILE_ESSENTIALS
            : $profile;

        foreach ($items as $index => $item) {
            $row = PromoItem::query()->where('slug', $item['slug'])->firstOrFail();

            expect($row->weather_profile)->toBe($expectedProfile)
                ->and($row->label)->toBe($item['label'])
                ->and($row->image_url)->toBe($item['image'])
                ->and($row->url)->toBe($item['url'])
                ->and($row->merchant)->toBe(PromoItem::MERCHANT_AMAZON)
                ->and($row->is_active)->toBeTrue()
                ->and($row->sort_order)->toBe($index);
        }
    }
});

it('re-buckets the config mild item into Essentials so nothing is unreachable', function () {
    $this->seed(PromoItemSeeder::class);

    // No row keeps the neutral `mild` weather profile — the provider never
    // queries it, so it would be stranded.
    expect(PromoItem::query()->where('weather_profile', PromoItem::PROFILE_MILD)->exists())->toBeFalse();

    // The two config Essentials items plus the re-bucketed `packing-cubes`.
    expect(PromoItem::query()->where('weather_profile', PromoItem::PROFILE_ESSENTIALS)->count())->toBe(3)
        ->and(PromoItem::query()->where('weather_profile', PromoItem::PROFILE_ESSENTIALS)->pluck('slug'))
        ->toContain('packing-cubes');
});

it('seeds unique slugs', function () {
    $this->seed(PromoItemSeeder::class);

    $slugs = PromoItem::query()->pluck('slug');

    expect($slugs->count())->toBe($slugs->unique()->count());
});

it('updates a retired config slug in place instead of colliding on the unique index', function () {
    // A config slug that was soft-deleted: the unique index spans trashed rows,
    // so a naive insert would throw. The seeder must match it withTrashed.
    PromoItem::factory()->trashed()->create(['slug' => 'packing-cubes', 'label' => 'stale']);

    $this->seed(PromoItemSeeder::class); // must not throw

    $row = PromoItem::withTrashed()->where('slug', 'packing-cubes')->firstOrFail();
    expect($row->label)->toBe('Compression packing cubes') // updated from config
        ->and(PromoItem::withTrashed()->where('slug', 'packing-cubes')->count())->toBe(1);
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
