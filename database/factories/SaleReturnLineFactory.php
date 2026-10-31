<?php

namespace Database\Factories;

use App\Models\SaleLine;
use App\Models\SaleReturn;
use App\Models\SaleReturnLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleReturnLine>
 */
class SaleReturnLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sale_line_id' => SaleLine::factory(),
            'business_id' => fn (array $attributes): int => SaleLine::query()->findOrFail((int) $attributes['sale_line_id'])->business_id,
            'sale_return_id' => SaleReturn::factory(),
            'sale_line_component_id' => null,
            'serialized_device_id' => null,
            'is_component' => false,
            'quantity' => '1.000',
            'unit_price' => '100.00',
            'unit_cost_snapshot' => '50.00',
            'total' => '100.00',
        ];
    }
}
