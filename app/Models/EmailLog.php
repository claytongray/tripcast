<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The single per-send source of truth + forecast-history time-series (AD-9).
 * One row per (trip_id, send_date); the unique index is the claim authority (AD-3).
 *
 * @property int $id
 * @property int $trip_id
 * @property Carbon $send_date
 * @property string $status
 * @property Carbon|null $claimed_at
 * @property string|null $failure_reason
 * @property array<string, mixed>|null $weather_snapshot
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Trip $trip
 */
class EmailLog extends Model
{
    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    protected $fillable = [
        'trip_id',
        'send_date',
        'status',
        'claimed_at',
        'failure_reason',
        'weather_snapshot',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'send_date' => 'date',
            'claimed_at' => 'datetime',
            'weather_snapshot' => 'array',
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
