<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A one-tap digest reaction (FR-8, AD-9). One row per (trip_id, send_date),
 * upserted last-reaction-wins; the unique index is the idempotency key.
 *
 * @property int $id
 * @property int $trip_id
 * @property Carbon $send_date
 * @property string $reaction
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Trip $trip
 */
class Feedback extends Model
{
    // Eloquent would otherwise pluralize to `feedbacks`.
    protected $table = 'feedback';

    public const REACTION_HELPED = 'helped';

    public const REACTION_NOT_HELPFUL = 'not_helpful';

    /** @var list<string> */
    protected $fillable = [
        'trip_id',
        'send_date',
        'reaction',
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
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}
