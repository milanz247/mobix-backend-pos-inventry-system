<?php

namespace Database\Factories;

use App\LocationStatus;
use App\LocationType;
use App\Models\Business;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => fake()->company().' Branch',
            'code' => strtoupper(fake()->unique()->bothify('LOC-###')),
            'type' => fake()->randomElement(LocationType::cases()),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->optional()->companyEmail(),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'is_main' => false,
            'status' => LocationStatus::Active,
        ];
    }
}
