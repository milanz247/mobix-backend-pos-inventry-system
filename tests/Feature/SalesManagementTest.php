<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Business;
use App\Models\CashierShift;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DamagedStockEntry;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleAddition;
use App\Models\SaleReturn;
use App\Models\SerializedDevice;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\SaleService;
use App\Support\PermissionCatalog;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

/** @return array{user: User, business: Business, location: Location, category: Category, quantityVariant: ProductVariant} */
function salesFeatureContext(string ...$permissions): array
{
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner, 'owner')->create();
    $location = Location::factory()->for($business)->create(['name' => 'Sales Main']);
    $cashier = User::factory()->create();
    $location->users()->attach($cashier);
    $cashier->givePermissionTo(Permission::findOrCreate('shifts.view', 'web'));
    CashierShift::factory()->for($business)->for($location)->for($cashier, 'cashier')->create(['opening_cash' => '0.00']);
    foreach (PermissionCatalog::legacyGrants() as $old => $new) {
        if (in_array($old, $permissions, true)) {
            $permissions = [...$permissions, ...$new];
        }
    }
    foreach ($permissions as $permission) {
        $cashier->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $category = Category::factory()->for($business)->create();
    $product = Product::factory()->for($business)->for($category)->create(['name' => 'Test Cable']);
    $quantityVariant = ProductVariant::factory()->for($business)->for($product)->create([
        'name' => 'Black',
        'sku' => 'SALE-CABLE-001',
        'selling_price' => '100.00',
        'cost_price' => '50.00',
    ]);

    return ['user' => $cashier, 'business' => $business, 'location' => $location, 'category' => $category, 'quantityVariant' => $quantityVariant];
}

function receiveSaleTestStock(User $user, Location $location, ProductVariant $variant, string $quantity): void
{
    app(InventoryService::class)->receive($user, [
        'operation_key' => Str::uuid()->toString(),
        'location_id' => $location->id,
        'product_variant_id' => $variant->id,
        'mode' => 'quantity',
        'quantity' => $quantity,
        'reason' => 'test opening stock',
    ], true);
}

/** @return array<string, mixed> */
function saleCheckoutPayload(Location $location, ProductVariant $variant, string $operationKey, string $quantity = '1.000'): array
{
    return [
        'operation_key' => $operationKey,
        'location_id' => $location->id,
        'source' => 'in_store_pos',
        'customer_name' => 'Test Customer',
        'order_discount' => '0.00',
        'lines' => [[
            'product_variant_id' => $variant->id,
            'quantity' => $quantity,
            'discount_amount' => '0.00',
        ]],
        'payments' => [['amount' => BigDecimal::of('100')->multipliedBy($quantity)->toScale(2, RoundingMode::HalfUp)->__toString(), 'method' => 'cash']],
    ];
}

test('cashier can post a paid sale and quantity movement references the sale', function () {
    ['user' => $user, 'location' => $location, 'quantityVariant' => $variant] = salesFeatureContext(
        'sales.pos', 'sales.create', 'sales.view', 'inventory.opening',
    );
    receiveSaleTestStock($user, $location, $variant, '4.000');
    $payload = saleCheckoutPayload($location, $variant, Str::uuid()->toString());

    $response = $this->actingAs($user)->post(route('pos.checkout'), $payload);
    $response->assertSessionHasNoErrors();
    $sale = Sale::query()->sole();
    $response->assertRedirect(route('pos.index', ['location_id' => $location->id]));
    $movement = StockMovement::query()->where('movement_type', 'sale')->sole();
    expect($sale->total)->toBe('100.00')
        ->and($sale->paid_total)->toBe('100.00')
        ->and($sale->sale_number)->toStartWith('SAL-')
        ->and(InventoryStock::query()->sole()->quantity_on_hand)->toBe('3.000')
        ->and($movement->quantity_delta)->toBe('-1.000')
        ->and($movement->reference_type)->toBe(Sale::class)
        ->and((int) $movement->reference_id)->toBe($sale->id);
});

test('checkout uses a walk-in customer when no customer name is entered', function () {
    ['user' => $user, 'location' => $location, 'quantityVariant' => $variant] = salesFeatureContext(
        'sales.pos', 'sales.create', 'sales.view', 'inventory.opening',
    );
    receiveSaleTestStock($user, $location, $variant, '1.000');
    $payload = saleCheckoutPayload($location, $variant, Str::uuid()->toString());
    $payload['customer_name'] = '';

    $this->actingAs($user)->post(route('pos.checkout'), $payload)->assertSessionHasNoErrors();

    expect(Sale::query()->sole()->customer_name)->toBe('Walk-in customer');
});

test('cashier can record the source of a POS sale', function () {
    ['user' => $user, 'location' => $location, 'quantityVariant' => $variant] = salesFeatureContext(
        'sales.pos', 'sales.create', 'sales.view', 'inventory.opening',
    );
    receiveSaleTestStock($user, $location, $variant, '2.000');
    $payload = saleCheckoutPayload($location, $variant, Str::uuid()->toString());
    $payload['source'] = 'whatsapp';

    $this->actingAs($user)->post(route('pos.checkout'), $payload)->assertSessionHasNoErrors();

    expect(Sale::query()->sole()->source->value)->toBe('whatsapp');
});

