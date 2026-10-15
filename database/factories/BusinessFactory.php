<?php

namespace Database\Factories;

use App\BusinessStatus;
use App\BusinessType;
use App\Models\Business;
use App\Models\User;
use App\TimeFormat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Business>
 */
class BusinessFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'name' => fake()->company(),
            'business_type' => fake()->randomElement(BusinessType::cases()),
            'email' => fake()->optional()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'country' => 'Sri Lanka',
            'currency' => 'LKR',
            'timezone' => 'Asia/Colombo',
            'time_format' => TimeFormat::TwelveHour,
            'logo_path' => null,
            'status' => BusinessStatus::Active,
        ];
    }
}
