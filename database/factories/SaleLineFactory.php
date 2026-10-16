<?php

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleLine;
use App\VariantTrackingMode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleLine>
 */
class SaleLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => fn (array $attributes): int => Sale::query()->findOrFail($attributes['sale_id'])->business_id,
            'sale_id' => Sale::factory(),
            'product_variant_id' => fn (array $attributes): int => ProductVariant::factory()->create(['business_id' => $attributes['business_id']])->id,
            'serialized_device_id' => null,
            'product_name_snapshot' => fake()->words(2, true),
            'variant_name_snapshot' => 'Default',
            'sku_snapshot' => strtoupper(fake()->bothify('SKU-####')),
            'barcode_snapshot' => null,
            'tracking_mode' => VariantTrackingMode::QuantityBased,
            'is_combo' => false,
            'imei_snapshot' => null,
            'quantity' => '1.000',
            'unit_price' => '100.00',
            'discount_amount' => '0.00',
            'cost_snapshot' => '50.00',
            'subtotal' => '100.00',
            'total' => '100.00',
        ];
    }
}
