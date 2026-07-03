<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\PromoItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A DB-backed weather-keyed promo (FR-26, AD-18). The Epic 8 catalog: one row
 * per admin-managed recommendation. `slug` is the stable attribution key that
 * `promo_events.promo_slug` joins against, so it is unique across soft-deleted
 * rows. `weather_profile` is drawn from the fixed taxonomy admins may not
 * extend; a Featured window (`featured_from`/`featured_to`, NULL-to = open-ended)
 * pins an item ahead of the profile rotation (Story 8.2 precedence).
 *
 * @property int $id
 * @property string $slug
 * @property string $label
 * @property string|null $description
 * @property string|null $image_url
 * @property string $url
 * @property string $merchant
 * @property string $weather_profile
 * @property bool $is_active
 * @property Carbon|null $featured_from
 * @property Carbon|null $featured_to
 * @property int $sort_order
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PromoItem extends Model
{
    /** @use HasFactory<PromoItemFactory> */
    use HasFactory;

    use SoftDeletes;

    public const MERCHANT_AMAZON = 'amazon';

    public const MERCHANT_OTHER = 'other';

    /** @var list<string> */
    public const MERCHANTS = [
        self::MERCHANT_AMAZON,
        self::MERCHANT_OTHER,
    ];

    public const PROFILE_SNOW = 'snow';

    public const PROFILE_HOT = 'hot';

    public const PROFILE_RAIN = 'rain';

    public const PROFILE_COLD_WET = 'cold-wet';

    public const PROFILE_COLD = 'cold';

    public const PROFILE_MILD = 'mild';

    public const PROFILE_ESSENTIALS = 'travel-essentials';

    /**
     * The fixed weather taxonomy (FR-26). Admins manage items, not this list.
     *
     * @var list<string>
     */
    public const PROFILES = [
        self::PROFILE_SNOW,
        self::PROFILE_HOT,
        self::PROFILE_RAIN,
        self::PROFILE_COLD_WET,
        self::PROFILE_COLD,
        self::PROFILE_MILD,
        self::PROFILE_ESSENTIALS,
    ];

    /**
     * Admin-managed columns only — nothing user-supplied is mass-assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'label',
        'description',
        'image_url',
        'url',
        'merchant',
        'weather_profile',
        'is_active',
        'featured_from',
        'featured_to',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'featured_from' => 'date',
            'featured_to' => 'date',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Active (published) items only.
     *
     * @param  Builder<PromoItem>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Items keyed to a single weather profile (Story 8.2 rotation).
     *
     * @param  Builder<PromoItem>  $query
     */
    public function scopeForProfile(Builder $query, string $profile): void
    {
        $query->where('weather_profile', $profile);
    }

    /**
     * Items whose Featured window covers the given date. A NULL `featured_to`
     * is an open-ended pin; a NULL `featured_from` is not Featured at all.
     *
     * @param  Builder<PromoItem>  $query
     */
    public function scopeFeaturedOn(Builder $query, CarbonInterface|string $date): void
    {
        $query->whereNotNull('featured_from')
            ->whereDate('featured_from', '<=', $date)
            ->where(function (Builder $inner) use ($date) {
                $inner->whereNull('featured_to')
                    ->orWhereDate('featured_to', '>=', $date);
            });
    }

    /**
     * Attribution events for this item, joined on the stable slug (Story 8.5).
     * Non-standard key pair; reads use `withTrashed()` since the slug spans
     * soft-deleted rows.
     *
     * @return HasMany<PromoEvent, $this>
     */
    public function promoEvents(): HasMany
    {
        return $this->hasMany(PromoEvent::class, 'promo_slug', 'slug');
    }
}
