<?php

namespace App\Models;

use Database\Factories\SampleRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One accepted public sample-tripcast request (acquisition tracking). Each send
 * writes a row; "how many sent" = row count, "who asked" = distinct user_id.
 *
 * @property int $id
 * @property int $user_id
 * @property string $email
 * @property string $destination
 */
#[Fillable(['user_id', 'email', 'destination'])]
class SampleRequest extends Model
{
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
