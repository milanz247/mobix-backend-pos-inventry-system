<?php

namespace Database\Factories;

use App\Models\Sale;
use App\Models\SaleRefund;
use App\Models\SaleReturn;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SaleRefund>
 */
class SaleRefundFactory extends Factory
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
            'sale_return_id' => SaleReturn::factory(),
            'amount' => '10.00',
            'operation_key' => fn (): string => Str::uuid()->toString(),
            'method' => 'cash',
            'reference' => null,
            'refunded_at' => now(),
            'processed_by' => User::factory(),
        ];
    }
}