test('unit price overrides are blocked until enabled in POS settings', function () {
    ['user' => $user, 'business' => $business, 'location' => $location, 'quantityVariant' => $variant] = salesFeatureContext(
        'sales.pos', 'sales.create', 'sales.view', 'sales.price-override', 'inventory.opening',
    );
    receiveSaleTestStock($user, $location, $variant, '2.000');
    $payload = saleCheckoutPayload($location, $variant, Str::uuid()->toString());
    $payload['lines'][0]['unit_price'] = '90.00';
    $payload['payments'][0]['amount'] = '90.00';

    $this->actingAs($user)
        ->post(route('pos.checkout'), $payload)
        ->assertSessionHasErrors('lines.0.unit_price');

    $business->update(['pos_allow_price_override' => true]);
    $payload['operation_key'] = Str::uuid()->toString();

    $this->actingAs($user)
        ->post(route('pos.checkout'), $payload)
        ->assertSessionHasNoErrors();

    expect(Sale::query()->sole()->lines()->sole()->unit_price)->toBe('90.00');
});

test('checkout rejects an unsupported sale source', function () {
    ['user' => $user, 'location' => $location, 'quantityVariant' => $variant] = salesFeatureContext(
        'sales.pos', 'sales.create', 'sales.view', 'inventory.opening',
    );
    receiveSaleTestStock($user, $location, $variant, '2.000');
    $payload = saleCheckoutPayload($location, $variant, Str::uuid()->toString());
    $payload['source'] = 'unknown';

    $this->actingAs($user)->post(route('pos.checkout'), $payload)->assertSessionHasErrors('source');

    expect(Sale::query()->count())->toBe(0)
        ->and(InventoryStock::query()->sole()->quantity_on_hand)->toBe('2.000');
});

test('combo checkout deducts each component at the selected location atomically', function () {
    ['user' => $user, 'business' => $business, 'location' => $location, 'category' => $category, 'quantityVariant' => $component] = salesFeatureContext(
        'sales.pos', 'sales.create', 'sales.view', 'inventory.opening',
    );
    receiveSaleTestStock($user, $location, $component, '3.000');
    $comboProduct = Product::factory()->combo()->for($business)->for($category)->create(['name' => 'Test Bundle']);
    $combo = ProductVariant::factory()->for($business)->for($comboProduct, 'product')->create([
        'sku' => 'SALE-BUNDLE-001',
        'selling_price' => '100.00',
        'cost_price' => '0.00',
    ]);
    $combo->bundleComponents()->create([
        'business_id' => $business->id,
        'component_variant_id' => $component->id,
        'quantity' => '2.000',
    ]);
    $payload = saleCheckoutPayload($location, $combo, Str::uuid()->toString());

    $this->actingAs($user)->post(route('pos.checkout'), $payload)->assertSessionHasNoErrors();

    $sale = Sale::query()->sole();
    $movement = StockMovement::query()->where('movement_type', 'sale')->sole();
    expect(InventoryStock::query()->sole()->quantity_on_hand)->toBe('1.000')
        ->and($sale->lines()->sole()->components()->sole()->quantity)->toBe('2.000')
        ->and($sale->lines()->sole()->cost_snapshot)->toBe('100.00')
        ->and($movement->quantity_delta)->toBe('-2.000')
        ->and($movement->reference_type)->toBe(Sale::class)
        ->and((int) $movement->reference_id)->toBe($sale->id);
});

test('cash payment records tendered cash and server-calculated change', function () {
    ['user' => $user, 'location' => $location, 'quantityVariant' => $variant] = salesFeatureContext(
        'sales.pos', 'sales.create', 'sales.view', 'inventory.opening',
    );
    receiveSaleTestStock($user, $location, $variant, '2.000');
    $payload = saleCheckoutPayload($location, $variant, Str::uuid()->toString());
    $payload['payments'][0]['cash_tendered'] = '120.00';

    $this->actingAs($user)->post(route('pos.checkout'), $payload)->assertSessionHasNoErrors();

    $payment = Sale::query()->sole()->payments()->sole();
    expect($payment->amount)->toBe('100.00')
        ->and($payment->cash_tendered)->toBe('120.00')
        ->and($payment->change_given)->toBe('20.00');
});

test('cash tender below the applied payment amount is rejected', function () {
    ['user' => $user, 'location' => $location, 'quantityVariant' => $variant] = salesFeatureContext(
        'sales.pos', 'sales.create', 'sales.view', 'inventory.opening',
    );
    receiveSaleTestStock($user, $location, $variant, '2.000');
    $payload = saleCheckoutPayload($location, $variant, Str::uuid()->toString());
    $payload['payments'][0]['cash_tendered'] = '99.00';

    $this->actingAs($user)->post(route('pos.checkout'), $payload)->assertSessionHasErrors('cash_tendered');

    expect(Sale::query()->count())->toBe(0)
        ->and(InventoryStock::query()->sole()->quantity_on_hand)->toBe('2.000');
});

test('checkout is idempotent and does not deduct the same sale twice', function () {
    ['user' => $user, 'location' => $location, 'quantityVariant' => $variant] = salesFeatureContext(
        'sales.pos', 'sales.create', 'sales.view', 'inventory.opening',
    );
    receiveSaleTestStock($user, $location, $variant, '3.000');
    $payload = saleCheckoutPayload($location, $variant, Str::uuid()->toString());

    $this->actingAs($user)->post(route('pos.checkout'), $payload)->assertSessionHasNoErrors();
    $this->actingAs($user)->post(route('pos.checkout'), $payload)->assertSessionHasNoErrors();

    expect(Sale::query()->count())->toBe(1)
        ->and(StockMovement::query()->where('movement_type', 'sale')->count())->toBe(1)
        ->and(InventoryStock::query()->sole()->quantity_on_hand)->toBe('2.000');
});

