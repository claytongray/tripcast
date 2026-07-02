<?php

namespace Database\Seeders;

use App\Models\PromoItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Config-fidelity switchover source (FR-26, AD-18). Mirrors
 * `config('tripcast.promo.catalog')` into `promo_items` so Story 8.2 can flip
 * the provider binding with zero attribution discontinuity. Upserts on `slug`,
 * with `sort_order` = the 0-based index within that profile's list — so
 * re-running leaves the row count and every column stable.
 *
 * The neutral/legacy `mild` bucket is re-bucketed into `travel-essentials`
 * (Story 8.4): `WeatherProfiler` never emits `mild`, so a seeded `mild` row
 * would be unreachable under `DatabasePromoProvider`. Routing it to Essentials
 * keeps the demo/fallback catalog fully reachable.
 */
class PromoItemSeeder extends Seeder
{
    public function run(): void
    {
        /** @var array<string, list<array<string, string>>> $catalog */
        $catalog = config('tripcast.promo.catalog', []);

        DB::transaction(function () use ($catalog) {
            foreach ($catalog as $profile => $items) {
                // Neutral `mild` is no longer weather-selectable — route it to the
                // Essentials pool so the seeded item stays reachable (Story 8.4).
                $weatherProfile = $profile === PromoItem::PROFILE_MILD
                    ? PromoItem::PROFILE_ESSENTIALS
                    : $profile;

                foreach ($items as $index => $item) {
                    PromoItem::query()->updateOrCreate(
                        ['slug' => $item['slug']],
                        [
                            'weather_profile' => $weatherProfile,
                            'label' => $item['label'],
                            'image_url' => $item['image'],
                            'url' => $item['url'],
                            'merchant' => PromoItem::MERCHANT_AMAZON,
                            'is_active' => true,
                            'sort_order' => $index,
                        ],
                    );
                }
            }
        });
    }
}
