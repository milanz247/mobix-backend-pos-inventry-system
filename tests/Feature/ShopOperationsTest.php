<?php

use App\Models\Business;
use App\Models\CashierShift;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DamagedStockEntry;
use App\Models\HeldCart;
use App\Models\InventoryOperation;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\SaleService;
use App\Support\CsvDownload;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

/** @return array{User, Business, Location, ProductVariant} */
function shopOperationsContext(): array
{
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner, 'owner')->create();
    $location = Location::factory()->for($business)->create();
    $user = User::factory()->create();
    $location->users()->attach($user);
    foreach (['shifts.view', 'sales.pos', 'sales.create', 'sales.view', 'sales.credit', 'credit-sales.view', 'customers.view', 'customers.create', 'sales.discount', 'inventory.opening', 'inventory.view', 'inventory.adjust', 'sale-returns.create', 'sale-refunds.create', 'sales.reports.view'] as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    CashierShift::factory()->for($business)->for($location)->for($user, 'cashier')->create();
    $category = Category::factory()->for($business)->create();
    $product = Product::factory()->for($business)->for($category)->create();
    $variant = ProductVariant::factory()->for($business)->for($product)->create(['selling_price' => '100.00', 'cost_price' => '50.00', 'minimum_selling_price' => '80.00']);
    app(InventoryService::class)->receive($user, ['operation_key' => (string) Str::uuid(), 'location_id' => $location->id, 'product_variant_id' => $variant->id, 'mode' => 'quantity', 'quantity' => '20.000', 'reason' => 'Opening'], true);

    return [$user, $business, $location, $variant];
}

/** @return array<string, mixed> */
function shopOperationsSale(Location $location, ProductVariant $variant): array
{
    return ['operation_key' => (string) Str::uuid(), 'location_id' => $location->id, 'source' => 'in_store_pos', 'order_discount' => '0.00', 'lines' => [['product_variant_id' => $variant->id, 'quantity' => '1.000', 'discount_amount' => '0.00']], 'payments' => [['amount' => '100.00', 'method' => 'cash']]];
}

test('minimum price protects the effective price after line and order discounts', function (string $discount) {
    [$user, $business, $location, $variant] = shopOperationsContext();
    $payload = shopOperationsSale($location, $variant);
    if ($discount === 'line') {
        $payload['lines'][0]['discount_amount'] = '21.00';
    } else {
        $payload['order_discount'] = '21.00';
    }
    $payload['payments'][0]['amount'] = '79.00';
    $this->actingAs($user)->post(route('pos.checkout'), $payload)->assertSessionHasErrors('lines.0.discount_amount');
    expect(Sale::query()->count())->toBe(0);
    $user->givePermissionTo(Permission::findOrCreate('sales.price-override', 'web'));
    $this->post(route('pos.checkout'), $payload)->assertSessionHasErrors('lines.0.discount_amount');
    $business->update(['pos_allow_price_override' => true]);
    $this->post(route('pos.checkout'), $payload)->assertSessionHasNoErrors();
    expect(Sale::query()->sole()->total)->toBe('79.00');
})->with(['line', 'order']);

test('credit terms set due dates and enforce aggregate debt with idempotent checkout', function () {
    [$user, $business, $location, $variant] = shopOperationsContext();
    $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Nimal', 'phone' => '+94771234567', 'credit_limit' => '150.00', 'credit_days' => 14]);
    $payload = shopOperationsSale($location, $variant);
    $payload['customer_id'] = $customer->id;
    $payload['payments'] = [];
    $payload['is_credit'] = true;
    $this->actingAs($user)->post(route('pos.checkout'), $payload)->assertSessionHasNoErrors();
    $sale = Sale::query()->sole();
    expect($sale->due_date->toDateString())->toBe(now($business->timezone)->addDays(14)->toDateString());
    $this->post(route('pos.checkout'), $payload)->assertSessionHasNoErrors();
    expect(Sale::query()->count())->toBe(1);
    $payload['operation_key'] = (string) Str::uuid();
    $this->post(route('pos.checkout'), $payload)->assertSessionHasErrors('customer_id');
    expect(Sale::query()->count())->toBe(1);
});

