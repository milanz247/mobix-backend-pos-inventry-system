<?php

namespace Database\Factories;

use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SalePayment>
 */
class SalePaymentFactory extends Factory
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
            'amount' => '100.00',
            'operation_key' => fn (): string => Str::uuid()->toString(),
            'payload_hash' => fn (): string => hash('sha256', Str::uuid()->toString()),
            'method' => 'cash',
            'reference' => null,
            'paid_at' => now(),
            'received_by' => User::factory(),
        ];
    }
}
