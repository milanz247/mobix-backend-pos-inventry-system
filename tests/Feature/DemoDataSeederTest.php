<?php

use App\Models\Business;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;

test('demo data seeder creates locations users roles permissions and location access', function () {
    $business = Business::factory()->create();

    $this->seed(DemoDataSeeder::class);

    $admin = User::query()->where('email', 'admin@nalakamobile.test')->sole();
    $manager = User::query()->where('email', 'manager@nalakamobile.test')->sole();
    $cashier = User::query()->where('email', 'cashier@nalakamobile.test')->sole();
    $technician = User::query()->where('email', 'technician@nalakamobile.test')->sole();

    expect($business->locations()->count())->toBe(4)
        ->and($business->locations()->where('is_main', true)->count())->toBe(1)
        ->and($admin->hasRole('Admin'))->toBeTrue()
        ->and($admin->can('products.view'))->toBeTrue()
        ->and($manager->hasRole('Manager'))->toBeTrue()
        ->and($cashier->hasRole('Cashier'))->toBeTrue()
        ->and($technician->hasRole('Technician'))->toBeTrue()
        ->and($admin->locations()->count())->toBe(4)
        ->and($manager->locations()->count())->toBe(4)
        ->and($cashier->locations()->count())->toBe(2)
        ->and($technician->locations()->count())->toBe(1)
        ->and($business->owner->locations()->count())->toBe(4);
});

test('demo data seeder can run repeatedly without duplicating users locations or products', function () {
    $business = Business::factory()->create();

    $this->seed(DemoDataSeeder::class);
    $this->seed(DemoDataSeeder::class);

    expect($business->locations()->count())->toBe(4)
        ->and(User::query()->whereIn('email', [
            'admin@nalakamobile.test',
            'manager@nalakamobile.test',
            'cashier@nalakamobile.test',
            'technician@nalakamobile.test',
        ])->count())->toBe(4)
        ->and($business->products()->count())->toBe(10)
        ->and($business->productVariants()->count())->toBe(13);
});
