<?php

namespace Database\Seeders;

use App\Models\PromoItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Config-fidelity switchover source (FR-26, AD-18). Mirrors
 * `config('tripcast.promo.catalog')` into `promo_items` so Story 8.2 can flip
 * the provider binding with zero attribution discontinuity. Upserts on `slug`,
 * preserving `weather_profile` = the config profile key (including `mild` and
 * `travel-essentials`) and `sort_order` = the 0-based index within that
 * profile's list — so re-running leaves the row count and every column stable.
 */
class PromoItemSeeder extends Seeder
{
    public function run(): void
    {
        /** @var array<string, list<array<string, string>>> $catalog */
        $catalog = config('tripcast.promo.catalog', []);

        DB::transaction(function () use ($catalog) {
            foreach ($catalog as $profile => $items) {
                foreach ($items as $index => $item) {
                    PromoItem::query()->updateOrCreate(
                        ['slug' => $item['slug']],
                        [
                            'weather_profile' => $profile,
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
