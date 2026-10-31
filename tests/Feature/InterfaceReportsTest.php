<?php

use App\Actions\Catalog\DeleteProduct;
use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\CashierShift;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DamagedStockEntry;
use App\Models\InventoryOperation;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleAddition;
use App\Models\SaleLine;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\InventoryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

/** @return array{User,Business,Location,ProductVariant} */
function interfaceReportContext(): array
{
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner, 'owner')->create(['timezone' => 'Asia/Colombo']);
    $location = Location::factory()->for($business)->create(['name' => 'Main shop']);
    $category = Category::factory()->for($business)->create();
    $product = Product::factory()->for($business)->for($category)->create(['name' => 'Cable']);
    $variant = ProductVariant::factory()->for($business)->for($product)->create(['sku' => 'AUDIT-CABLE']);

    return [$owner, $business, $location, $variant];
}

test('customer table exposes actions and refuses deletion once invoice history exists', function () {
    [$owner, $business, $location] = interfaceReportContext();
    $unused = Customer::query()->create(['business_id' => $business->id, 'name' => 'Unused', 'phone' => '+94771234567']);
    $used = Customer::query()->create(['business_id' => $business->id, 'name' => 'With history', 'phone' => '+94771234568']);
    Sale::factory()->for($business)->for($location)->for($owner, 'cashier')->create(['customer_id' => $used->id]);
    $this->actingAs($owner)->get('/customers')->assertInertia(fn (Assert $page) => $page->where('canUpdate', true)->where('canDelete', true)->where('customers.data.0.can_delete', true)->where('customers.data.1.can_delete', false));
    $this->delete('/customers/'.$used->id)->assertSessionHasErrors('customer');
    $this->delete('/customers/'.$unused->id)->assertRedirect('/customers');
    expect(Customer::query()->find($used->id))->not->toBeNull()->and(Customer::query()->find($unused->id))->toBeNull();
});

test('customer history includes purchased products and independently filters credit invoices', function () {
    [$owner, $business, $location, $variant] = interfaceReportContext();
    $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Nimal', 'phone' => '+94771234567']);
    foreach ([false, true] as $credit) {
        $sale = Sale::factory()->for($business)->for($location)->for($owner, 'cashier')->create(['customer_id' => $customer->id, 'is_credit' => $credit, 'total' => '100.00', 'paid_total' => $credit ? '0.00' : '100.00']);
        SaleLine::factory()->for($sale)->for($variant, 'variant')->create(['product_name_snapshot' => 'Cable', 'quantity' => '2.000']);
    }
    $this->actingAs($owner)->get('/customers/'.$customer->id)->assertInertia(fn (Assert $page) => $page->where('view', 'purchases')->has('purchases.data', 2)->where('purchases.data.0.product', 'Cable')->where('purchases.data.0.quantity', '2.000'));
    $this->get('/customers/'.$customer->id.'?view=credit')->assertInertia(fn (Assert $page) => $page->where('view', 'credit')->has('sales.data', 1)->where('sales.data.0.is_credit', true)->where('outstanding', '100.00'));
});

test('daily sales uses business midnight and attributes older invoice collections to their payment date', function () {
    [$owner, $business, $location] = interfaceReportContext();
    foreach (['2026-10-30 23:59:59', '2026-10-31 00:00:00', '2026-10-31 23:59:59', '2026-11-01 00:00:00'] as $posted) {
        Sale::factory()->for($business)->for($location)->for($owner, 'cashier')->create(['posted_at' => $posted, 'total' => '100.00', 'paid_total' => '0.00']);
    }
    $old = Sale::query()->oldest('posted_at')->firstOrFail();
    SalePayment::factory()->for($old)->create(['received_by' => $owner->id, 'amount' => '40.00', 'paid_at' => '2026-10-31 02:00:00']);
    $this->actingAs($owner)->get('/reports/daily-sales?date=2026-10-31')->assertInertia(fn (Assert $page) => $page->component('reports/DailySales')->where('summary.invoices', 2)->where('summary.net_sales', '200.00')->where('summary.collections', '40.00')->has('rows.data', 2));
    $this->get('/reports/daily-sales?date=2026-10-31&section=payments')->assertInertia(fn (Assert $page) => $page->has('rows.data', 1)->where('rows.data.0.sale_id', $old->id)->where('rows.data.0.amount', '40.00'));
});

