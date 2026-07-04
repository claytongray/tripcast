<?php

namespace App\Models;

use Database\Factories\AdminEmailSendFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Audit trail for admin-triggered digest sends — deliberately separate from
 * `email_logs` (AD-3): it never collides with the sacred (trip_id, send_date)
 * dedup index and is invisible to send-health metrics (AD-9). One row per admin
 * trigger, capturing who sent what to whom and the outcome.
 *
 * @property int $id
 * @property int $trip_id
 * @property int $admin_user_id
 * @property string $recipient
 * @property string $recipient_email
 * @property string $status
 * @property string|null $failure_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AdminEmailSend extends Model
{
    /** @use HasFactory<AdminEmailSendFactory> */
    use HasFactory;

    public const RECIPIENT_OWNER = 'owner';

    public const RECIPIENT_ADMIN = 'admin';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    protected $fillable = [
        'trip_id',
        'admin_user_id',
        'recipient',
        'recipient_email',
        'status',
        'failure_reason',
    ];

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
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }
}
