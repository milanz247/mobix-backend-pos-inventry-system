<?php

namespace Database\Factories;

use App\CatalogStatus;
use App\Models\Brand;
use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Brand>
 */
class BrandFactory extends Factory
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
            'name' => fake()->unique()->company(),
            'description' => fake()->optional()->sentence(),
            'logo_path' => null,
            'status' => CatalogStatus::Active,
        ];
    }
}
