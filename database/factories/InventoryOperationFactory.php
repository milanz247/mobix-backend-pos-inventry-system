<?php

namespace Database\Factories;

use App\InventoryOperationType;
use App\Models\Business;
use App\Models\InventoryOperation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InventoryOperation>
 */
class InventoryOperationFactory extends Factory
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
            'operation_key' => Str::uuid(),
            'payload_hash' => hash('sha256', fake()->uuid()),
            'type' => InventoryOperationType::Receiving,
            'status' => 'posted',
            'source_location_id' => null,
            'destination_location_id' => null,
            'reference' => fake()->optional()->bothify('REF-####'),
            'reason' => null,
            'notes' => null,
            'performed_by' => User::factory(),
            'posted_at' => now(),
        ];
    }
}
