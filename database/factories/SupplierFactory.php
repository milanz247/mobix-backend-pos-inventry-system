<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Supplier;
use App\SupplierStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
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
            'name' => fake()->company(),
            'contact_person' => fake()->name(),
            'email' => fake()->optional()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'alternate_phone' => null,
            'address' => fake()->optional()->address(),
            'city' => fake()->optional()->city(),
            'notes' => null,
            'status' => SupplierStatus::Active,
        ];
    }
}
