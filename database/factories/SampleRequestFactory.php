<?php

namespace Database\Factories;

use App\Models\SampleRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SampleRequest>
 */
class SampleRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'email' => fake()->safeEmail(),
            'destination' => 'reykjavik',
        ];
    }
}
