<?php

namespace Database\Factories;

use App\Models\PromoItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<PromoItem>
 */
class PromoItemFactory extends Factory
{
    /**
     * Default state: an active Amazon item on a random profile, not Featured.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => $this->faker->unique()->slug(3),
            'label' => $this->faker->words(3, true),
            'image_url' => 'https://placehold.co/120x120?text=Item',
            'url' => 'https://www.amazon.com/dp/B000'.strtoupper($this->faker->bothify('#####')),
            'merchant' => PromoItem::MERCHANT_AMAZON,
            'weather_profile' => $this->faker->randomElement(PromoItem::PROFILES),
            'is_active' => true,
            'featured_from' => null,
            'featured_to' => null,
            'sort_order' => 0,
        ];
    }

    /**
     * An unpublished item (excluded by the active scope).
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Pin the item to a specific weather profile.
     */
    public function forProfile(string $profile): static
    {
        return $this->state(fn (array $attributes) => [
            'weather_profile' => $profile,
        ]);
    }

    /**
     * The Essentials fallback pool (travel-essentials profile).
     */
    public function essentials(): static
    {
        return $this->state(fn (array $attributes) => [
            'weather_profile' => PromoItem::PROFILE_ESSENTIALS,
        ]);
    }

    /**
     * A non-Amazon merchant whose URL is used verbatim (no tag appended).
     */
    public function other(string $url): static
    {
        return $this->state(fn (array $attributes) => [
            'merchant' => PromoItem::MERCHANT_OTHER,
            'url' => $url,
        ]);
    }

    /**
     * A Featured item. `$from` defaults to today on the NY send clock (AD-7);
     * a null `$to` leaves the pin open-ended.
     */
    public function featured(?string $from = null, ?string $to = null): static
    {
        return $this->state(fn (array $attributes) => [
            'featured_from' => $from ?? Carbon::now('America/New_York')->toDateString(),
            'featured_to' => $to,
        ]);
    }

    /**
     * A soft-deleted (retired) item whose slug stays reserved.
     */
    public function trashed(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => Carbon::now('America/New_York'),
        ]);
    }
}
