<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Location;
use App\Models\Sale;
use App\Models\User;
use App\SalePaymentStatus;
use App\SaleStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
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
            'location_id' => fn (array $attributes): int => Location::factory()->create(['business_id' => $attributes['business_id']])->id,
            'sale_number' => fn (): string => 'SAL-'.fake()->unique()->numerify('##########'),
            'operation_key' => fn (): string => Str::uuid()->toString(),
            'payload_hash' => fn (): string => hash('sha256', Str::uuid()->toString()),
            'cashier_id' => User::factory(),
            'customer_name' => fake()->name(),
            'customer_phone' => fake()->numerify('07########'),
            'customer_email' => fake()->safeEmail(),
            'subtotal' => '100.00',
            'discount_total' => '0.00',
            'tax_total' => '0.00',
            'total' => '100.00',
            'paid_total' => '100.00',
            'status' => SaleStatus::Completed,
            'payment_status' => SalePaymentStatus::Paid,
            'inventory_operation_id' => null,
            'notes' => null,
            'posted_at' => now(),
        ];
    }
}