test('checkout rolls back sale and payment when location stock is insufficient', function () {
    ['user' => $user, 'location' => $location, 'quantityVariant' => $variant] = salesFeatureContext(
        'sales.pos', 'sales.create', 'sales.view', 'inventory.opening',
    );
    receiveSaleTestStock($user, $location, $variant, '1.000');
    $payload = saleCheckoutPayload($location, $variant, Str::uuid()->toString(), '2.000');
    $payload['payments'][0]['amount'] = '200.00';

    $this->actingAs($user)->post(route('pos.checkout'), $payload)->assertSessionHasErrors();

    expect(Sale::query()->count())->toBe(0)
        ->and(InventoryStock::query()->sole()->quantity_on_hand)->toBe('1.000')
        ->and(StockMovement::query()->where('movement_type', 'sale')->count())->toBe(0);
});

test('cashier cannot sell from a location they are not assigned to', function () {
    ['user' => $user, 'business' => $business, 'location' => $location, 'quantityVariant' => $variant] = salesFeatureContext(
        'sales.pos', 'sales.create', 'sales.view',
    );
    $otherLocation = Location::factory()->for($business)->create(['name' => 'Restricted location']);
    $payload = saleCheckoutPayload($otherLocation, $variant, Str::uuid()->toString());

    $this->actingAs($user)->post(route('pos.checkout'), $payload)->assertSessionHasErrors('location_id');

    expect(Sale::query()->count())->toBe(0);
});

test('serialized phone can only be sold once by its identifier', function () {
    ['user' => $user, 'business' => $business, 'category' => $category, 'location' => $location] = salesFeatureContext(
        'sales.pos', 'sales.create', 'sales.view', 'inventory.receive',
    );
    $product = Product::factory()->serialized()->for($business)->for($category)->create(['name' => 'IMEI Phone']);
    $variant = ProductVariant::factory()->serialized()->for($business)->for($product)->create([
        'sku' => 'SALE-PHONE-001',
        'selling_price' => '500.00',
    ]);
    app(InventoryService::class)->receive($user, [
        'operation_key' => Str::uuid()->toString(),
        'location_id' => $location->id,
        'product_variant_id' => $variant->id,
        'mode' => 'serialized',
        'devices' => [['imei' => '356789012345671', 'condition' => 'used', 'unit_cost' => '300.00', 'unit_selling_price' => '475.00']],
    ]);
    $payload = [
        'operation_key' => Str::uuid()->toString(),
        'location_id' => $location->id,
        'source' => 'in_store_pos',
        'lines' => [['product_variant_id' => $variant->id, 'quantity' => '1', 'device_identifier' => '356789012345671']],
        'payments' => [['amount' => '475.00', 'method' => 'cash']],
    ];

    $this->actingAs($user)->post(route('pos.checkout'), $payload)->assertSessionHasNoErrors();
    $sale = Sale::query()->sole();
    $this->actingAs($user)->post(route('pos.checkout'), [...$payload, 'operation_key' => Str::uuid()->toString()])->assertSessionHasErrors('lines.0.device_identifier');

    expect($sale->lines()->sole()->imei_snapshot)->toBe('356789012345671')
        ->and(SerializedDevice::query()->sole()->status->value)->toBe('sold')
        ->and(Sale::query()->count())->toBe(1);
});

test('POS automatically selects an available serialized device when IMEI entry is disabled', function () {
    ['user' => $user, 'business' => $business, 'category' => $category, 'location' => $location] = salesFeatureContext(
        'sales.pos', 'sales.create', 'sales.view', 'inventory.receive',
    );
    $business->update(['pos_require_imei_on_sale' => false]);
    $product = Product::factory()->serialized()->for($business)->for($category)->create(['name' => 'Auto-selected Phone']);
    $variant = ProductVariant::factory()->serialized()->for($business)->for($product)->create([
        'sku' => 'SALE-AUTO-IMEI-001',
        'selling_price' => '500.00',
    ]);
    app(InventoryService::class)->receive($user, [
        'operation_key' => Str::uuid()->toString(),
        'location_id' => $location->id,
        'product_variant_id' => $variant->id,
        'mode' => 'serialized',
        'devices' => [['imei' => '356789012345672', 'condition' => 'new', 'unit_cost' => '300.00']],
    ]);

    $response = $this->actingAs($user)->post(route('pos.checkout'), [
        'operation_key' => Str::uuid()->toString(),
        'location_id' => $location->id,
        'source' => 'in_store_pos',
        'lines' => [['product_variant_id' => $variant->id, 'quantity' => '1']],
        'payments' => [['amount' => '500.00', 'method' => 'cash']],
    ]);

    $response->assertSessionHasNoErrors();
    expect(Sale::query()->sole()->lines()->sole()->imei_snapshot)->toBe('356789012345672')
        ->and(SerializedDevice::query()->sole()->status->value)->toBe('sold');
});

