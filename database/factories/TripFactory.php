<?php

namespace Database\Factories;

use App\Models\Trip;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Trip>
 */
class TripFactory extends Factory
{
    /**
     * Default state: an upcoming, active trip owned by a confirmed user. Dates
     * are relative to the (test-pinnable) America/New_York send clock (AD-7), so
     * the default trip is always in the future for whatever "today" is pinned.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $departure = Carbon::now('America/New_York')->addDays(10)->startOfDay();

        return [
            'user_id' => User::factory()->confirmed(),
            'destination_raw' => 'Edinburgh',
            'canonical_place_name' => 'Edinburgh, United Kingdom',
            'latitude' => 55.9533,
            'longitude' => -3.1883,
            'departure_date' => $departure->toDateString(),
            'return_date' => $departure->copy()->addDays(7)->toDateString(),
            'status' => Trip::STATUS_ACTIVE,
        ];
    }

    /**
     * A paused trip — no digests until resumed (AD-5).
     */
    public function paused(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Trip::STATUS_PAUSED,
        ]);
    }

    /**
     * A completed (terminal) trip — shown in the dashboard's past group (AD-5).
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Trip::STATUS_COMPLETED,
        ]);
    }

    /**
     * Dates already in the past on the send clock — useful for grouping/countdown
     * tests. Status is left to the caller (e.g. chain ->completed()).
     */
    public function past(): static
    {
        return $this->state(function (array $attributes) {
            $return = Carbon::now('America/New_York')->subDays(3)->startOfDay();

            return [
                'departure_date' => $return->copy()->subDays(7)->toDateString(),
                'return_date' => $return->toDateString(),
            ];
        });
    }
}
