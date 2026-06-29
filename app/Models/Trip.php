<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