test('overdue debt blocks additional credit but permits a fully paid linked purchase', function () {
    [$user, $business, $location, $variant] = shopOperationsContext();
    $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Nimal', 'phone' => '+94771234567']);
    Sale::factory()->for($business)->for($location)->for($user, 'cashier')->create(['customer_id' => $customer->id, 'is_credit' => true, 'total' => '50.00', 'paid_total' => '0.00', 'due_date' => now()->subDay()->toDateString()]);
    $payload = shopOperationsSale($location, $variant);
    $payload['customer_id'] = $customer->id;
    $payload['is_credit'] = true;
    $payload['payments'] = [];
    $this->actingAs($user)->post(route('pos.checkout'), $payload)->assertSessionHasErrors('customer_id');
    $payload['is_credit'] = false;
    $payload['payments'] = [['amount' => '100.00', 'method' => 'cash']];
    $this->post(route('pos.checkout'), $payload)->assertSessionHasNoErrors();
    expect(Sale::query()->latest('id')->first()->customer_id)->toBe($customer->id);
});

test('tax inclusive snapshots survive rate changes and partial returns without changing payment totals', function () {
    [$user, $business, $location, $variant] = shopOperationsContext();
    $business->update(['tax_rate' => '10.00']);
    $variant->update(['selling_price' => '110.00']);
    $payload = shopOperationsSale($location, $variant);
    $payload['lines'][0]['quantity'] = '3.000';
    $payload['payments'][0]['amount'] = '330.00';
    $this->actingAs($user)->post(route('pos.checkout'), $payload)->assertSessionHasNoErrors();
    $sale = Sale::query()->sole();
    expect($sale->total)->toBe('330.00')->and($sale->tax_total)->toBe('30.00')->and($sale->lines->sole()->tax_total)->toBe('30.00');
    $business->update(['tax_rate' => '20.00']);
    foreach (['1.000', '2.000'] as $quantity) {
        app(SaleService::class)->processReturn($user, $sale, ['operation_key' => (string) Str::uuid(), 'location_id' => $location->id, 'reason' => 'Unwanted', 'refund_amount' => $quantity === '1.000' ? '110.00' : '220.00', 'refund_method' => 'cash', 'lines' => [['sale_line_id' => $sale->lines->sole()->id, 'quantity' => $quantity, 'restock' => true]]]);
    }
    expect((string) $sale->lines->sole()->returnLines()->sum('tax_total'))->toBe('30.00');
    expect((string) app(SaleService::class)->outstanding($sale->fresh()))->toBe('0.00');
});

test('customer search is bounded and customer updates require independent permission', function () {
    [$user, $business] = shopOperationsContext();
    for ($i = 0; $i < 25; $i++) {
        Customer::query()->create(['business_id' => $business->id, 'name' => 'Customer '.$i, 'phone' => '+9477'.str_pad((string) $i, 7, '0', STR_PAD_LEFT)]);
    }
    $this->actingAs($user)->getJson('/customers/search')->assertOk()->assertJsonCount(20);
    $this->getJson('/customers/search?search=077')->assertOk()->assertJsonCount(20);
    $this->getJson('/customers/search?search=078')->assertOk()->assertJsonCount(0);
    $customer = Customer::query()->first();
    $data = ['name' => 'Updated', 'phone' => '077 123 4567', 'email' => '', 'credit_limit' => '200.00', 'credit_days' => 7];
    $this->put('/customers/'.$customer->id, $data)->assertForbidden();
    $user->givePermissionTo(Permission::findOrCreate('customers.update', 'web'));
    $this->put('/customers/'.$customer->id, $data)->assertSessionHasNoErrors();
    expect($customer->fresh()->phone)->toBe('+94771234567');
});

