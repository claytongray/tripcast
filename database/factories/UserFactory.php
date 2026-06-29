<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'free',
            'timezone' => 'America/New_York',
            'is_admin' => false,
            'email_opted_out' => false,
        ];
    }

    /**
     * Indicate that the user is an administrator (AD-12).
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_admin' => true,
        ]);
    }

    /**
     * Indicate that the user has the ad-free plan (AD-19).
     */
    public function adFree(): static
    {
        return $this->state(fn (array $attributes) => [
            'plan' => 'ad_free',
        ]);
    }

    /**
     * Indicate that the user has opted out of all email (AD-13).
     */
    public function optedOut(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_opted_out' => true,
        ]);
    }
}
