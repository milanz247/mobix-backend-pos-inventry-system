<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\CashierShift;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CashierShift>
 */
class CashierShiftFactory extends Factory
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
            'cashier_id' => User::factory(),
            'active_cashier_id' => fn (array $attributes): int => $attributes['cashier_id'],
            'reference' => fn (): string => 'SHIFT-'.Str::ulid(),
            'operation_key' => fn (): string => (string) Str::uuid(),
            'status' => 'open',
            'opening_cash' => '1000.00',
            'opened_at' => now(),
        ];
    }
}
