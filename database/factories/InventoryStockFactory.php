<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryStock>
 */
class InventoryStockFactory extends Factory
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
            'location_id' => fn (array $attributes) => Location::factory()->state(['business_id' => $attributes['business_id']]),
            'product_variant_id' => fn (array $attributes) => ProductVariant::factory()->state(['business_id' => $attributes['business_id']]),
            'quantity_on_hand' => fake()->randomFloat(3, 0, 100),
        ];
    }
}
