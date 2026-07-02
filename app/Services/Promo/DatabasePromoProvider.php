<?php

namespace App\Services\Promo;

use App\Models\PromoItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * DB-backed promo adapter (Epic 8, AD-18). Selects from the admin-managed
 * `promo_items` catalog with precedence Featured → weather profile → Essentials,
 * preserving the deterministic per-send_date rotation of AffiliatePromoProvider.
 * While the catalog is unseeded (empty table) it delegates to the config adapter
 * so the digest slot is never silently blank. Read-only.
 */
class DatabasePromoProvider implements PromoProvider
{
    public function __construct(
        private readonly AmazonAffiliateTagger $tagger,
        private readonly WeatherProfiler $profiler,
        private readonly AffiliatePromoProvider $fallback,
    ) {}

    public function select(array $snapshot, string $sendDate): ?Promo
    {
        // Never blank before the catalog is seeded (AD-18 switchover safety).
        if (! PromoItem::query()->exists()) {
            return $this->fallback->select($snapshot, $sendDate);
        }

        // Precedence: Featured, then the weather-profile pool, then Essentials.
        $featured = $this->pick($this->pool(fn ($q) => $q->active()->featuredOn($sendDate)), $sendDate);
        if ($featured !== null) {
            return $featured;
        }

        $profile = $this->profiler->profile($snapshot);
        if ($profile !== null) {
            $profilePromo = $this->pick($this->pool(fn ($q) => $q->active()->forProfile($profile)), $sendDate);
            if ($profilePromo !== null) {
                return $profilePromo;
            }
        }

        return $this->pick(
            $this->pool(fn ($q) => $q->active()->forProfile(PromoItem::PROFILE_ESSENTIALS)),
            $sendDate,
        );
    }

    public function findBySlug(string $slug): ?Promo
    {
        // withTrashed + no is_active filter: a retired item's click link stays live.
        $item = PromoItem::withTrashed()->where('slug', $slug)->first();

        if ($item instanceof PromoItem) {
            return $this->toPromo($item);
        }

        return $this->fallback->findBySlug($slug);
    }

    /**
     * An ordered candidate pool. `(sort_order asc, slug asc)` is the stable
     * ordering the deterministic rotation depends on (never `id`).
     *
     * @param  callable(Builder<PromoItem>): mixed  $filter
     * @return Collection<int, PromoItem>
     */
    private function pool(callable $filter): Collection
    {
        $query = PromoItem::query();
        $filter($query);

        return $query->orderBy('sort_order')->orderBy('slug')->get();
    }

    /**
     * Deterministic pick from a pool: stable for a given send_date so a re-render
     * selects the same item (AD-3/AD-18). Null when the pool is empty.
     *
     * @param  Collection<int, PromoItem>  $pool
     */
    private function pick(Collection $pool, string $sendDate): ?Promo
    {
        if ($pool->isEmpty()) {
            return null;
        }

        $ordered = $pool->values();
        $item = $ordered->get(crc32($sendDate) % $ordered->count());

        return $item instanceof PromoItem ? $this->toPromo($item) : null;
    }

    private function toPromo(PromoItem $item): Promo
    {
        return new Promo(
            slug: $item->slug,
            label: $item->label,
            imageUrl: $item->image_url,
            url: $item->merchant === PromoItem::MERCHANT_AMAZON
                ? $this->tagger->tag($item->url)
                : $item->url,
        );
    }
}