test('customer statements and aging exclude unassigned locations', function () {
    [$user, $business, $location] = shopOperationsContext();
    $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Nimal', 'phone' => '+94771234567']);
    Sale::factory()->for($business)->for($location)->for($user, 'cashier')->create(['customer_id' => $customer->id, 'is_credit' => true, 'total' => '100.00', 'paid_total' => '20.00', 'due_date' => now($business->timezone)->subDays(10)->toDateString()]);
    $other = Location::factory()->for($business)->create();
    Sale::factory()->for($business)->for($other)->for($user, 'cashier')->create(['customer_id' => $customer->id, 'is_credit' => true, 'total' => '900.00', 'paid_total' => '0.00']);
    $this->actingAs($user)->get('/customers/'.$customer->id)->assertInertia(fn (Assert $page) => $page->component('customers/Show')->where('outstanding', '80.00')->where('aging.1_30', '80.00')->has('sales.data', 1));
});

test('reorder alerts use location stock and unauthorized edits are rejected', function () {
    [$user, $business, $location, $variant] = shopOperationsContext();
    $this->actingAs($user)->put('/inventory/reorder', ['location_id' => $location->id, 'product_variant_id' => $variant->id, 'quantity' => '20.000'])->assertSessionHasNoErrors();
    $this->get('/inventory/reorder?alerts=1&location_id='.$location->id)->assertInertia(fn (Assert $page) => $page->has('rows.data', 1)->where('rows.data.0.threshold', '20.000'));
    $other = Location::factory()->for($business)->create();
    $this->put('/inventory/reorder', ['location_id' => $other->id, 'product_variant_id' => $variant->id, 'quantity' => '5.000'])->assertNotFound();
    $this->put('/inventory/reorder', ['location_id' => $location->id, 'product_variant_id' => $variant->id, 'quantity' => null])->assertSessionHasNoErrors();
    $this->get('/inventory/reorder?alerts=1&location_id='.$location->id)->assertInertia(fn (Assert $page) => $page->has('rows.data', 0));
});

test('shared carts are durable idempotent and can only be claimed once at an accessible location', function () {
    [$user, $business, $location, $variant] = shopOperationsContext();
    $id = (string) Str::uuid();
    $data = ['id' => $id, 'location_id' => $location->id, 'order_discount' => '0.00', 'customer_name' => 'Counter customer', 'lines' => [['variant' => ['id' => $variant->id], 'quantity' => '1.000', 'unit_price' => '100.00', 'discount_amount' => '0.00', 'device_identifier' => '']]];
    $this->actingAs($user)->postJson('/pos/held-carts', $data)->assertCreated();
    $this->postJson('/pos/held-carts', $data)->assertCreated();
    expect(HeldCart::query()->count())->toBe(1);
    $otherUser = User::factory()->create();
    $otherUser->givePermissionTo(Permission::findOrCreate('sales.pos', 'web'));
    $this->actingAs($otherUser)->postJson('/pos/held-carts/'.$id.'/claim')->assertNotFound();
    $location->users()->attach($otherUser);
    $this->getJson('/pos/held-carts?location_id='.$location->id)->assertJsonCount(1);
    $this->postJson('/pos/held-carts/'.$id.'/claim')->assertOk()->assertJsonPath('customer_name', 'Counter customer');
    $this->postJson('/pos/held-carts/'.$id.'/claim')->assertNotFound();
});

test('CSV report export respects permissions and neutralizes spreadsheet formulas', function () {
    [$user, $business, $location, $variant] = shopOperationsContext();
    $payload = shopOperationsSale($location, $variant);
    $payload['customer_name'] = '=HYPERLINK("evil")';
    $this->actingAs($user)->post(route('pos.checkout'), $payload)->assertSessionHasNoErrors();
    $csv = $this->get('/sales/reports?export=1')->assertOk()->assertDownload('sales-report.csv')->streamedContent();
    expect($csv)->toContain("'=HYPERLINK")->toContain(Sale::query()->sole()->sale_number);
    $user->revokePermissionTo('sales.reports.view');
    $this->get('/sales/reports?export=1')->assertForbidden();
    $response = CsvDownload::make('test.csv', [['=1+1', '+cmd', '-5.00', '@bad']]);
    ob_start();
    $response->sendContent();
    $content = ob_get_clean();
    expect($content)->toContain("'=1+1")->toContain("'+cmd")->toContain('-5.00')->toContain("'@bad");
});

