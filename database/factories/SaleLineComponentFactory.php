<?php

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\SaleLine;
use App\Models\SaleLineComponent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleLineComponent>
 */
class SaleLineComponentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => fn (array $attributes): int => SaleLine::query()->findOrFail($attributes['sale_line_id'])->business_id,
            'sale_line_id' => SaleLine::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'serialized_device_id' => null,
            'sku_snapshot' => strtoupper(fake()->bothify('SKU-####')),
            'name_snapshot' => fake()->words(2, true),
            'imei_snapshot' => null,
            'quantity' => '1.000',
            'unit_cost_snapshot' => '50.00',
        ];
    }
}
