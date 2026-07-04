<?php

namespace App\Models;

use Database\Factories\TripFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A watched trip (FR-1/FR-2). Created once with resolved coordinates (AD-8) and
 * an owner (AD-10). Status transitions are owned by the single state-transition
 * method added in its later stories (AD-5); v1 creation defaults to `active`.
 *
 * @property int $id
 * @property int $user_id
 * @property string $destination_raw
 * @property string $canonical_place_name
 * @property float $latitude
 * @property float $longitude
 * @property string|null $destination_timezone
 * @property Carbon $departure_date
 * @property Carbon $return_date
 * @property string $status
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
class Trip extends Model
{
    /** @use HasFactory<TripFactory> */
    use HasFactory;

    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_COMPLETED = 'completed';

    /** @var list<string> */
    protected $fillable = [
        'destination_raw',
        'canonical_place_name',
        'latitude',
        'longitude',
        'destination_timezone',
        'departure_date',
        'return_date',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'departure_date' => 'date',
            'return_date' => 'date',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * The single state-transition surface (AD-5). Every status change — dashboard
     * pause/resume, the email end-trip link, the daily completion sweep, admin —
     * goes through here; no controller/job writes `status` directly. `completed`
     * is **terminal**: no transition leaves it. A no-op transition (same status)
     * is idempotent. An unknown target, or any move off `completed`, throws.
     */
    public function transitionTo(string $status): void
    {
        if (! in_array($status, [self::STATUS_ACTIVE, self::STATUS_PAUSED, self::STATUS_COMPLETED], true)) {
            throw new InvalidTripTransitionException("Unknown trip status: {$status}.");
        }

        if ($this->status === $status) {
            return; // idempotent (covers completed → completed)
        }

        if ($this->status === self::STATUS_COMPLETED) {
            throw new InvalidTripTransitionException('A completed trip is terminal and cannot transition.');
        }

        $this->status = $status;
        $this->save();
    }

    /**
     * Complete this trip (the system sweep + the email end-trip link, AD-5/FR-5).
     * Idempotent — an already-completed trip stays completed.
     */
    public function complete(): void
    {
        $this->transitionTo(self::STATUS_COMPLETED);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Per-send log rows — the source of truth + forecast-history series (AD-9).
     *
     * @return HasMany<EmailLog, $this>
     */
    public function emailLogs(): HasMany
    {
        return $this->hasMany(EmailLog::class);
    }

    /**
     * Admin-triggered send audit rows for this trip (out-of-band, not email_logs).
     *
     * @return HasMany<AdminEmailSend, $this>
     */
    public function adminEmailSends(): HasMany
    {
        return $this->hasMany(AdminEmailSend::class);
    }

    /**
     * One-tap digest reactions, one per send_date (FR-8, AD-9).
     *
     * @return HasMany<Feedback, $this>
     */
    public function feedback(): HasMany
    {
        return $this->hasMany(Feedback::class);
    }
}
