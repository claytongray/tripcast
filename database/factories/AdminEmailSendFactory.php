<?php

namespace Database\Factories;

use App\Models\AdminEmailSend;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdminEmailSend>
 */
class AdminEmailSendFactory extends Factory
{
    protected $model = AdminEmailSend::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trip_id' => Trip::factory(),
            'admin_user_id' => User::factory()->admin(),
            'recipient' => AdminEmailSend::RECIPIENT_ADMIN,
            'recipient_email' => fake()->safeEmail(),
            'status' => AdminEmailSend::STATUS_SENT,
            'failure_reason' => null,
        ];
    }
}
