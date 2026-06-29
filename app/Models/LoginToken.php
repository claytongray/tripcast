<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Single-use magic-link login token (AD-6).
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
class LoginToken extends Model
{
    use Prunable;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'token_hash',
        'expires_at',
        'consumed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether the token is still usable: not consumed and not expired.
     */
    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }

    /**
     * Scope to tokens that are expired or already consumed (pruning target).
     *
     * @param  Builder<LoginToken>  $query
     */
    public function scopeSpent(Builder $query): void
    {
        $query->where(function (Builder $q) {
            $q->whereNotNull('consumed_at')->orWhere('expires_at', '<', now());
        });
    }

    /**
     * Expired/consumed tokens are pruned on a schedule (AD-6 convention).
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->spent();
    }
}
