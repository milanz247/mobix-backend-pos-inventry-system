<?php

namespace Database\Factories;

use App\CatalogStatus;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductVariant;
use App\ProductCondition;
use App\VariantTrackingMode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
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
            'product_id' => fn (array $attributes) => Product::factory()->state([
                'business_id' => $attributes['business_id'],
            ]),
            'name' => 'Default',
            'sku' => strtoupper(fake()->unique()->bothify('SKU-########')),
            'barcode' => null,
            'model_number' => null,
            'storage_capacity' => null,
            'color' => null,
            'variant_attributes' => null,
            'condition' => null,
            'tracking_mode' => VariantTrackingMode::QuantityBased,
            'unit' => 'piece',
            'cost_price' => fake()->randomFloat(2, 0, 100000),
            'selling_price' => fake()->randomFloat(2, 0, 150000),
            'minimum_selling_price' => null,
            'status' => CatalogStatus::Active,
        ];
    }

    public function serialized(?ProductCondition $condition = null): static
    {
        return $this->state([
            'tracking_mode' => VariantTrackingMode::Serialized,
            'condition' => $condition ?? ProductCondition::New,
        ]);
    }
}