test('legacy local phone records are reused and duplicates are rejected in customer management', function () {
    [$user, $business, $location, $variant] = shopOperationsContext();
    $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Existing customer', 'phone' => '0771234567']);
    $payload = shopOperationsSale($location, $variant);
    $payload['is_credit'] = true;
    $payload['payments'] = [];
    $payload['customer_name'] = 'Same customer';
    $payload['customer_phone'] = '+94 77 123 4567';
    $this->actingAs($user)->post(route('pos.checkout'), $payload)->assertSessionHasNoErrors();
    expect(Sale::query()->sole()->customer_id)->toBe($customer->id)->and(Customer::query()->count())->toBe(1);
    $this->post('/customers', ['name' => 'Duplicate', 'phone' => '0094771234567', 'credit_days' => 30])->assertSessionHasErrors('phone');
});

test('credit due dates cannot be in the past', function () {
    [$user, $business, $location, $variant] = shopOperationsContext();
    $payload = shopOperationsSale($location, $variant);
    $payload['is_credit'] = true;
    $payload['customer_name'] = 'Nimal';
    $payload['customer_phone'] = '0771234567';
    $payload['due_date'] = now($business->timezone)->subDay()->toDateString();
    $this->actingAs($user)->post(route('pos.checkout'), $payload)->assertSessionHasErrors('due_date');
    expect(Sale::query()->count())->toBe(0);
});

test('tax and invoice value remain unchanged by a warranty replacement at a new tax rate', function () {
    [$user, $business, $location, $variant] = shopOperationsContext();
    $user->givePermissionTo(Permission::findOrCreate('sale-replacements.create', 'web'));
    $business->update(['tax_rate' => '10.00']);
    $variant->update(['selling_price' => '110.00']);
    $payload = shopOperationsSale($location, $variant);
    $payload['payments'][0]['amount'] = '110.00';
    $this->actingAs($user)->post(route('pos.checkout'), $payload)->assertSessionHasNoErrors();
    $sale = Sale::query()->sole();
    $line = $sale->lines->sole();
    $business->update(['tax_rate' => '20.00']);
    $return = app(SaleService::class)->processReturn($user, $sale, ['operation_key' => (string) Str::uuid(), 'location_id' => $location->id, 'is_exchange' => true, 'reason' => 'Faulty item', 'refund_amount' => '0.00', 'lines' => [['sale_line_id' => $line->id, 'quantity' => '1.000', 'restock' => false]], 'replacements' => [['sale_line_id' => $line->id]]]);
    $replacement = $sale->fresh()->lines()->where('sale_return_id', $return->id)->sole();
    expect($replacement->tax_total)->toBe('10.00')->and($replacement->total)->toBe('110.00')
        ->and((string) app(SaleService::class)->outstanding($sale->fresh()))->toBe('0.00');
});

test('damaged stock CSV excludes costs without cost permission and includes all matching rows', function () {
    [$user, $business, $location, $variant] = shopOperationsContext();
    $user->givePermissionTo(Permission::findOrCreate('damaged-reports.view', 'web'));
    $operation = InventoryOperation::query()->where('business_id', $business->id)->firstOrFail();
    $lastSku = '';
    for ($i = 0; $i < 26; $i++) {
        $item = $i === 0 ? $variant : ProductVariant::factory()->for($business)->for($variant->product)->create(['sku' => 'EXPORT-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT)]);
        DamagedStockEntry::query()->create(['business_id' => $business->id, 'location_id' => $location->id, 'product_variant_id' => $item->id, 'inventory_operation_id' => $operation->id, 'quantity' => '1.000', 'remaining_quantity' => '1.000', 'unit_cost' => '123.45', 'reason' => 'Faulty', 'recorded_by' => $user->id]);
        $lastSku = $item->sku;
    }
    $csv = $this->actingAs($user)->get('/reports/damaged-stock?export=1')->assertOk()->assertDownload('damaged-stock.csv')->streamedContent();
    expect($csv)->toContain($variant->sku)->toContain($lastSku)->not->toContain('Damaged value')->not->toContain('123.45');
    $user->givePermissionTo(Permission::findOrCreate('sales.costs.view', 'web'));
    $csv = $this->get('/reports/damaged-stock?export=1')->assertOk()->streamedContent();
    expect($csv)->toContain('Damaged value')->toContain('123.45');
});
