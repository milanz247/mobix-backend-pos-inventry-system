<?php

namespace Database\Factories;

use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SaleReturn>
 */
class SaleReturnFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sale_id' => Sale::factory(),
            'business_id' => fn (array $attributes): int => Sale::query()->findOrFail((int) $attributes['sale_id'])->business_id,
            'location_id' => fn (array $attributes): int => Sale::query()->findOrFail((int) $attributes['sale_id'])->location_id,
            'return_number' => fn (): string => 'RET-'.fake()->unique()->numerify('##########'),
            'operation_key' => fn (): string => Str::uuid()->toString(),
            'payload_hash' => fn (): string => hash('sha256', Str::uuid()->toString()),
            'total' => '10.00',
            'refund_total' => '10.00',
            'reason' => 'Customer return',
            'notes' => null,
            'inventory_operation_id' => null,
            'processed_by' => User::factory(),
            'processed_at' => now(),
        ];
    }
}
