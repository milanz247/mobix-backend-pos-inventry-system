<?php

use App\Models\Brand;
use App\Models\Business;
use App\Models\CashierShift;
use App\Models\CashMovement;
use App\Models\Category;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SerializedDevice;
use App\Models\User;
use App\Services\CashierShiftService;
use App\Services\InventoryService;
use App\Services\SaleService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

/** @return array{user: User, business: Business, location: Location} */
function detailedReportContext(): array
{
    $business = Business::factory()->create();
    $location = Location::factory()->for($business)->create();
    $user = User::factory()->create();
    $location->users()->attach($user);
    foreach (['inventory.view', 'inventory.opening', 'products.view', 'shifts.view', 'shifts.open', 'shifts.movements', 'shifts.close', 'sales.view', 'sales.pos', 'sales.create', 'sale-returns.create', 'sale-refunds.create'] as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return compact('user', 'business', 'location');
}

function reportVariant(array $context, string $sku, string $type = 'simple'): ProductVariant
{
    $category = Category::factory()->for($context['business'])->create();
    $product = Product::factory()->for($context['business'])->for($category)->create(['name' => $sku.' Product', 'product_type' => $type]);

    return ProductVariant::factory()->for($context['business'])->for($product)->create([
        'sku' => $sku, 'barcode' => 'BAR-'.$sku, 'selling_price' => '100.00',
        'tracking_mode' => $type === 'serialized' ? 'serialized' : 'quantity_based',
    ]);
}

function reportBalance(array $context, ProductVariant $variant, string $quantity): void
{
    InventoryStock::factory()->for($context['business'])->for($context['location'])->for($variant, 'variant')->create(['quantity_on_hand' => $quantity]);
}

function reportShift(array $context): CashierShift
{
    return app(CashierShiftService::class)->open($context['user'], [
        'location_id' => $context['location']->id, 'operation_key' => (string) Str::uuid(), 'opening_cash' => '1000.00',
    ]);
}

function reportExpense(array $context, CashierShift $shift, string $amount = '50.00'): CashMovement
{
    return app(CashierShiftService::class)->movement($context['user'], $shift, [
        'operation_key' => (string) Str::uuid(), 'type' => 'expense', 'amount' => $amount,
        'reason' => 'Shop operational expense', 'supporting_reference' => 'Receipt EXP-001',
    ]);
}

/** @return array<string, mixed> */
function reportSalePayload(array $context, ProductVariant $variant): array
{
    return [
        'operation_key' => (string) Str::uuid(), 'location_id' => $context['location']->id, 'source' => 'in_store_pos',
        'customer_name' => 'Report Customer', 'customer_phone' => '0771234567',
        'lines' => [['product_variant_id' => $variant->id, 'quantity' => '1.000', 'discount_amount' => '0.00']],
        'payments' => [['method' => 'cash', 'amount' => '40.00', 'cash_tendered' => '100.00'], ['method' => 'card', 'amount' => '60.00']],
    ];
}

test('zero stock stays visible in POS while the backend rejects unavailable inventory', function () {
    $context = detailedReportContext();
    $variant = reportVariant($context, 'EMPTY');
    reportShift($context);
    $this->actingAs($context['user'])->get(route('pos.index'))->assertInertia(fn (Assert $page) => $page
        ->has('variants.data', 1)->where('variants.data.0.sku', 'EMPTY')->where('variants.data.0.can_sell', false)
        ->where('variants.data.0.stock_status', 'out_of_stock')->where('variants.data.0.available_quantity', '0.000')
        ->missing('canReceiveStock')->missing('canOpenStock'));
    $this->post(route('pos.checkout'), reportSalePayload($context, $variant))->assertSessionHasErrors();
    expect(Sale::query()->count())->toBe(0);
});

test('out of stock report includes missing balances and flags negative data without listing stocked variants', function () {
    $context = detailedReportContext();
    $zero = reportVariant($context, 'ZERO');
    $negative = reportVariant($context, 'NEGATIVE');
    reportBalance($context, $negative, '-2.000');
    $available = reportVariant($context, 'AVAILABLE');
    reportBalance($context, $available, '7.000');
    $this->actingAs($context['user'])->get(route('reports.out-of-stock'))->assertInertia(fn (Assert $page) => $page
        ->component('reports/OutOfStock')->has('rows.data', 2)->where('summary.zero_stock', 1)->where('summary.inconsistent', 1)
        ->where('rows.data.0.sku', 'NEGATIVE')->where('rows.data.0.available_quantity', '-2.000')->where('rows.data.0.stock_status', 'inconsistent')
        ->where('rows.data.1.sku', 'ZERO')->where('rows.data.1.url', route('products.show', $zero->product_id))->etc()
    );
});

test('serialized stock uses eligible devices at the selected location rather than catalogue counts', function () {
    $context = detailedReportContext();
    $empty = reportVariant($context, 'PHONE-EMPTY', 'serialized');
    $stocked = reportVariant($context, 'PHONE-STOCKED', 'serialized');
    $other = Location::factory()->for($context['business'])->create();
    SerializedDevice::factory()->create(['business_id' => $context['business']->id, 'product_variant_id' => $empty->id, 'location_id' => $context['location']->id, 'status' => 'sold', 'created_by' => $context['user']->id]);
    SerializedDevice::factory()->create(['business_id' => $context['business']->id, 'product_variant_id' => $empty->id, 'location_id' => $other->id, 'status' => 'in_stock', 'created_by' => $context['user']->id]);
    SerializedDevice::factory()->create(['business_id' => $context['business']->id, 'product_variant_id' => $stocked->id, 'location_id' => $context['location']->id, 'status' => 'in_stock', 'created_by' => $context['user']->id]);
    $this->actingAs($context['user'])->get(route('reports.out-of-stock', ['product_type' => 'serialized']))
        ->assertInertia(fn (Assert $page) => $page->has('rows.data', 1)->where('rows.data.0.sku', 'PHONE-EMPTY')->where('rows.data.0.available_quantity', '0.000'));
    $this->get(route('pos.index', ['search' => 'PHONE-STOCKED']))->assertInertia(fn (Assert $page) => $page
        ->where('variants.data.0.devices_in_stock', 1)->where('variants.data.0.available_quantity', '1.000')->where('variants.data.0.can_sell', true));
});

test('bundle cards and out of stock reports use component constrained capacity', function () {
    $context = detailedReportContext();
    $component = reportVariant($context, 'COMPONENT');
    $bundle = reportVariant($context, 'BUNDLE', 'combo');
    $bundle->bundleComponents()->create(['business_id' => $context['business']->id, 'component_variant_id' => $component->id, 'quantity' => '2.000']);
    $this->actingAs($context['user'])->get(route('pos.index', ['search' => 'BUNDLE']))->assertInertia(fn (Assert $page) => $page
        ->where('variants.data.0.can_sell', false)->where('variants.data.0.available_quantity', '0.000'));
    $this->get(route('reports.out-of-stock', ['product_type' => 'combo']))->assertInertia(fn (Assert $page) => $page->has('rows.data', 1)->where('rows.data.0.sku', 'BUNDLE'));
    reportBalance($context, $component, '5.000');
    $this->get(route('pos.index', ['search' => 'BUNDLE']))->assertInertia(fn (Assert $page) => $page
        ->where('variants.data.0.can_sell', true)->where('variants.data.0.available_quantity', '2.000'));
    $this->get(route('reports.out-of-stock', ['product_type' => 'combo']))->assertInertia(fn (Assert $page) => $page->has('rows.data', 0));
    $component->product->update(['status' => 'inactive']);
    $this->get(route('pos.index', ['search' => 'BUNDLE']))->assertInertia(fn (Assert $page) => $page
        ->where('variants.data.0.can_sell', false)->where('variants.data.0.stock_status', 'inconsistent'));
    reportShift($context);
    $this->post(route('pos.checkout'), reportSalePayload($context, $bundle))->assertSessionHasErrors();
    expect(Sale::query()->count())->toBe(0);
});

test('stock filters search barcode category brand and type and paginate results', function () {
    $context = detailedReportContext();
    $target = reportVariant($context, 'TARGET');
    $brand = Brand::factory()->for($context['business'])->create();
    $target->product->update(['brand_id' => $brand->id]);
    reportVariant($context, 'OTHER');
    $this->actingAs($context['user'])->get(route('reports.out-of-stock', [
        'search' => 'BAR-TARGET', 'category_id' => $target->product->category_id, 'brand_id' => $brand->id, 'product_type' => 'simple', 'stock_status' => 'out_of_stock',
    ]))->assertInertia(fn (Assert $page) => $page->has('rows.data', 1)->where('rows.data.0.id', $target->id));
    ProductVariant::factory()->count(26)->for($context['business'])->for($target->product)->create();
    $this->get(route('reports.out-of-stock'))->assertInertia(fn (Assert $page) => $page->has('rows.data', 25)->where('rows.total', 28));
});

test('last movement date is available for zero balances', function () {
    $context = detailedReportContext();
    $variant = reportVariant($context, 'LAST-MOVE');
    app(InventoryService::class)->receive($context['user'], [
        'operation_key' => (string) Str::uuid(), 'location_id' => $context['location']->id,
        'product_variant_id' => $variant->id, 'mode' => 'quantity', 'quantity' => '1.000', 'reason' => 'Initial stock',
    ], true);
    reportShift($context);
    app(SaleService::class)->checkout($context['user'], reportSalePayload($context, $variant));
    $this->actingAs($context['user'])->get(route('reports.out-of-stock'))->assertInertia(fn (Assert $page) => $page
        ->has('rows.data', 1)->where('rows.data.0.available_quantity', '0.000')->where('rows.data.0.last_movement_at', fn ($value) => is_string($value) && $value !== ''));
});

test('stock report inventory links can show a variant without a stock record when catalogue access is absent', function () {
    $context = detailedReportContext();
    $variant = reportVariant($context, 'EMPTY-DETAIL');
    $context['user']->revokePermissionTo('products.view');
    $url = route('inventory.locations.index', ['location_id' => $context['location']->id, 'search' => $variant->sku]);
    $this->actingAs($context['user'])->get(route('reports.out-of-stock'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data.0.url', $url));
    $this->get($url)->assertInertia(fn (Assert $page) => $page
        ->has('stocks.data', 1)->where('stocks.data.0.sku', 'EMPTY-DETAIL')->where('stocks.data.0.quantity_on_hand', '0.000'));
});

test('reports require permission and reject unauthorized location contexts', function () {
    $context = detailedReportContext();
    $other = Location::factory()->for($context['business'])->create();
    $this->actingAs($context['user'])->get(route('reports.out-of-stock', ['location_id' => $other->id]))->assertNotFound();
    $this->get(route('reports.expenses', ['location_id' => $other->id]))->assertNotFound();
    $this->get(route('reports.current-shift', ['location_id' => $other->id]))->assertNotFound();
    $unauthorized = User::factory()->create();
    $this->actingAs($unauthorized)->get(route('reports.out-of-stock'))->assertForbidden();
    $this->get(route('reports.expenses'))->assertForbidden();
    $this->get(route('reports.current-shift'))->assertForbidden();
});

test('expense report traces the single drawer movement and returns complete detail', function () {
    $context = detailedReportContext();
    $shift = reportShift($context);
    $expense = reportExpense($context, $shift, '75.25');
    $this->actingAs($context['user'])->get(route('reports.expenses'))->assertInertia(fn (Assert $page) => $page
        ->component('reports/Expenses')->where('summary.total_expenses', '75.25')->where('summary.cash_expenses', '75.25')
        ->has('rows.data', 1)->where('rows.data.0.reference', $expense->reference)->where('rows.data.0.status', 'completed')
        ->where('rows.data.0.url', route('shifts.movement', [$shift, $expense])));
    $this->get(route('shifts.movement', [$shift, $expense]))->assertInertia(fn (Assert $page) => $page
        ->component('reports/CashMovement')->where('movement.supporting_reference', 'Receipt EXP-001')
        ->where('movement.creator.name', $context['user']->name)->where('shift.location.id', $context['location']->id)->has('events', 1));
    expect(app(CashierShiftService::class)->summary($shift)['expected_cash'])->toBe('924.75');
});

test('reversed expenses remain inspectable and are excluded from active expense totals exactly once', function () {
    $context = detailedReportContext();
    $shift = reportShift($context);
    $expense = reportExpense($context, $shift);
    foreach (['shifts.view-all', 'shifts.correct'] as $permission) {
        $context['user']->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    app(CashierShiftService::class)->reverse($context['user'], $shift, $expense, 'Receipt was entered incorrectly');
    $this->actingAs($context['user'])->get(route('reports.expenses'))->assertInertia(fn (Assert $page) => $page
        ->where('summary.total_expenses', '0.00')->has('rows.data', 1)->where('rows.data.0.status', 'reversed'));
    $this->get(route('shifts.movement', [$shift, $expense]))->assertInertia(fn (Assert $page) => $page
        ->where('movement.reversal.reason', 'Receipt was entered incorrectly')->has('events', 2));
    $reversal = $expense->reversal()->sole();
    $this->get(route('shifts.movement', [$shift, $reversal]))->assertInertia(fn (Assert $page) => $page
        ->where('movement.original.id', $expense->id)->has('events', 1));
    expect($shift->movements()->count())->toBe(2)->and(app(CashierShiftService::class)->summary($shift)['expected_cash'])->toBe('1000.00');
});

test('expense filters respect date creator shift source and derived status', function () {
    $context = detailedReportContext();
    $shift = reportShift($context);
    $this->travelTo(Carbon::parse('2026-10-20 10:00:00', 'Asia/Colombo'));
    $expense = reportExpense($context, $shift);
    $this->actingAs($context['user'])->get(route('reports.expenses', [
        'date_from' => '2026-10-20', 'date_to' => '2026-10-20', 'location_id' => $context['location']->id,
        'user_id' => $context['user']->id, 'shift_reference' => $shift->reference, 'source' => 'cash_drawer', 'status' => 'completed',
    ]))->assertInertia(fn (Assert $page) => $page->has('rows.data', 1)->where('rows.data.0.id', $expense->id));
    $this->get(route('reports.expenses', ['date_to' => '2026-10-19']))->assertInertia(fn (Assert $page) => $page->has('rows.data', 0)->where('summary.total_expenses', '0.00'));
});

test('current report defaults to the own active shift and provides each transaction drill down', function () {
    $context = detailedReportContext();
    $variant = reportVariant($context, 'SHIFT-ITEM');
    reportBalance($context, $variant, '5.000');
    $shift = reportShift($context);
    $sale = app(SaleService::class)->checkout($context['user'], reportSalePayload($context, $variant));
    $expense = reportExpense($context, $shift);
    $this->actingAs($context['user'])->get(route('reports.current-shift'))->assertRedirect(route('shifts.show', $shift));
    $this->get(route('shifts.show', $shift))->assertInertia(fn (Assert $page) => $page
        ->component('shifts/Show')->where('summary.cash_sales', '40.00')->where('summary.payments.card', '60.00')
        ->where('summary.expected_cash', '990.00')->has('transactions.data', 1)->where('transactions.data.0.url', route('sales.show', $sale)));
    $payment = $sale->payments()->latest('id')->firstOrFail();
    $this->get(route('shifts.show', ['shift' => $shift, 'section' => 'payments']))->assertInertia(fn (Assert $page) => $page
        ->has('transactions.data', 2)->where('transactions.data.0.url', route('sales.show', $sale).'#payment-'.$payment->id));
    $this->get(route('shifts.show', ['shift' => $shift, 'section' => 'expenses']))->assertInertia(fn (Assert $page) => $page
        ->has('transactions.data', 1)->where('transactions.data.0.amount', '50.00')->where('transactions.data.0.url', route('shifts.movement', [$shift, $expense])));
    $this->get(route('sales.show', $sale))->assertInertia(fn (Assert $page) => $page
        ->where('sale.customer.name', 'Report Customer')->has('sale.lines', 1)->has('sale.payments', 2)->has('sale.payments.0.id'));
});

test('refund drill down exposes persisted noncash refunds without reducing cash drawer totals', function () {
    $context = detailedReportContext();
    $variant = reportVariant($context, 'RETURN-ITEM');
    reportBalance($context, $variant, '2.000');
    $shift = reportShift($context);
    $sale = app(SaleService::class)->checkout($context['user'], reportSalePayload($context, $variant));
    app(SaleService::class)->processReturn($context['user'], $sale, [
        'operation_key' => (string) Str::uuid(), 'location_id' => $context['location']->id, 'reason' => 'Card refund approved', 'notes' => 'Checked by cashier',
        'lines' => [['sale_line_id' => $sale->lines()->sole()->id, 'quantity' => '1.000', 'restock' => true]],
        'refund_amount' => '100.00', 'refund_method' => 'card',
    ]);
    $refund = $sale->refunds()->sole();
    $this->actingAs($context['user'])->get(route('shifts.show', ['shift' => $shift, 'section' => 'refunds']))->assertInertia(fn (Assert $page) => $page
        ->where('summary.expected_cash', '1040.00')->where('summary.cash_refunds', '0.00')->where('summary.refunds.card', '100.00')
        ->has('transactions.data', 1)->where('transactions.data.0.url', route('sales.show', $sale).'#refund-'.$refund->id));
    $this->get(route('sales.show', $sale))->assertInertia(fn (Assert $page) => $page
        ->where('sale.returns.0.notes', 'Checked by cashier')->where('sale.returns.0.refunds.0.method', 'card')->has('sale.returns.0.lines', 1));
});

test('shift cash tabs and saved closing totals reconcile with underlying records', function () {
    $context = detailedReportContext();
    $shift = reportShift($context);
    foreach (['cash_in' => '100', 'cash_out' => '20', 'drop' => '30'] as $type => $amount) {
        app(CashierShiftService::class)->movement($context['user'], $shift, ['operation_key' => (string) Str::uuid(), 'type' => $type, 'amount' => $amount, 'reason' => 'Documented shift movement']);
    }
    $this->actingAs($context['user'])->get(route('shifts.show', ['shift' => $shift, 'section' => 'cash_movements']))->assertInertia(fn (Assert $page) => $page->has('transactions.data', 2)->where('summary.expected_cash', '1050.00'));
    $this->get(route('shifts.show', ['shift' => $shift, 'section' => 'drops']))->assertInertia(fn (Assert $page) => $page->has('transactions.data', 1)->where('transactions.data.0.amount', '30.00'));
    app(CashierShiftService::class)->close($context['user'], $shift, ['actual_cash' => '1045.00', 'variance_reason' => 'Five rupees missing']);
    $this->get(route('shifts.show', ['shift' => $shift, 'section' => 'closing']))->assertInertia(fn (Assert $page) => $page
        ->where('shift.actual_cash', '1045.00')->where('shift.variance', '-5.00')->where('summary.expected_cash', '1050.00')
        ->has('transactions.data', 2)->where('transactions.data.0.url', route('shifts.report', $shift)));
});

test('financial detail routes reject other cashier and unauthorized location records', function () {
    $context = detailedReportContext();
    $shift = reportShift($context);
    $expense = reportExpense($context, $shift);
    $other = User::factory()->create();
    $context['location']->users()->attach($other);
    $other->givePermissionTo(Permission::findOrCreate('shifts.view', 'web'));
    $this->actingAs($other)->get(route('shifts.movement', [$shift, $expense]))->assertNotFound();
    $this->get(route('reports.current-shift', ['shift_id' => $shift->id]))->assertNotFound();
    $this->get(route('reports.expenses'))->assertInertia(fn (Assert $page) => $page->has('rows.data', 0));
    $other->givePermissionTo(Permission::findOrCreate('shifts.view-all', 'web'));
    $context['location']->users()->detach($other);
    $this->get(route('shifts.show', $shift))->assertNotFound();
    $this->get(route('shifts.movement', [$shift, $expense]))->assertNotFound();
});

test('empty report contexts render clear empty states and guest routes require login', function () {
    $this->get(route('reports.out-of-stock'))->assertRedirect(route('login'));
    $context = detailedReportContext();
    $this->actingAs($context['user'])->get(route('reports.current-shift'))->assertInertia(fn (Assert $page) => $page->component('reports/CurrentShift'));
    $this->get(route('reports.expenses'))->assertInertia(fn (Assert $page) => $page->has('rows.data', 0)->where('summary.total_expenses', '0.00'));
    $this->get(route('reports.out-of-stock'))->assertInertia(fn (Assert $page) => $page->has('rows.data', 0));
});

test('expense summary totals remain complete on later pages', function () {
    $context = detailedReportContext();
    $shift = reportShift($context);
    for ($index = 0; $index < 26; $index++) {
        reportExpense($context, $shift, '1.00');
    }
    $this->actingAs($context['user'])->get(route('reports.expenses', ['page' => 2]))
        ->assertInertia(fn (Assert $page) => $page->has('rows.data', 1)->where('rows.total', 26)
            ->where('summary.records', 26)->where('summary.total_expenses', '26.00'));
});

test('serialized bundle components constrain capacity and must use whole device counts', function () {
    $context = detailedReportContext();
    $accessory = reportVariant($context, 'BUNDLE-CABLE');
    reportBalance($context, $accessory, '5.000');
    $phone = reportVariant($context, 'BUNDLE-PHONE', 'serialized');
    SerializedDevice::factory()->create(['business_id' => $context['business']->id, 'product_variant_id' => $phone->id, 'location_id' => $context['location']->id, 'status' => 'in_stock', 'created_by' => $context['user']->id]);
    $bundle = reportVariant($context, 'BUNDLE-PAIR', 'combo');
    $bundle->bundleComponents()->create(['business_id' => $context['business']->id, 'component_variant_id' => $accessory->id, 'quantity' => '2.000']);
    $bundle->bundleComponents()->create(['business_id' => $context['business']->id, 'component_variant_id' => $phone->id, 'quantity' => '1.000']);
    $this->actingAs($context['user'])->get(route('pos.index', ['search' => 'BUNDLE-PAIR']))->assertInertia(fn (Assert $page) => $page
        ->where('variants.data.0.available_quantity', '1.000')->where('variants.data.0.can_sell', true));
    $bundle->bundleComponents()->where('component_variant_id', $phone->id)->update(['quantity' => '0.500']);
    $this->get(route('reports.out-of-stock', ['product_type' => 'combo']))->assertInertia(fn (Assert $page) => $page
        ->has('rows.data', 1)->where('rows.data.0.stock_status', 'inconsistent')->where('rows.data.0.can_sell', false));
});
