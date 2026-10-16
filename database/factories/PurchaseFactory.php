<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Location;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\PurchasePaymentStatus;
use App\PurchaseStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Purchase>
 */
class PurchaseFactory extends Factory
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
            'supplier_id' => fn (array $attributes): int => Supplier::factory()->state(['business_id' => $attributes['business_id']])->create()->id,
            'location_id' => fn (array $attributes): int => Location::factory()->state(['business_id' => $attributes['business_id']])->create()->id,
            'invoice_number' => strtoupper(fake()->unique()->bothify('INV-########')),
            'operation_key' => Str::uuid()->toString(),
            'invoice_date' => fake()->date(),
            'notes' => null,
            'subtotal' => '0.00',
            'discount_total' => '0.00',
            'total' => '0.00',
            'paid_total' => '0.00',
            'status' => PurchaseStatus::Draft,
            'payment_status' => PurchasePaymentStatus::Unpaid,
            'inventory_operation_id' => null,
            'created_by' => User::factory(),
            'posted_by' => null,
            'posted_at' => null,
        ];
    }
}