test('all sales report has no implicit date restriction and remains branch scoped', function () {
    [$owner, $business, $location] = interfaceReportContext();
    $other = Location::factory()->for($business)->create();
    foreach ([$location, $other] as $branch) {
        Sale::factory()->for($business)->for($branch)->for($owner, 'cashier')->create(['posted_at' => '2025-01-01 12:00:00', 'total' => '100.00', 'paid_total' => '20.00']);
    }
    $staff = User::factory()->create();
    $location->users()->attach($staff);
    $staff->givePermissionTo(Permission::findOrCreate('sales.reports.view', 'web'));
    $this->actingAs($staff)->get('/reports/all-sales')->assertInertia(fn (Assert $page) => $page->component('reports/AllSales')->where('summary.invoices', 1)->where('summary.outstanding', '80.00')->has('rows.data', 1));
});

test('cashier shift report includes sales and drawer totals without revealing other cashiers shifts', function () {
    [$owner, $business, $location] = interfaceReportContext();
    $staff = User::factory()->create();
    $location->users()->attach($staff);
    foreach (['shifts.view', 'shift-reports.view'] as $permission) {
        $staff->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $shift = CashierShift::factory()->for($business)->for($location)->for($staff, 'cashier')->create();
    CashierShift::factory()->for($business)->for($location)->for($owner, 'cashier')->create();
    $sale = Sale::factory()->for($business)->for($location)->for($staff, 'cashier')->create(['cashier_shift_id' => $shift->id, 'total' => '100.00', 'paid_total' => '100.00']);
    SalePayment::factory()->for($sale)->create(['cashier_shift_id' => $shift->id, 'received_by' => $staff->id, 'amount' => '100.00']);
    $this->actingAs($staff)->get('/reports/shift-sales')->assertInertia(fn (Assert $page) => $page->component('reports/ShiftSales')->where('summary.shifts', 1)->where('summary.net_sales', '100.00')->where('summary.collections', '100.00')->has('rows.data', 1));
});

test('product edits and deletion retain actor and immutable product history without credentials', function () {
    [$owner, $business, $location, $variant] = interfaceReportContext();
    $this->actingAs($owner)->withSession(['active_location_id' => $location->id, 'audit_context' => true]);
    $this->get('/customers'); // Start a real request/session for model recording.
    $product = $variant->product;
    $product->update(['name' => 'Updated Cable']);
    $log = ActivityLog::query()->where('product_id', $product->id)->where('action', 'updated')->where('subject_type', 'Product')->latest('id')->firstOrFail();
    expect($log->getAttribute('actor_id'))->toBe($owner->id)->and($log->getAttribute('changes')['before']['name'])->toBe('Cable')->and($log->getAttribute('changes')['after']['name'])->toBe('Updated Cable');
    $fingerprint = $log->getAttribute('session_fingerprint');
    expect($fingerprint)->toHaveLength(64)->not->toBe(session()->getId());
    app(ActivityLogService::class)->record('redaction_test', 'User', (string) $owner->id, $owner->name, ['nested' => ['password' => 'do-not-save', 'token' => 'do-not-save', 'name' => 'Visible']]);
    $raw = (string) DB::table('activity_logs')->where('action', 'redaction_test')->value('changes');
    expect($raw)->not->toContain('do-not-save')->toContain('Visible');
    $this->get('/reports/product-activity?product_id='.$product->id)->assertInertia(fn (Assert $page) => $page->component('reports/Activity')->where('productActivity', true)->has('rows.data'));
    $product->update(['status' => 'inactive']);
    app(DeleteProduct::class)->handle($business, $product);
    expect(Product::query()->find($product->id))->toBeNull()->and(ActivityLog::query()->where('product_id', $product->id)->where('action', 'catalogue_deleted')->exists())->toBeTrue();
    $this->expectException(LogicException::class);
    $log->delete();
});

test('activity records roll back with failed transactions and cost fields are hidden from restricted viewers', function () {
    [$owner, $business, $location, $variant] = interfaceReportContext();
    $this->actingAs($owner)->withSession(['active_location_id' => $location->id])->get('/customers');
    $before = ActivityLog::query()->count();
    try {
        DB::transaction(function () use ($variant): void {
            $variant->update(['cost_price' => '777.00']);
            throw new RuntimeException('Rollback');
        });
    } catch (RuntimeException) {
    }
    expect(ActivityLog::query()->count())->toBe($before);
    $variant->refresh()->update(['cost_price' => '888.00']);
    $staff = User::factory()->create();
    $location->users()->attach($staff);
    foreach (['product-activity.view', 'products.view'] as $permission) {
        $staff->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $response = $this->actingAs($staff)->get('/reports/product-activity?product_id='.$variant->product_id)->assertOk();
    $response->assertDontSee('888.00');
    app(ActivityLogService::class)->record('created', 'PurchaseLine', '123', 'Purchase line', ['after' => ['quantity' => '3.000', 'unit_cost' => '333.33', 'total' => '999.99']], $business->id, $location->id, $variant->product_id, 'Cable');
    $this->get('/reports/product-activity?product_id='.$variant->product_id)->assertDontSee('999.99')->assertDontSee('333.33');
    $this->get('/reports/activity')->assertForbidden();
});

test('profile includes security controls and roles have paginated table data plus a separate creation page', function () {
    [$owner] = interfaceReportContext();
    $this->actingAs($owner)->get('/settings/profile')->assertInertia(fn (Assert $page) => $page->has('securitySettings.passwordRules')->has('securitySettings.canManageTwoFactor'));
    $this->get('/roles')->assertInertia(fn (Assert $page) => $page->component('management/roles/Index')->has('pagination')->where('total', 0));
    $this->get('/roles/create')->assertInertia(fn (Assert $page) => $page->component('management/roles/Create')->has('permissionGroups'));
});

test('damaged stock has separate category and branch tables with complete scoped summaries', function () {
    [$owner, $business, $location, $variant] = interfaceReportContext();
    $other = Location::factory()->for($business)->create(['name' => 'Second shop']);
    $operation = InventoryOperation::factory()->create(['business_id' => $business->id, 'performed_by' => $owner->id]);
    foreach ([$location, $other] as $branch) {
        DamagedStockEntry::query()->create(['business_id' => $business->id, 'location_id' => $branch->id, 'product_variant_id' => $variant->id, 'inventory_operation_id' => $operation->id, 'quantity' => '2.000', 'remaining_quantity' => '2.000', 'unit_cost' => '50.00', 'reason' => 'Faulty', 'recorded_by' => $owner->id]);
    }
    $this->actingAs($owner)->get('/reports/damaged-stock?view=branch')->assertInertia(fn (Assert $page) => $page->where('view', 'branch')->where('summary.quantity', '4.000')->has('branchGroups.data', 2)->has('categoryGroups.data', 1)->has('rows.data', 2));
    $this->get('/reports/damaged-stock?view=category&location_id='.$location->id)->assertInertia(fn (Assert $page) => $page->where('view', 'category')->where('summary.quantity', '2.000')->has('branchGroups.data', 1));
});

test('daily sales retains distinct additions recorded for the same invoice in the same second', function () {
    [$owner, $business, $location] = interfaceReportContext();
    $sale = Sale::factory()->for($business)->for($location)->for($owner, 'cashier')->create(['posted_at' => '2026-10-31 04:00:00', 'total' => '400.00', 'paid_total' => '0.00']);
    for ($i = 0; $i < 3; $i++) {
        SaleAddition::query()->forceCreate(['business_id' => $business->id, 'sale_id' => $sale->id, 'created_by' => $owner->id, 'operation_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', (string) $i), 'total' => '100.00', 'created_at' => '2026-10-31 05:00:00']);
    }
    $this->actingAs($owner)->get('/reports/daily-sales?date=2026-10-31')->assertInertia(fn (Assert $page) => $page->where('summary.invoices', 1)->where('summary.net_sales', '400.00')->has('rows.data', 4)->where('rows.data', fn ($rows) => collect($rows)->pluck('id')->unique()->count() === 4));
});

test('a late evening POS checkout remains in the same shop day report', function () {
    [$owner, $business, $location, $variant] = interfaceReportContext();
    $this->travelTo(Carbon::parse('2026-10-31 16:30:00', 'UTC'));
    $variant->update(['selling_price' => '100.00', 'minimum_selling_price' => null]);
    CashierShift::factory()->for($business)->for($location)->for($owner, 'cashier')->create();
    app(InventoryService::class)->receive($owner, ['operation_key' => (string) Str::uuid(), 'location_id' => $location->id, 'product_variant_id' => $variant->id, 'mode' => 'quantity', 'quantity' => '2.000', 'reason' => 'Opening'], true);
    $this->actingAs($owner)->post('/pos/checkout', ['operation_key' => (string) Str::uuid(), 'location_id' => $location->id, 'source' => 'in_store_pos', 'lines' => [['product_variant_id' => $variant->id, 'quantity' => '1.000']], 'payments' => [['amount' => '100.00', 'method' => 'cash']]])->assertSessionHasNoErrors();
    expect(Sale::query()->sole()->posted_at->format('H:i:s'))->toBe('22:00:00');
    $this->get('/reports/daily-sales?date=2026-10-31')->assertInertia(fn (Assert $page) => $page->where('summary.invoices', 1)->where('summary.net_sales', '100.00')->where('summary.collections', '100.00')->has('rows.data', 1));
    $this->get('/reports/daily-sales?date=2026-11-01')->assertInertia(fn (Assert $page) => $page->where('summary.invoices', 0));
});
