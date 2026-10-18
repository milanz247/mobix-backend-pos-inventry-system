<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Business;
use App\Models\Category;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\SerializedDevice;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\InventoryService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
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

test('sales report is permission protected and scoped to accessible locations', function () {
    ['user' => $user, 'location' => $location] = salesFeatureContext('sales.reports.view');

    $this->actingAs($user)->get(route('sales.reports', ['location_id' => $location->id]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('sales/Reports')->where('summary.sales_count', 0));

    $unauthorized = User::factory()->create();
    $this->actingAs($unauthorized)->get(route('sales.reports'))->assertForbidden();
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

test('POS exposes location stock and stock receiving access for eligible products', function () {
    ['user' => $user, 'location' => $location, 'quantityVariant' => $variant] = salesFeatureContext(
        'sales.pos', 'inventory.receive',
    );

    $this->actingAs($user)
        ->get(route('pos.index', ['location_id' => $location->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sales/Pos')
            ->where('canReceiveStock', true)
            ->where('posSettings.show_receipt_after_sale', true)
            ->where('posSettings.auto_print_receipt', false)
            ->where('posSettings.require_imei_on_sale', true)
            ->where('posSettings.products_per_page', 24)
            ->where('variants.data.0.id', $variant->id)
            ->where('variants.data.0.quantity_on_hand', '0.000'));
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
