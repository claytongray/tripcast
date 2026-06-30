<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One affiliate-engagement event (FR-18, AD-18). Append-only and idempotent per
 * (trip_id, send_date, promo_slug, event) — recording is via firstOrCreate so a
 * re-click or mail-client prefetch never double-logs.
 *
 * @property int $id
 * @property int $trip_id
 * @property int $user_id
 * @property Carbon $send_date
 * @property string $promo_slug
 * @property string $event
 */
class PromoEvent extends Model
{
    public const EVENT_IMPRESSION = 'impression';

    public const EVENT_CLICK = 'click';

    /** @var list<string> */
    protected $fillable = [
        'trip_id',
        'user_id',
        'send_date',
        'promo_slug',
        'event',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'send_date' => 'date',
        ];
    }

    /**
     * Idempotently record an event for a trip's send (AD-18). The unique key
     * makes a re-record (reclaim, prefetch, double-click) a no-op.
     */
    public static function record(Trip $trip, string $sendDate, string $slug, string $event): void
    {
        self::query()->firstOrCreate(
            ['trip_id' => $trip->id, 'send_date' => $sendDate, 'promo_slug' => $slug, 'event' => $event],
            ['user_id' => $trip->user_id],
        );
    }

    /**
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
