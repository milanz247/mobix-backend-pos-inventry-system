<?php

use App\Models\BundleComponent;
use App\Models\Business;
use App\Models\Category;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SerializedDevice;
use App\Models\StockMovement;
use App\Models\User;
use App\ProductCondition;
use App\Services\InventoryService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

/** @return array{user: User, business: Business, location: Location, category: Category} */
function inventoryContext(string ...$permissions): array
{
    $user = User::factory()->create();
    $business = Business::factory()->for($user, 'owner')->create();
    $location = Location::factory()->for($business)->create(['name' => 'Main shop']);
    $category = Category::factory()->for($business)->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return compact('user', 'business', 'location', 'category');
}

function inventoryVariant(Business $business, Category $category, bool $serialized = false, bool $combo = false): ProductVariant
{
    $product = Product::factory()->for($business)->for($category)
        ->when($serialized, fn ($factory) => $factory->serialized())
        ->when($combo, fn ($factory) => $factory->combo())
        ->create();

    return ProductVariant::factory()->for($business)->for($product)
        ->when($serialized, fn ($factory) => $factory->serialized())
        ->create();
}

test('authorized owner can view inventory while an unassigned user cannot access location stock', function () {
    ['user' => $owner, 'business' => $business, 'location' => $location, 'category' => $category] = inventoryContext('inventory.view');
    $variant = inventoryVariant($business, $category);
    InventoryStock::factory()->for($business)->for($location)->for($variant, 'variant')->create(['quantity_on_hand' => 4]);

    $this->actingAs($owner)->get(route('inventory.locations.index', ['location_id' => $location->id]))->assertOk();

    $other = User::factory()->create();
    $other->givePermissionTo(Permission::findOrCreate('inventory.view', 'web'));
    $this->actingAs($other)->get(route('inventory.locations.index', ['location_id' => $location->id]))->assertNotFound();
});

test('inventory write routes enforce permission on direct requests', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('inventory.receiving.create'))->assertForbidden();
    $this->actingAs($user)->post(route('inventory.receiving.store'), [])->assertForbidden();
});

