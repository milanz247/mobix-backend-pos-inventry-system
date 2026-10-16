<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\InventoryOperation;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\SerializedDevice;
use App\Models\User;
use App\ProductCondition;
use App\SerializedDeviceStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SerializedDevice>
 */
class SerializedDeviceFactory extends Factory
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
            'product_variant_id' => ProductVariant::factory(),
            'location_id' => fn (array $attributes) => Location::factory()->state(['business_id' => $attributes['business_id']]),
            'received_operation_id' => fn (array $attributes) => InventoryOperation::factory()->state(['business_id' => $attributes['business_id']]),
            'imei' => fake()->unique()->numerify('###############'),
            'imei2' => null,
            'serial_number' => fake()->optional()->bothify('SN-########'),
            'condition' => ProductCondition::New,
            'status' => SerializedDeviceStatus::InStock,
            'unit_cost' => fake()->randomFloat(2, 0, 500000),
            'unit_selling_price' => null,
            'received_at' => now(),
            'created_by' => User::factory(),
        ];
    }
}