test('POS resolves an available serialized device by scanned IMEI in the selected location', function () {
    ['user' => $user, 'business' => $business, 'category' => $category, 'location' => $location] = salesFeatureContext(
        'sales.pos', 'sales.create', 'sales.view', 'inventory.receive',
    );
    $product = Product::factory()->serialized()->for($business)->for($category)->create();
    $variant = ProductVariant::factory()->serialized()->for($business)->for($product)->create(['sku' => 'POS-IMEI-001']);
    app(InventoryService::class)->receive($user, [
        'operation_key' => Str::uuid()->toString(),
        'location_id' => $location->id,
        'product_variant_id' => $variant->id,
        'mode' => 'serialized',
        'devices' => [['imei' => '356789012345671', 'condition' => 'used', 'unit_cost' => '300.00']],
    ]);

    $this->actingAs($user)->get(route('pos.index', ['location_id' => $location->id, 'search' => '356789012345671']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('matchingDevice.imei', '356789012345671')
            ->where('matchingDevice.product_variant_id', $variant->id));
});

test('POS exposes zero stock product availability without receiving controls', function () {
    ['user' => $user, 'location' => $location, 'quantityVariant' => $variant] = salesFeatureContext(
        'sales.pos', 'inventory.receive',
    );

    $this->actingAs($user)
        ->get(route('pos.index', ['location_id' => $location->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sales/Pos')
            ->missing('canReceiveStock')
            ->missing('canOpenStock')
            ->where('posSettings.show_receipt_after_sale', true)
            ->where('posSettings.auto_print_receipt', false)
            ->where('posSettings.require_imei_on_sale', true)
            ->where('posSettings.products_per_page', 24)
            ->where('variants.data.0.id', $variant->id)
            ->where('variants.data.0.quantity_on_hand', '0.000')
            ->where('variants.data.0.can_sell', false)
            ->where('variants.data.0.stock_status', 'out_of_stock'));

    $this->get(route('inventory.receiving.create', ['location_id' => $location->id]))->assertOk();
});

test('POS exposes the next product page for infinite scrolling', function () {
    ['user' => $user, 'business' => $business, 'location' => $location, 'category' => $category] = salesFeatureContext(
        'sales.pos',
    );
    $business->update(['pos_products_per_page' => 12]);
    Product::factory()->count(12)->for($business)->for($category)->create()
        ->each(fn (Product $product) => ProductVariant::factory()->for($business)->for($product)->create());

    $this->actingAs($user)
        ->withHeader('X-Inertia', 'true')
        ->withHeader('X-Inertia-Version', app(HandleInertiaRequests::class)->version(request()))
        ->get(route('pos.index', ['location_id' => $location->id]))
        ->assertOk()
        ->assertJsonCount(12, 'props.variants.data')
        ->assertJsonPath('mergeProps.0', 'variants.data')
        ->assertJsonPath('scrollProps.variants.nextPage', 2);
});

test('authorized return restores stock and records an immutable refund', function () {
    ['user' => $user, 'location' => $location, 'quantityVariant' => $variant] = salesFeatureContext(
        'sales.pos', 'sales.create', 'sales.view', 'sales.returns.create', 'sale-returns.create', 'sale-refunds.create', 'inventory.opening',
    );
    receiveSaleTestStock($user, $location, $variant, '4.000');
    $this->actingAs($user)->post(route('pos.checkout'), saleCheckoutPayload($location, $variant, Str::uuid()->toString(), '2.000'))->assertSessionHasNoErrors();
    $sale = Sale::query()->sole();

    $this->actingAs($user)->post(route('sales.returns.store', $sale), [
        'operation_key' => Str::uuid()->toString(),
        'location_id' => $location->id,
        'reason' => 'Customer return',
        'lines' => [['sale_line_id' => $sale->lines()->sole()->id, 'quantity' => '1.000', 'restock' => true]],
        'refund_amount' => '100.00',
        'refund_method' => 'cash',
    ])->assertRedirect(route('sales.show', $sale))->assertSessionHasNoErrors();

    expect(SaleReturn::query()->count())->toBe(1)
        ->and($sale->fresh()->status->value)->toBe('partially_returned')
        ->and(InventoryStock::query()->sole()->quantity_on_hand)->toBe('3.000')
        ->and(StockMovement::query()->where('movement_type', 'sale_return')->sole()->quantity_delta)->toBe('1.000');
});

test('a non-saleable quantity return is recorded without restoring available stock', function () {
    ['user' => $user, 'location' => $location, 'quantityVariant' => $variant] = salesFeatureContext(
        'sales.pos', 'sales.create', 'sales.view', 'sales.returns.create', 'sale-returns.create', 'sale-refunds.create', 'inventory.opening',
    );
    receiveSaleTestStock($user, $location, $variant, '2.000');
    $this->actingAs($user)->post(route('pos.checkout'), saleCheckoutPayload($location, $variant, Str::uuid()->toString()))->assertSessionHasNoErrors();
    $sale = Sale::query()->sole();

    $this->actingAs($user)->post(route('sales.returns.store', $sale), [
        'operation_key' => Str::uuid()->toString(),
        'location_id' => $location->id,
        'reason' => 'Faulty item',
        'lines' => [['sale_line_id' => $sale->lines()->sole()->id, 'quantity' => '1.000', 'restock' => false]],
        'refund_amount' => '100.00',
        'refund_method' => 'cash',
    ])->assertRedirect(route('sales.show', $sale))->assertSessionHasNoErrors();

    expect(InventoryStock::query()->sole()->quantity_on_hand)->toBe('1.000')
        ->and(StockMovement::query()->where('movement_type', 'sale_return')->count())->toBe(0)
        ->and(SaleReturn::query()->sole()->lines()->sole()->restock)->toBeFalse();
});

test('user without sales permission cannot open the point of sale', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('pos.index'))->assertForbidden();
});

test('sales details return only authorized sale records', function () {
    ['user' => $user, 'business' => $business, 'location' => $location, 'quantityVariant' => $variant] = salesFeatureContext(
        'sales.pos', 'sales.create', 'sales.view', 'inventory.opening',
    );
    $business->update([
        'invoice_title' => 'Tax invoice',
        'invoice_paper_size' => 'thermal',
        'invoice_thermal_width' => 58,
        'invoice_accent_color' => '#1464A5',
        'invoice_show_logo' => false,
        'invoice_show_product_codes' => false,
        'invoice_show_line_discounts' => true,
    ]);
    receiveSaleTestStock($user, $location, $variant, '2.000');
    $this->actingAs($user)->post(route('pos.checkout'), saleCheckoutPayload($location, $variant, Str::uuid()->toString()))->assertSessionHasNoErrors();
    $sale = Sale::query()->sole();

    $this->actingAs($user)->get(route('sales.show', $sale))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('sales/Show')->where('sale.sale_number', $sale->sale_number));

    $this->actingAs($user)->get(route('sales.receipt', ['sale' => $sale, 'autoprint' => 1]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sales/Receipt')
            ->where('business.invoice_title', 'Tax invoice')
            ->where('business.invoice_paper_size', 'thermal')
            ->where('business.invoice_thermal_width', 58)
            ->where('business.invoice_accent_color', '#1464A5')
            ->where('business.invoice_show_logo', false)
            ->where('business.invoice_show_product_codes', false)
            ->where('business.invoice_show_line_discounts', true)
            ->where('autoPrint', true),
        );
});

test('credit checkout requires a customer and saves reusable customer records', function () {
    ['user' => $user, 'business' => $business, 'location' => $location, 'quantityVariant' => $variant] = salesFeatureContext(
        'sales.pos', 'sales.create', 'sales.view', 'sales.credit', 'inventory.opening', 'sale-payments.create',
    );
    receiveSaleTestStock($user, $location, $variant, '4.000');
    $payload = saleCheckoutPayload($location, $variant, Str::uuid()->toString());
    $payload['is_credit'] = true;
    $payload['payments'] = [];
    $this->actingAs($user)->post(route('pos.checkout'), $payload)->assertSessionHasErrors('customer_id');
    expect(Sale::count())->toBe(0);

    $payload['customer_phone'] = '077 123 4567';
    $this->post(route('pos.checkout'), $payload)->assertSessionHasNoErrors();
    $sale = Sale::query()->sole();
    $customer = Customer::query()->sole();
    expect($sale->customer_id)->toBe($customer->id)
        ->and($sale->payment_status->value)->toBe('unpaid')
        ->and($customer->phone)->toBe('+94771234567');
    $this->post(route('pos.checkout'), $payload)->assertSessionHasNoErrors();
    expect(Sale::count())->toBe(1)->and(Customer::count())->toBe(1);

    $this->get(route('sales.credit'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('sales/Index')->where('creditOnly', true)->has('sales.data', 1));
    foreach (['30.00', '70.00'] as $amount) {
        $this->post(route('sales.payments.store', $sale), [
            'operation_key' => Str::uuid()->toString(), 'amount' => $amount, 'method' => 'cash',
        ])->assertSessionHasNoErrors();
    }
    expect($sale->fresh()->payment_status->value)->toBe('paid');
    $this->get(route('sales.credit'))->assertInertia(fn (Assert $page) => $page->has('sales.data', 1)->where('sales.data.0.outstanding', '0.00'));

    $payload['operation_key'] = Str::uuid()->toString();
    $payload['customer_id'] = $customer->id;
    unset($payload['customer_name'], $payload['customer_phone']);
    $this->post(route('pos.checkout'), $payload)->assertSessionHasNoErrors();
    expect(Customer::count())->toBe(1);
});

test('credit checkout rejects customers from another business and missing credit permission', function () {
    ['user' => $user, 'location' => $location, 'quantityVariant' => $variant] = salesFeatureContext(
        'sales.pos', 'sales.create', 'sales.view', 'sales.credit', 'inventory.opening',
    );
    receiveSaleTestStock($user, $location, $variant, '2.000');
    $other = Business::factory()->create(['setup_key' => 'other']);
    $customer = Customer::query()->create(['business_id' => $other->id, 'name' => 'Other', 'phone' => '123']);
    $payload = saleCheckoutPayload($location, $variant, Str::uuid()->toString());
    $payload['is_credit'] = true;
    $payload['payments'] = [];
    $payload['customer_id'] = $customer->id;
    $this->actingAs($user)->post(route('pos.checkout'), $payload)->assertSessionHasErrors('customer_id');
    $user->revokePermissionTo('sales.credit');
    $this->post(route('pos.checkout'), $payload)->assertSessionHasErrors('payments');
    expect(Sale::count())->toBe(0);
});

test('more items append to the same credit invoice exactly once and preserve prior payments', function () {
    ['user' => $user, 'location' => $location, 'quantityVariant' => $variant] = salesFeatureContext(
        'sales.pos', 'sales.create', 'sales.view', 'sales.credit', 'inventory.opening',
    );
    receiveSaleTestStock($user, $location, $variant, '5.000');
    $payload = saleCheckoutPayload($location, $variant, Str::uuid()->toString());
    $payload['is_credit'] = true;
    $payload['customer_phone'] = '0771234567';
    $payload['payments'] = [['amount' => '30.00', 'method' => 'cash']];
    $this->actingAs($user)->post(route('pos.checkout'), $payload)->assertSessionHasNoErrors();
    $sale = Sale::query()->sole();
    $number = $sale->sale_number;
    $this->get(route('sales.show', $sale))->assertInertia(fn (Assert $page) => $page->where('canAddItems', true));
    $this->get(route('pos.index', ['credit_sale_id' => $sale->id, 'location_id' => $location->id]))
        ->assertInertia(fn (Assert $page) => $page->where('creditSale.id', $sale->id)->where('creditSale.outstanding', '70.00'));
    $addition = saleCheckoutPayload($location, $variant, Str::uuid()->toString(), '2.000');
    $addition['credit_sale_id'] = $sale->id;
    $addition['payments'] = [['amount' => '20.00', 'method' => 'cash']];
    unset($addition['customer_name']);
    $this->post(route('pos.checkout'), $addition)->assertSessionHasNoErrors()->assertRedirect(route('sales.show', $sale));
    $this->post(route('pos.checkout'), $addition)->assertSessionHasNoErrors();
    $sale->refresh();
    expect(Sale::count())->toBe(1)->and($sale->sale_number)->toBe($number)
        ->and($sale->total)->toBe('300.00')->and($sale->paid_total)->toBe('50.00')
        ->and($sale->lines()->count())->toBe(2)->and($sale->payments()->count())->toBe(2)
        ->and(InventoryStock::query()->sole()->quantity_on_hand)->toBe('2.000')
        ->and(app(SaleService::class)->outstanding($sale)->__toString())->toBe('250.00');
    $addition['lines'][0]['quantity'] = '3.000';
    $this->post(route('pos.checkout'), $addition)->assertSessionHasErrors('operation_key');
    expect($sale->fresh()->total)->toBe('300.00');
});

test('credit invoice additions roll back when stock is insufficient and reject settled invoices', function () {
    ['user' => $user, 'location' => $location, 'quantityVariant' => $variant] = salesFeatureContext(
        'sales.pos', 'sales.create', 'sales.view', 'sales.credit', 'inventory.opening', 'sale-payments.create',
    );
    receiveSaleTestStock($user, $location, $variant, '2.000');
    $payload = saleCheckoutPayload($location, $variant, Str::uuid()->toString());
    $payload['is_credit'] = true;
    $payload['customer_phone'] = '0771234567';
    $payload['payments'] = [];
    $this->actingAs($user)->post(route('pos.checkout'), $payload)->assertSessionHasNoErrors();
    $sale = Sale::query()->sole();
    $addition = saleCheckoutPayload($location, $variant, Str::uuid()->toString(), '3.000');
    $addition['credit_sale_id'] = $sale->id;
    $addition['payments'] = [];
    $this->post(route('pos.checkout'), $addition)->assertSessionHasErrors();
    expect($sale->fresh()->total)->toBe('100.00')->and($sale->lines()->count())->toBe(1)
        ->and(SaleAddition::count())->toBe(0)
        ->and(InventoryStock::query()->sole()->quantity_on_hand)->toBe('1.000');
    $this->post(route('sales.payments.store', $sale), ['operation_key' => Str::uuid()->toString(), 'amount' => '100.00', 'method' => 'cash'])->assertSessionHasNoErrors();
    $addition['lines'][0]['quantity'] = '1.000';
    $this->post(route('pos.checkout'), $addition)->assertSessionHasErrors('credit_sale_id');
});

test('faulty replacement keeps invoice balance and payments while moving returned units to damaged stock', function () {
    ['user' => $user, 'location' => $location, 'quantityVariant' => $variant] = salesFeatureContext(
        'sales.pos', 'sales.create', 'sales.view', 'inventory.opening', 'sale-returns.create', 'inventory.view',
    );
    receiveSaleTestStock($user, $location, $variant, '4.000');
    $this->actingAs($user)->post(route('pos.checkout'), saleCheckoutPayload($location, $variant, Str::uuid()->toString()))->assertSessionHasNoErrors();
    $sale = Sale::query()->sole();
    $original = $sale->lines()->sole();
    $payload = [
        'operation_key' => Str::uuid()->toString(), 'location_id' => $location->id, 'reason' => 'Faulty item',
        'is_exchange' => true, 'refund_amount' => '0.00',
        'lines' => [['sale_line_id' => $original->id, 'quantity' => '1.000', 'restock' => false]],
        'replacements' => [['sale_line_id' => $original->id]],
    ];
    $this->post(route('sales.returns.store', $sale), $payload)->assertSessionHasNoErrors();
    $this->post(route('sales.returns.store', $sale), $payload)->assertSessionHasNoErrors();
    $sale->refresh();
    $return = SaleReturn::query()->sole();
    expect($return->is_exchange)->toBeTrue()->and($return->refunds()->count())->toBe(0)
        ->and($sale->paid_total)->toBe('100.00')->and($sale->payment_status->value)->toBe('paid')
        ->and($sale->lines()->count())->toBe(2)
        ->and(app(SaleService::class)->outstanding($sale)->__toString())->toBe('0.00')
        ->and(InventoryStock::query()->sole()->quantity_on_hand)->toBe('2.000')
        ->and(DamagedStockEntry::query()->sole()->remaining_quantity)->toBe('1.000');
    $this->get(route('inventory.damaged.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('inventory/Damaged')->has('entries.data', 1)->where('entries.data.0.invoice', $sale->sale_number));
    // The replacement remains returnable if the customer encounters another fault.
    expect($sale->lines()->where('sale_return_id', $return->id)->sole()->returnLines()->count())->toBe(0);
});

test('replacement stock shortage rolls back both the damage receipt and original return', function () {
    ['user' => $user, 'location' => $location, 'quantityVariant' => $variant] = salesFeatureContext(
        'sales.pos', 'sales.create', 'sales.view', 'inventory.opening', 'sale-returns.create',
    );
    receiveSaleTestStock($user, $location, $variant, '1.000');
    $this->actingAs($user)->post(route('pos.checkout'), saleCheckoutPayload($location, $variant, Str::uuid()->toString()))->assertSessionHasNoErrors();
    $sale = Sale::query()->sole();
    $line = $sale->lines()->sole();
    $this->post(route('sales.returns.store', $sale), [
        'operation_key' => Str::uuid()->toString(), 'location_id' => $location->id, 'reason' => 'Faulty item',
        'is_exchange' => true, 'refund_amount' => '0.00',
        'lines' => [['sale_line_id' => $line->id, 'quantity' => '1.000', 'restock' => false]],
        'replacements' => [['sale_line_id' => $line->id]],
    ])->assertSessionHasErrors();
    expect(SaleReturn::count())->toBe(0)->and(DamagedStockEntry::count())->toBe(0)
        ->and($sale->fresh()->total)->toBe('100.00')->and($sale->lines()->count())->toBe(1)
        ->and(InventoryStock::query()->sole()->quantity_on_hand)->toBe('0.000');
});

test('serialized replacement quarantines the old IMEI and sells the selected new IMEI', function () {
    ['user' => $user, 'business' => $business, 'category' => $category, 'location' => $location] = salesFeatureContext(
        'sales.pos', 'sales.create', 'sales.view', 'inventory.receive', 'sale-returns.create', 'inventory.view',
    );
    $product = Product::factory()->serialized()->for($business)->for($category)->create();
    $variant = ProductVariant::factory()->serialized()->for($business)->for($product)->create(['selling_price' => '100.00']);
    app(InventoryService::class)->receive($user, [
        'operation_key' => Str::uuid()->toString(), 'location_id' => $location->id, 'product_variant_id' => $variant->id,
        'mode' => 'serialized', 'devices' => [
            ['imei' => '356789012345671', 'condition' => 'new', 'unit_cost' => '50.00'],
            ['imei' => '356789012345672', 'condition' => 'new', 'unit_cost' => '50.00'],
        ],
    ]);
    $payload = saleCheckoutPayload($location, $variant, Str::uuid()->toString());
    $payload['lines'][0]['device_identifier'] = '356789012345671';
    $this->actingAs($user)->post(route('pos.checkout'), $payload)->assertSessionHasNoErrors();
    $sale = Sale::query()->sole();
    $line = $sale->lines()->sole();
    $this->post(route('sales.returns.store', $sale), [
        'operation_key' => Str::uuid()->toString(), 'location_id' => $location->id, 'reason' => 'Faulty item',
        'is_exchange' => true, 'refund_amount' => '0.00',
        'lines' => [['sale_line_id' => $line->id, 'quantity' => '1.000', 'restock' => false]],
        'replacements' => [['sale_line_id' => $line->id, 'device_identifier' => '356789012345672']],
    ])->assertSessionHasNoErrors();
    expect(SerializedDevice::query()->where('imei', '356789012345671')->sole()->status->value)->toBe('damaged')
        ->and(SerializedDevice::query()->where('imei', '356789012345672')->sole()->status->value)->toBe('sold')
        ->and($sale->lines()->whereNotNull('sale_return_id')->sole()->imei_snapshot)->toBe('356789012345672')
        ->and(app(SaleService::class)->outstanding($sale->fresh())->__toString())->toBe('0.00');
    $this->get(route('inventory.damaged.index'))->assertInertia(fn (Assert $page) => $page->has('devices.data', 1)->where('devices.data.0.imei', '356789012345671'));
});

test('discounted credit replacement preserves the outstanding debt without a refund', function () {
    ['user' => $user, 'location' => $location, 'quantityVariant' => $variant] = salesFeatureContext(
        'sales.pos', 'sales.create', 'sales.view', 'sales.credit', 'sales.discount', 'inventory.opening', 'sale-returns.create',
    );
    receiveSaleTestStock($user, $location, $variant, '4.000');
    $payload = saleCheckoutPayload($location, $variant, Str::uuid()->toString(), '2.000');
    $payload['is_credit'] = true;
    $payload['customer_phone'] = '0771234567';
    $payload['order_discount'] = '10.00';
    $payload['payments'] = [['amount' => '20.00', 'method' => 'cash']];
    $this->actingAs($user)->post(route('pos.checkout'), $payload)->assertSessionHasNoErrors();
    $sale = Sale::query()->sole();
    $line = $sale->lines()->sole();
    $replacement = [
        'operation_key' => Str::uuid()->toString(), 'location_id' => $location->id, 'reason' => 'Faulty item',
        'is_exchange' => true, 'refund_amount' => '0.00',
        'lines' => [['sale_line_id' => $line->id, 'quantity' => '1.000', 'restock' => false]],
        'replacements' => [['sale_line_id' => $line->id]],
    ];
    $this->post(route('sales.returns.store', $sale), $replacement)->assertSessionHasNoErrors();
    expect(app(SaleService::class)->outstanding($sale->fresh())->__toString())->toBe('170.00')
        ->and($sale->fresh()->paid_total)->toBe('20.00')
        ->and($sale->lines()->whereNotNull('sale_return_id')->sole()->total)->toBe('95.00')
        ->and(SaleReturn::query()->sole()->total)->toBe('95.00')
        ->and(SaleReturn::query()->sole()->refund_total)->toBe('0.00');
});

test('faulty refund cannot silently put damaged items back into saleable stock', function () {
    ['user' => $user, 'location' => $location, 'quantityVariant' => $variant] = salesFeatureContext(
        'sales.pos', 'sales.create', 'sales.view', 'inventory.opening', 'sale-returns.create', 'sale-refunds.create',
    );
    receiveSaleTestStock($user, $location, $variant, '2.000');
    $this->actingAs($user)->post(route('pos.checkout'), saleCheckoutPayload($location, $variant, Str::uuid()->toString()))->assertSessionHasNoErrors();
    $sale = Sale::query()->sole();
    $this->post(route('sales.returns.store', $sale), [
        'operation_key' => Str::uuid()->toString(), 'location_id' => $location->id, 'reason' => 'Faulty item',
        'refund_amount' => '100.00', 'refund_method' => 'cash',
        'lines' => [['sale_line_id' => $sale->lines()->sole()->id, 'quantity' => '1.000', 'restock' => true]],
    ])->assertSessionHasNoErrors();
    expect(InventoryStock::query()->sole()->quantity_on_hand)->toBe('1.000')
        ->and(DamagedStockEntry::query()->sole()->remaining_quantity)->toBe('1.000')
        ->and(SaleReturn::query()->sole()->lines()->sole()->restock)->toBeFalse();
});

test('return print records the return-specific balance and remains unchanged after a later credit payment', function () {
    ['user' => $user, 'location' => $location, 'quantityVariant' => $variant] = salesFeatureContext(
        'sales.pos', 'sales.create', 'sales.view', 'sales.credit', 'inventory.opening', 'sale-returns.create', 'sale-refunds.create', 'sale-payments.create',
    );
    receiveSaleTestStock($user, $location, $variant, '3.000');
    $payload = saleCheckoutPayload($location, $variant, Str::uuid()->toString(), '2.000');
    $payload['customer_phone'] = '0771234567';
    $payload['payments'] = [['amount' => '20.00', 'method' => 'cash']];
    $this->actingAs($user)->post(route('pos.checkout'), $payload)->assertSessionHasNoErrors();
    $sale = Sale::query()->sole();
    $this->post(route('sales.returns.store', $sale), [
        'operation_key' => Str::uuid()->toString(), 'location_id' => $location->id, 'reason' => 'Faulty item',
        'refund_amount' => '20.00', 'refund_method' => 'cash',
        'lines' => [['sale_line_id' => $sale->lines()->sole()->id, 'quantity' => '1.000', 'restock' => false]],
    ])->assertSessionHasNoErrors();
    $return = SaleReturn::query()->sole();
    $this->post(route('sales.payments.store', $sale), ['operation_key' => Str::uuid()->toString(), 'amount' => '100.00', 'method' => 'cash'])->assertSessionHasNoErrors();
    $this->get(route('sales.returns.receipt', [$sale, $return]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('sales/ReturnReceipt')->where('receipt.return_number', $return->return_number)
        ->where('receipt.refund_total', '20.00')->where('receipt.balance', '100.00')->where('receipt.net_total', '100.00')
        ->where('receipt.net_paid', '0.00')->where('receipt.balance_is_snapshot', true)
        ->where('receipt.lines.0.condition', 'Damaged stock')->where('receipt.lines.0.sku', $variant->sku));
    $different = Sale::factory()->create(['business_id' => $sale->business_id, 'location_id' => $location->id, 'cashier_id' => $user->id]);
    $this->get(route('sales.returns.receipt', [$different, $return]))->assertNotFound();
});

test('later credit additions and replacements update dashboard dates without rewriting the original day', function () {
    $this->travelTo(Carbon::parse('2026-10-20 09:00:00', 'UTC'));
    ['user' => $user, 'business' => $business, 'location' => $location, 'quantityVariant' => $variant] = salesFeatureContext(
        'sales.pos', 'sales.create', 'sales.view', 'sales.credit', 'inventory.opening', 'sale-returns.create', 'sales.costs.view', 'dashboard.view',
    );
    receiveSaleTestStock($user, $location, $variant, '5.000');
    $payload = saleCheckoutPayload($location, $variant, Str::uuid()->toString());
    $payload['customer_phone'] = '0771234567';
    $payload['payments'] = [['amount' => '20.00', 'method' => 'cash']];
    $this->actingAs($user)->post(route('pos.checkout'), $payload)->assertSessionHasNoErrors();
    $sale = Sale::query()->sole();
    $original = $sale->lines()->sole();
    $this->travelTo(Carbon::parse('2026-10-21 09:00:00', 'UTC'));
    $addition = saleCheckoutPayload($location, $variant, Str::uuid()->toString());
    $addition['credit_sale_id'] = $sale->id;
    $addition['payments'] = [];
    $this->post(route('pos.checkout'), $addition)->assertSessionHasNoErrors();
    $this->travelTo(Carbon::parse('2026-10-22 09:00:00', 'UTC'));
    $this->post(route('sales.returns.store', $sale), [
        'operation_key' => Str::uuid()->toString(), 'location_id' => $location->id, 'reason' => 'Faulty item',
        'is_exchange' => true, 'refund_amount' => '0.00',
        'lines' => [['sale_line_id' => $original->id, 'quantity' => '1.000', 'restock' => false]],
        'replacements' => [['sale_line_id' => $original->id]],
    ])->assertSessionHasNoErrors();
    $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('overview.sales_total', '100.00')->where('overview.returns', '100.00')
        ->where('overview.outstanding', '180.00')->where('trend.27.net_sales', '100.00')
        ->where('trend.28.net_sales', '100.00')->where('trend.29.net_sales', '0.00')->where('trend.29.gross_profit', '0.00'));
    $return = SaleReturn::query()->sole();
    $this->get(route('sales.returns.receipt', [$sale, $return]))->assertInertia(fn (Assert $page) => $page
        ->where('receipt.is_exchange', true)->has('receipt.replacements', 1)->where('receipt.balance', '180.00'));
});