test('stock receiving keeps an authorized POS location selected', function () {
    ['business' => $business, 'location' => $location, 'category' => $category] = inventoryContext();
    $cashier = User::factory()->create();
    $cashier->givePermissionTo(Permission::findOrCreate('inventory.receive', 'web'));
    $location->users()->attach($cashier);
    $otherLocation = Location::factory()->for($business)->create(['name' => 'Other branch']);

    $this->actingAs($cashier)
        ->get(route('inventory.receiving.create', ['location_id' => $otherLocation->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('inventory/Receive')
            ->where('selectedLocationId', $location->id)
            ->has('locations', 1));
});

test('stock listing searches and scopes results to the selected authorized location', function () {
    ['user' => $user, 'business' => $business, 'location' => $location, 'category' => $category] = inventoryContext('inventory.view');
    $otherLocation = Location::factory()->for($business)->create();
    $first = inventoryVariant($business, $category);
    $second = inventoryVariant($business, $category);
    $first->update(['sku' => 'STOCK-SEARCH-ME']);
    $second->update(['sku' => 'STOCK-OTHER']);
    InventoryStock::factory()->for($business)->for($location)->for($first, 'variant')->create(['quantity_on_hand' => 2]);
    InventoryStock::factory()->for($business)->for($otherLocation)->for($second, 'variant')->create(['quantity_on_hand' => 9]);

    $this->actingAs($user)
        ->get(route('inventory.locations.index', ['location_id' => $location->id, 'search' => 'STOCK-SEARCH-ME']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('inventory/StockByLocation')
            ->has('stocks.data', 1)
            ->where('stocks.data.0.sku', 'STOCK-SEARCH-ME'));
});

test('quantity receiving posts one balance change and immutable movement and is idempotent', function () {
    ['user' => $user, 'business' => $business, 'location' => $location, 'category' => $category] = inventoryContext('inventory.receive');
    $variant = inventoryVariant($business, $category);
    $key = Str::uuid()->toString();
    $payload = ['operation_key' => $key, 'location_id' => $location->id, 'product_variant_id' => $variant->id, 'mode' => 'quantity', 'quantity' => '10.000', 'unit_cost' => '125.00'];

    $this->actingAs($user)->post(route('inventory.receiving.store'), $payload)->assertSessionHasNoErrors();
    $this->actingAs($user)->post(route('inventory.receiving.store'), $payload)->assertSessionHasNoErrors();

    expect(InventoryStock::query()->sole()->quantity_on_hand)->toBe('10.000')
        ->and(StockMovement::query()->count())->toBe(1)
        ->and(StockMovement::query()->sole()->balance_after)->toBe('10.000');
});

test('an operation key cannot be replayed by a different user', function () {
    ['user' => $owner, 'business' => $business, 'location' => $location, 'category' => $category] = inventoryContext('inventory.receive');
    $variant = inventoryVariant($business, $category);
    $payload = [
        'operation_key' => Str::uuid()->toString(), 'location_id' => $location->id,
        'product_variant_id' => $variant->id, 'mode' => 'quantity', 'quantity' => '1',
    ];
    $this->actingAs($owner)->post(route('inventory.receiving.store'), $payload)->assertSessionHasNoErrors();

    $other = User::factory()->create();
    $other->givePermissionTo(Permission::findOrCreate('inventory.receive', 'web'));
    $this->actingAs($other)->post(route('inventory.receiving.store'), $payload)->assertSessionHasErrors('operation_key');

    expect(InventoryStock::query()->sole()->quantity_on_hand)->toBe('1.000')
        ->and(StockMovement::query()->count())->toBe(1);
});

test('serialized receiving stores multiple exact devices without creating quantity stock', function () {
    ['user' => $user, 'business' => $business, 'location' => $location, 'category' => $category] = inventoryContext('inventory.receive');
    $variant = inventoryVariant($business, $category, serialized: true);

    $this->actingAs($user)->post(route('inventory.receiving.store'), [
        'operation_key' => Str::uuid()->toString(), 'location_id' => $location->id,
        'product_variant_id' => $variant->id, 'mode' => 'serialized',
        'devices' => [
            ['imei' => '356789012345671', 'imei2' => '356789012345689', 'condition' => 'new', 'unit_cost' => '100000.00'],
            ['imei' => '356789012345697', 'condition' => 'refurbished', 'unit_cost' => '80000.00'],
        ],
    ])->assertSessionHasNoErrors();

    expect(SerializedDevice::query()->count())->toBe(2)
        ->and(InventoryStock::query()->count())->toBe(0)
        ->and(SerializedDevice::query()->where('location_id', $location->id)->count())->toBe(2);
});

test('opening stock requires intent and creates an opening movement', function () {
    ['user' => $user, 'business' => $business, 'location' => $location, 'category' => $category] = inventoryContext('inventory.opening');
    $variant = inventoryVariant($business, $category);

    $this->actingAs($user)->post(route('inventory.opening.store'), [
        'operation_key' => Str::uuid()->toString(), 'location_id' => $location->id,
        'product_variant_id' => $variant->id, 'mode' => 'quantity', 'quantity' => '3',
    ])->assertSessionHasErrors('reason');

    $this->actingAs($user)->post(route('inventory.opening.store'), [
        'operation_key' => Str::uuid()->toString(), 'location_id' => $location->id,
        'product_variant_id' => $variant->id, 'mode' => 'quantity', 'quantity' => '3', 'reason' => 'Initial physical count',
    ])->assertSessionHasNoErrors();

    expect(StockMovement::query()->sole()->movement_type->value)->toBe('opening_balance');
});

test('adjustments cannot reduce quantity stock below zero', function () {
    ['user' => $user, 'business' => $business, 'location' => $location, 'category' => $category] = inventoryContext('inventory.adjust');
    $variant = inventoryVariant($business, $category);
    InventoryStock::factory()->for($business)->for($location)->for($variant, 'variant')->create(['quantity_on_hand' => 2]);

    $this->actingAs($user)->post(route('inventory.adjustments.store'), [
        'operation_key' => Str::uuid()->toString(), 'location_id' => $location->id, 'product_variant_id' => $variant->id,
        'direction' => 'decrease', 'quantity' => '3', 'reason' => 'physical_count',
    ])->assertSessionHasErrors('quantity');

    expect(InventoryStock::query()->sole()->quantity_on_hand)->toBe('2.000')->and(StockMovement::query()->count())->toBe(0);
});

test('an increase adjustment updates the balance and records a positive movement', function () {
    ['user' => $user, 'business' => $business, 'location' => $location, 'category' => $category] = inventoryContext('inventory.adjust');
    $variant = inventoryVariant($business, $category);
    InventoryStock::factory()->for($business)->for($location)->for($variant, 'variant')->create(['quantity_on_hand' => 2]);

    $this->actingAs($user)->post(route('inventory.adjustments.store'), [
        'operation_key' => Str::uuid()->toString(), 'location_id' => $location->id, 'product_variant_id' => $variant->id,
        'direction' => 'increase', 'quantity' => '1.250', 'reason' => 'physical_count',
    ])->assertSessionHasNoErrors();

    expect(InventoryStock::query()->sole()->quantity_on_hand)->toBe('3.250')
        ->and(StockMovement::query()->sole()->movement_type->value)->toBe('adjustment_in')
        ->and(StockMovement::query()->sole()->quantity_delta)->toBe('1.250');
});

test('a transfer updates both location balances and movements atomically', function () {
    ['user' => $user, 'business' => $business, 'location' => $source, 'category' => $category] = inventoryContext('inventory.transfer');
    $destination = Location::factory()->for($business)->create();
    $variant = inventoryVariant($business, $category);
    InventoryStock::factory()->for($business)->for($source)->for($variant, 'variant')->create(['quantity_on_hand' => 8]);

    $this->actingAs($user)->post(route('inventory.transfers.store'), [
        'operation_key' => Str::uuid()->toString(), 'source_location_id' => $source->id, 'destination_location_id' => $destination->id,
        'items' => [['product_variant_id' => $variant->id, 'quantity' => '5']], 'device_ids' => [], 'reference' => 'TR-001',
    ])->assertSessionHasNoErrors();

    expect(InventoryStock::query()->where('location_id', $source->id)->value('quantity_on_hand'))->toBe('3.000')
        ->and(InventoryStock::query()->where('location_id', $destination->id)->value('quantity_on_hand'))->toBe('5.000')
        ->and(StockMovement::query()->get()->map(
            fn (StockMovement $movement): string => $movement->movement_type->value,
        )->all())->toEqualCanonicalizing(['transfer_out', 'transfer_in']);
});

test('an insufficient transfer rolls back the source deduction, destination balance, operation and movement history', function () {
    ['user' => $user, 'business' => $business, 'location' => $source, 'category' => $category] = inventoryContext('inventory.transfer');
    $destination = Location::factory()->for($business)->create();
    $variant = inventoryVariant($business, $category);
    InventoryStock::factory()->for($business)->for($source)->for($variant, 'variant')->create(['quantity_on_hand' => 2]);

    $this->actingAs($user)->post(route('inventory.transfers.store'), [
        'operation_key' => Str::uuid()->toString(), 'source_location_id' => $source->id, 'destination_location_id' => $destination->id,
        'items' => [['product_variant_id' => $variant->id, 'quantity' => '5']], 'device_ids' => [], 'reference' => 'TR-ROLLBACK',
    ])->assertSessionHasErrors('quantity');

    expect(InventoryStock::query()->where('location_id', $source->id)->value('quantity_on_hand'))->toBe('2.000')
        ->and(InventoryStock::query()->where('location_id', $destination->id)->exists())->toBeFalse()
        ->and(StockMovement::query()->count())->toBe(0)
        ->and(InventoryOperation::query()->count())->toBe(0);
});

test('a transfer requires access to both source and destination locations', function () {
    ['business' => $business, 'location' => $source, 'category' => $category] = inventoryContext();
    $destination = Location::factory()->for($business)->create();
    $user = User::factory()->create();
    $user->givePermissionTo(Permission::findOrCreate('inventory.transfer', 'web'));
    $user->locations()->attach($source);
    $variant = inventoryVariant($business, $category);
    InventoryStock::factory()->for($business)->for($source)->for($variant, 'variant')->create(['quantity_on_hand' => 5]);

    $this->actingAs($user)->post(route('inventory.transfers.store'), [
        'operation_key' => Str::uuid()->toString(), 'source_location_id' => $source->id, 'destination_location_id' => $destination->id,
        'items' => [['product_variant_id' => $variant->id, 'quantity' => '1']], 'device_ids' => [], 'reference' => 'TR-DENIED',
    ])->assertSessionHasErrors('location_id');

    expect(InventoryStock::query()->where('location_id', $source->id)->value('quantity_on_hand'))->toBe('5.000')
        ->and(StockMovement::query()->count())->toBe(0);
});

test('a transfer cannot target a location from another business', function () {
    ['user' => $user, 'business' => $business, 'location' => $source, 'category' => $category] = inventoryContext('inventory.transfer');
    $otherOwner = User::factory()->create();
    $otherBusiness = Business::factory()->for($otherOwner, 'owner')->create(['setup_key' => 'other']);
    $foreignLocation = Location::factory()->for($otherBusiness)->create();
    $variant = inventoryVariant($business, $category);
    InventoryStock::factory()->for($business)->for($source)->for($variant, 'variant')->create(['quantity_on_hand' => 5]);

    $this->actingAs($user)->post(route('inventory.transfers.store'), [
        'operation_key' => Str::uuid()->toString(), 'source_location_id' => $source->id, 'destination_location_id' => $foreignLocation->id,
        'items' => [['product_variant_id' => $variant->id, 'quantity' => '1']], 'device_ids' => [], 'reference' => 'TR-CROSS-BUSINESS',
    ])->assertSessionHasErrors('location_id');

    expect(StockMovement::query()->count())->toBe(0)
        ->and(InventoryStock::query()->where('location_id', $source->id)->value('quantity_on_hand'))->toBe('5.000');
});

test('serialized transfers move the same device and add a traceable event', function () {
    ['user' => $user, 'business' => $business, 'location' => $source, 'category' => $category] = inventoryContext('inventory.receive', 'inventory.transfer');
    $destination = Location::factory()->for($business)->create();
    $variant = inventoryVariant($business, $category, serialized: true);
    app(InventoryService::class)->receive($user, [
        'operation_key' => Str::uuid()->toString(), 'location_id' => $source->id,
        'product_variant_id' => $variant->id, 'mode' => 'serialized',
        'devices' => [['imei' => '123456789012345', 'condition' => ProductCondition::New->value, 'unit_cost' => '100.00']],
    ]);
    $device = SerializedDevice::query()->sole();

    app(InventoryService::class)->transfer($user, [
        'operation_key' => Str::uuid()->toString(), 'source_location_id' => $source->id,
        'destination_location_id' => $destination->id, 'items' => [], 'device_ids' => [$device->id],
        'reference' => 'TR-DEVICE-001',
    ]);

    expect($device->fresh()->location_id)->toBe($destination->id)
        ->and($device->events()->count())->toBe(2)
        ->and($device->events()->orderByDesc('id')->firstOrFail()->event_type->value)->toBe('transferred')
        ->and(InventoryStock::query()->count())->toBe(0);
});

test('duplicate and cross-slot imei values are rejected atomically', function () {
    ['user' => $user, 'business' => $business, 'location' => $location, 'category' => $category] = inventoryContext('inventory.receive');
    $variant = inventoryVariant($business, $category, serialized: true);
    $base = ['location_id' => $location->id, 'product_variant_id' => $variant->id, 'mode' => 'serialized'];
    $this->actingAs($user)->post(route('inventory.receiving.store'), $base + [
        'operation_key' => Str::uuid()->toString(),
        'devices' => [['imei' => '111111111111111', 'imei2' => '222222222222222', 'condition' => 'new', 'unit_cost' => '10']],
    ])->assertSessionHasNoErrors();
    $this->actingAs($user)->post(route('inventory.receiving.store'), $base + [
        'operation_key' => Str::uuid()->toString(),
        'devices' => [['imei' => '222222222222222', 'condition' => 'new', 'unit_cost' => '10']],
    ])->assertSessionHasErrors('devices');

    expect(SerializedDevice::query()->count())->toBe(1);
});

test('duplicate imei values within one submitted device batch are rejected', function () {
    ['user' => $user, 'business' => $business, 'location' => $location, 'category' => $category] = inventoryContext('inventory.receive');
    $variant = inventoryVariant($business, $category, serialized: true);

    $this->actingAs($user)->post(route('inventory.receiving.store'), [
        'operation_key' => Str::uuid()->toString(), 'location_id' => $location->id,
        'product_variant_id' => $variant->id, 'mode' => 'serialized',
        'devices' => [
            ['imei' => '123456789012345', 'condition' => 'new', 'unit_cost' => '100'],
            ['imei' => '123456789012345', 'condition' => 'new', 'unit_cost' => '100'],
        ],
    ])->assertSessionHasErrors('devices.1.imei');

    expect(SerializedDevice::query()->count())->toBe(0);
});

test('reusing an operation key with a changed payload is rejected', function () {
    ['user' => $user, 'business' => $business, 'location' => $location, 'category' => $category] = inventoryContext('inventory.receive');
    $variant = inventoryVariant($business, $category);
    $key = Str::uuid()->toString();
    $payload = ['operation_key' => $key, 'location_id' => $location->id, 'product_variant_id' => $variant->id, 'mode' => 'quantity', 'quantity' => '3'];
    $this->actingAs($user)->post(route('inventory.receiving.store'), $payload)->assertSessionHasNoErrors();
    $this->actingAs($user)->post(route('inventory.receiving.store'), [...$payload, 'quantity' => '4'])->assertSessionHasErrors('operation_key');

    expect(InventoryStock::query()->sole()->quantity_on_hand)->toBe('3.000')->and(StockMovement::query()->count())->toBe(1);
});

test('combo availability resolves components and rejects insufficient location stock', function () {
    ['user' => $user, 'business' => $business, 'location' => $location, 'category' => $category] = inventoryContext();
    $bundle = inventoryVariant($business, $category, combo: true);
    $component = inventoryVariant($business, $category);
    BundleComponent::query()->create([
        'business_id' => $business->id,
        'bundle_variant_id' => $bundle->id,
        'component_variant_id' => $component->id,
        'quantity' => '2.000',
    ]);
    InventoryStock::factory()->for($business)->for($location)->for($component, 'variant')->create(['quantity_on_hand' => 3]);
    $service = app(InventoryService::class);

    expect($service->resolveBundleAvailability($user, $location, $bundle)[0]['sufficient'])->toBeTrue()
        ->and($service->resolveBundleAvailability($user, $location, $bundle, '2.000')[0]['sufficient'])->toBeFalse()
        ->and(fn () => $service->assertBundleAvailability($user, $location, $bundle, '2.000'))->toThrow(ValidationException::class);
});

test('combo availability rejects an inactive or nested combo component', function () {
    ['user' => $user, 'business' => $business, 'location' => $location, 'category' => $category] = inventoryContext();
    $bundle = inventoryVariant($business, $category, combo: true);
    $nested = inventoryVariant($business, $category, combo: true);
    BundleComponent::query()->create([
        'business_id' => $business->id,
        'bundle_variant_id' => $bundle->id,
        'component_variant_id' => $nested->id,
        'quantity' => '1.000',
    ]);

    expect(fn () => app(InventoryService::class)->resolveBundleAvailability($user, $location, $bundle))
        ->toThrow(ValidationException::class);
});

test('serialized and combo variants reject direct quantity receiving', function () {
    ['user' => $user, 'business' => $business, 'location' => $location, 'category' => $category] = inventoryContext('inventory.receive');
    $serialized = inventoryVariant($business, $category, serialized: true);
    $combo = inventoryVariant($business, $category, combo: true);

    foreach ([$serialized, $combo] as $variant) {
        $this->actingAs($user)->post(route('inventory.receiving.store'), [
            'operation_key' => Str::uuid()->toString(), 'location_id' => $location->id,
            'product_variant_id' => $variant->id, 'mode' => 'quantity', 'quantity' => 1,
        ])->assertSessionHasErrors('product_variant_id');
    }
});

test('posted movement models cannot be edited or deleted', function () {
    ['user' => $user, 'business' => $business, 'location' => $location, 'category' => $category] = inventoryContext();
    $variant = inventoryVariant($business, $category);
    $operation = app(InventoryService::class)->receive($user, [
        'operation_key' => Str::uuid()->toString(), 'location_id' => $location->id,
        'product_variant_id' => $variant->id, 'mode' => 'quantity', 'quantity' => '1',
    ]);
    $movement = StockMovement::query()->where('inventory_operation_id', $operation->id)->sole();

    expect(fn () => $movement->update(['notes' => 'changed']))->toThrow(LogicException::class)
        ->and(fn () => $movement->delete())->toThrow(LogicException::class);
});

test('inventory permissions are seeded idempotently without granting every role', function () {
    $context = inventoryContext();
    $this->seed(RolePermissionSeeder::class);
    $this->seed(RolePermissionSeeder::class);

    expect(Permission::query()->where('name', 'inventory.view')->count())->toBe(1)
        ->and(Permission::query()->where('name', 'inventory.transfer')->count())->toBe(1);
});
