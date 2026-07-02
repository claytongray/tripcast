<?php

namespace App\Models;

use Database\Factories\SampleRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One accepted sample-tripcast request. Each send writes a row; "how many
 * sent" = row count, "who asked" = distinct user_id. `source` separates the
 * public landing funnel (acquisition) from signed-in dashboard self-sends
 * (engagement) — funnel metrics count only landing rows.
 *
 * @property int $id
 * @property int $user_id
 * @property string $email
 * @property string $destination
 * @property string $source
 */
#[Fillable(['user_id', 'email', 'destination', 'source'])]
class SampleRequest extends Model
{
    public const SOURCE_LANDING = 'landing';

    public const SOURCE_DASHBOARD = 'dashboard';

    /** @use HasFactory<SampleRequestFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
