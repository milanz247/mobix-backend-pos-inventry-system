<?php

use App\Models\Business;
use App\Models\Category;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\SerializedDevice;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\PurchaseService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

/** @return array{user: User, business: Business, location: Location, supplier: Supplier, quantity_variant: ProductVariant, serialized_variant: ProductVariant} */
function purchaseTestContext(string ...$permissions): array
{
    $owner = User::factory()->create();
    $user = User::factory()->create();
    $business = Business::factory()->for($owner, 'owner')->create();
    $location = Location::factory()->for($business)->create(['name' => 'Purchase Main']);
    $location->users()->attach($user);
    $supplier = $business->suppliers()->create(['name' => 'Test Supplier', 'phone' => '0712345678']);
    $category = Category::factory()->for($business)->create();

    $quantityProduct = Product::factory()->for($business)->for($category)->create(['name' => 'Quantity Item']);
    $quantityVariant = ProductVariant::factory()->for($business)->for($quantityProduct)->create(['sku' => 'PUR-QTY-001', 'cost_price' => '40.00']);
    $serializedProduct = Product::factory()->serialized()->for($business)->for($category)->create(['name' => 'Serialized Phone']);
    $serializedVariant = ProductVariant::factory()->serialized()->for($business)->for($serializedProduct)->create(['sku' => 'PUR-IMEI-001', 'cost_price' => '500.00']);

    foreach ($permissions as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return [
        'user' => $user,
        'business' => $business,
        'location' => $location,
        'supplier' => $supplier,
        'quantity_variant' => $quantityVariant,
        'serialized_variant' => $serializedVariant,
    ];
}

/** @return array<string, mixed> */
function quantityPurchasePayload(Supplier $supplier, Location $location, ProductVariant $variant, string $invoice = 'INV-100'): array
{
    return [
        'supplier_id' => $supplier->id,
        'location_id' => $location->id,
        'invoice_number' => $invoice,
        'invoice_date' => now()->toDateString(),
        'notes' => 'Initial supplier invoice',
        'lines' => [[
            'product_variant_id' => $variant->id,
            'quantity' => '5.000',
            'unit_cost' => '20.00',
            'discount_amount' => '5.00',
            'devices' => [],
        ]],
    ];
}

test('purchase draft stores actual line costs and posting receives quantity stock exactly once', function () {
    ['user' => $user, 'supplier' => $supplier, 'location' => $location, 'quantity_variant' => $variant] = purchaseTestContext(
        'purchases.view', 'purchases.create', 'purchases.post', 'inventory.receive',
    );
    $payload = quantityPurchasePayload($supplier, $location, $variant);

    $response = $this->actingAs($user)->post(route('purchases.store'), $payload)->assertSessionHasNoErrors();
    $purchase = Purchase::query()->sole();
    $line = PurchaseLine::query()->sole();

    expect($purchase->total)->toBe('95.00')
        ->and($purchase->business_id)->toBe($supplier->business_id)
        ->and($line->unit_cost)->toBe('20.00')
        ->and(InventoryStock::query()->count())->toBe(0);

    $this->actingAs($user)->post(route('purchases.post', $purchase))->assertSessionHasNoErrors();
    $this->actingAs($user)->post(route('purchases.post', $purchase))->assertSessionHasNoErrors();

    expect($purchase->fresh()->inventory_operation_id)->not->toBeNull()
        ->and(InventoryOperation::query()->count())->toBe(1)
        ->and(InventoryStock::query()->sole()->quantity_on_hand)->toBe('5.000')
        ->and(StockMovement::query()->sole()->movement_type->value)->toBe('receiving')
        ->and(StockMovement::query()->sole()->quantity_delta)->toBe('5.000');
});

test('serialized purchase receiving creates exact devices without a quantity balance', function () {
    ['user' => $user, 'supplier' => $supplier, 'location' => $location, 'serialized_variant' => $variant] = purchaseTestContext(
        'purchases.create', 'purchases.post', 'inventory.receive',
    );
    $payload = [
        'supplier_id' => $supplier->id,
        'location_id' => $location->id,
        'invoice_number' => 'PHONE-INV-1',
        'invoice_date' => now()->toDateString(),
        'lines' => [[
            'product_variant_id' => $variant->id,
            'quantity' => '',
            'unit_cost' => '',
            'discount_amount' => '0.00',
            'devices' => [
                ['imei' => '356789012345671', 'imei2' => '356789012345689', 'condition' => 'new', 'unit_cost' => '510.00', 'unit_selling_price' => '650.00'],
                ['imei' => '356789012345697', 'condition' => 'used', 'unit_cost' => '400.00', 'unit_selling_price' => '500.00'],
            ],
        ]],
    ];

    $this->actingAs($user)->post(route('purchases.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
    $purchase = Purchase::query()->sole();
    expect($purchase->total)->toBe('910.00');

    $this->actingAs($user)->post(route('purchases.post', $purchase))->assertSessionHasNoErrors();

    expect(SerializedDevice::query()->count())->toBe(2)
        ->and(SerializedDevice::query()->where('location_id', $location->id)->where('unit_cost', '510.00')->exists())->toBeTrue()
        ->and(InventoryStock::query()->count())->toBe(0)
        ->and($purchase->fresh()->lines()->sole()->devices()->whereNotNull('serialized_device_id')->count())->toBe(2);
});

test('purchase posting rolls back the receiving operation if an imei is claimed after draft creation', function () {
    ['user' => $user, 'location' => $location, 'supplier' => $supplier, 'serialized_variant' => $variant] = purchaseTestContext(
        'purchases.create', 'purchases.post', 'inventory.receive',
    );
    $payload = [
        'supplier_id' => $supplier->id,
        'location_id' => $location->id,
        'invoice_number' => 'PHONE-ROLLBACK',
        'invoice_date' => now()->toDateString(),
        'lines' => [[
            'product_variant_id' => $variant->id,
            'devices' => [['imei' => '444444444444444', 'condition' => 'new', 'unit_cost' => '100.00']],
        ]],
    ];
    $this->actingAs($user)->post(route('purchases.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
    $purchase = Purchase::query()->sole();

    app(InventoryService::class)->receive($user, [
        'operation_key' => Str::uuid()->toString(),
        'location_id' => $location->id,
        'product_variant_id' => $variant->id,
        'mode' => 'serialized',
        'devices' => [['imei' => '444444444444444', 'condition' => 'new', 'unit_cost' => '100.00']],
    ]);

    expect(fn () => app(PurchaseService::class)->post($user, $purchase))
        ->toThrow(ValidationException::class);

    expect($purchase->fresh()->status->value)->toBe('draft')
        ->and($purchase->fresh()->inventory_operation_id)->toBeNull()
        ->and(InventoryOperation::query()->count())->toBe(1)
        ->and(SerializedDevice::query()->count())->toBe(1);
});

test('supplier payments update outstanding balances and reject overpayment', function () {
    ['user' => $user, 'supplier' => $supplier, 'location' => $location, 'quantity_variant' => $variant] = purchaseTestContext(
        'purchases.create', 'purchases.post', 'inventory.receive', 'purchase-payments.create',
    );
    $this->actingAs($user)->post(route('purchases.store'), quantityPurchasePayload($supplier, $location, $variant, 'INV-PAY'));
    $purchase = Purchase::query()->sole();
    $this->actingAs($user)->post(route('purchases.post', $purchase));

    $this->actingAs($user)->post(route('purchases.payments.store', $purchase), [
        'operation_key' => Str::uuid()->toString(), 'amount' => '40.00', 'payment_date' => now()->toDateString(), 'method' => 'cash',
    ])->assertSessionHasNoErrors();

    expect($purchase->fresh()->paid_total)->toBe('40.00')
        ->and($purchase->fresh()->payment_status->value)->toBe('partially_paid');

    $this->actingAs($user)->post(route('purchases.payments.store', $purchase), [
        'operation_key' => Str::uuid()->toString(), 'amount' => '56.00', 'payment_date' => now()->toDateString(), 'method' => 'cash',
    ])->assertSessionHasErrors('amount');

    expect($purchase->payments()->count())->toBe(1)->and($purchase->fresh()->paid_total)->toBe('40.00');
});

test('purchase returns reduce location stock and are recorded as immutable supplier-return movements', function () {
    ['user' => $user, 'supplier' => $supplier, 'location' => $location, 'quantity_variant' => $variant] = purchaseTestContext(
        'purchases.view', 'purchases.create', 'purchases.post', 'purchase-returns.create', 'inventory.receive',
    );
    $this->actingAs($user)->post(route('purchases.store'), quantityPurchasePayload($supplier, $location, $variant, 'INV-RETURN'));
    $purchase = Purchase::query()->sole();
    $this->actingAs($user)->post(route('purchases.post', $purchase));
    $line = $purchase->lines()->sole();

    $this->actingAs($user)->post(route('purchases.returns.store', $purchase), [
        'location_id' => $location->id,
        'return_number' => 'RET-INV-1',
        'operation_key' => Str::uuid()->toString(),
        'return_date' => now()->toDateString(),
        'lines' => [['purchase_line_id' => $line->id, 'quantity' => '2.000']],
    ])->assertSessionHasNoErrors();

    expect(InventoryStock::query()->sole()->quantity_on_hand)->toBe('3.000')
        ->and(StockMovement::query()->count())->toBe(2)
        ->and(StockMovement::query()->latest('id')->firstOrFail()->movement_type->value)->toBe('purchase_return')
        ->and($purchase->returns()->sole()->total)->toBe('38.00')
        ->and($purchase->fresh()->status->value)->toBe('partially_returned');

    $this->actingAs($user)->post(route('purchases.returns.store', $purchase), [
        'location_id' => $location->id,
        'return_number' => 'RET-INV-2',
        'operation_key' => Str::uuid()->toString(),
        'return_date' => now()->toDateString(),
        'lines' => [['purchase_line_id' => $line->id, 'quantity' => '4.000']],
    ])->assertSessionHasErrors('lines');

    $this->actingAs($user)->post(route('purchases.returns.store', $purchase), [
        'location_id' => $location->id,
        'return_number' => 'RET-INV-3',
        'operation_key' => Str::uuid()->toString(),
        'return_date' => now()->toDateString(),
        'lines' => [['purchase_line_id' => $line->id, 'quantity' => '3.000']],
    ])->assertSessionHasNoErrors();

    expect(InventoryStock::query()->sole()->quantity_on_hand)->toBe('0.000')
        ->and($purchase->returns()->sum('total'))->toBe('95.00')
        ->and($purchase->fresh()->status->value)->toBe('returned');
});

test('purchase lists paginate and scope invoices to locations the user can access', function () {
    ['business' => $business, 'location' => $location, 'supplier' => $supplier, 'quantity_variant' => $variant] = purchaseTestContext('purchases.view');
    $owner = $business->owner;
    $otherLocation = Location::factory()->for($business)->create();
    $business->purchases()->create([
        'supplier_id' => $supplier->id, 'location_id' => $otherLocation->id, 'invoice_number' => 'PRIVATE-INV',
        'operation_key' => Str::uuid()->toString(), 'invoice_date' => now()->toDateString(), 'created_by' => $owner->id,
    ]);

    // An explicitly assigned user sees only purchases for their assigned location.
    $assigned = User::factory()->create();
    $assigned->givePermissionTo(Permission::findOrCreate('purchases.view', 'web'));
    $assigned->locations()->attach($location);
    $business->purchases()->create([
        'supplier_id' => $supplier->id, 'location_id' => $location->id, 'invoice_number' => 'VISIBLE-INV',
        'operation_key' => Str::uuid()->toString(), 'invoice_date' => now()->toDateString(), 'created_by' => $owner->id,
    ]);

    $this->actingAs($assigned)->get(route('purchases.index', ['search' => 'INV']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('purchasing/purchases/Index')
            ->has('purchases.data', 1)
            ->where('purchases.data.0.invoice_number', 'VISIBLE-INV'));
});

test('purchasing routes reject users without the corresponding permission', function () {
    ['user' => $user, 'supplier' => $supplier, 'location' => $location, 'quantity_variant' => $variant] = purchaseTestContext();

    $this->actingAs($user)->get(route('suppliers.index'))->assertForbidden();
    $this->actingAs($user)->post(route('purchases.store'), quantityPurchasePayload($supplier, $location, $variant))->assertForbidden();
});
