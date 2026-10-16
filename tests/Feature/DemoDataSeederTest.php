<?php

use App\Models\Business;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SerializedDevice;
use App\Models\StockMovement;
use App\Models\User;
use App\SystemRole;
use Brick\Math\BigDecimal;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Facades\Hash;

test('demo data seeder creates locations users roles permissions and location access', function () {
    config(['demo.milan_password' => 'TestDemoPassword#2026']);
    $business = Business::factory()->create();

    $this->seed(DemoDataSeeder::class);

    $milan = User::query()->where('email', 'milan@nalakamobile.test')->sole();

    expect($business->locations()->count())->toBe(4)
        ->and($business->locations()->where('is_main', true)->count())->toBe(1)
        ->and($milan->name)->toBe('Milan')
        ->and($milan->hasRole('Admin'))->toBeTrue()
        ->and(Hash::check('TestDemoPassword#2026', $milan->password))->toBeTrue()
        ->and($milan->locations()->count())->toBe(4)
        ->and($business->owner->locations()->count())->toBe(4);
});

test('demo data seeder creates an initial business owner and complete catalogue from an empty database', function () {
    config(['demo.milan_password' => 'TestDemoPassword#2026']);

    $this->seed(DemoDataSeeder::class);

    $business = Business::query()->with('owner')->sole();
    $owner = User::query()->where('email', 'milan@nalakamobile.test')->sole();

    expect($business->name)->toBe('NalakaMobile Demo Shop')
        ->and($business->currency)->toBe('LKR')
        ->and($business->owner_id)->toBe($owner->id)
        ->and($owner->name)->toBe('Milan')
        ->and($owner->hasRole(SystemRole::Owner->value))->toBeTrue()
        ->and($business->products()->count())->toBe(20)
        ->and($business->locations()->where('status', 'active')->count())->toBe(4)
        ->and($business->locations()->where('is_main', true)->count())->toBe(1)
        ->and(Sale::query()->where('business_id', $business->id)->count())->toBe(5)
        ->and(Sale::query()->where('business_id', $business->id)->whereHas('lines', fn ($query) => $query->whereNotNull('serialized_device_id'))->exists())->toBeTrue();
});

test('demo data seeder can run repeatedly without duplicating users locations or products', function () {
    config(['demo.milan_password' => 'TestDemoPassword#2026']);
    $business = Business::factory()->create();

    $this->seed(DemoDataSeeder::class);
    $this->seed(DemoDataSeeder::class);

    expect($business->locations()->count())->toBe(4)
        ->and(User::query()->whereIn('email', [
            'admin@nalakamobile.test',
            'manager@nalakamobile.test',
            'cashier@nalakamobile.test',
            'technician@nalakamobile.test',
        ])->count())->toBe(0)
        ->and($business->products()->count())->toBe(20)
        ->and($business->productVariants()->count())->toBe(24)
        ->and(User::query()->where('email', 'milan@nalakamobile.test')->count())->toBe(1)
        ->and(InventoryOperation::query()->where('business_id', $business->id)->count())->toBeGreaterThan(0)
        ->and(InventoryStock::query()->where('business_id', $business->id)->count())->toBeGreaterThan(0)
        ->and(SerializedDevice::query()->where('business_id', $business->id)->count())->toBeGreaterThan(0)
        ->and(Purchase::query()->where('business_id', $business->id)->count())->toBe(1)
        ->and(Sale::query()->where('business_id', $business->id)->count())->toBe(5);
});

test('seeded quantity balances equal the immutable stock movement ledger', function () {
    config(['demo.milan_password' => 'TestDemoPassword#2026']);
    $business = Business::factory()->create();

    $this->seed(DemoDataSeeder::class);

    $balances = $business->inventoryStocks()->get();

    expect($balances)->not->toBeEmpty();

    foreach ($balances as $balance) {
        $ledgerQuantity = StockMovement::query()
            ->where('business_id', $business->id)
            ->where('location_id', $balance->location_id)
            ->where('product_variant_id', $balance->product_variant_id)
            ->sum('quantity_delta');

        expect(BigDecimal::of((string) $ledgerQuantity)->toScale(3)->__toString())
            ->toBe($balance->quantity_on_hand);
    }
});

test('demo seeder does not reset an existing Milan account password', function () {
    config(['demo.milan_password' => 'TestDemoPassword#2026']);
    $business = Business::factory()->create();
    $milan = User::factory()->create([
        'name' => 'Existing Milan',
        'email' => 'milan@nalakamobile.test',
        'password' => 'ExistingPassword#2026',
    ]);

    $this->seed(DemoDataSeeder::class);

    expect($milan->refresh()->name)->toBe('Existing Milan')
        ->and(Hash::check('ExistingPassword#2026', $milan->password))->toBeTrue()
        ->and($business->locations()->count())->toBe(4);
});
