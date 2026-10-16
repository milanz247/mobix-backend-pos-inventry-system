<?php

use App\Models\Business;
use App\Models\Location;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\SalePayment;
use App\Models\SaleRefund;
use App\Models\SaleReturn;
use App\Models\SaleReturnLine;
use App\Models\User;
use App\PermissionName;
use App\SalePaymentStatus;
use App\SaleStatus;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authorized users can visit the dashboard', function () {
    $user = User::factory()->create();
    Business::factory()->for($user, 'owner')->create();
    $user->givePermissionTo(Permission::findOrCreate(PermissionName::DashboardView->value, 'web'));
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('overview.sales_total', '0.00')
        ->has('trend', 30)
        ->where('currency', 'LKR')
        ->etc());
});

test('dashboard reports sales returns payments balances and reliable profit', function () {
    Carbon::setTestNow('2026-10-16 04:00:00');
    $user = User::factory()->create();
    $business = Business::factory()->for($user, 'owner')->create([
        'currency' => 'LKR',
        'timezone' => 'Asia/Colombo',
    ]);
    $location = Location::factory()->create(['business_id' => $business->id]);
    $user->givePermissionTo(Permission::findOrCreate(PermissionName::DashboardView->value, 'web'));

    $creditSale = Sale::factory()->create([
        'business_id' => $business->id,
        'location_id' => $location->id,
        'cashier_id' => $user->id,
        'total' => '200.00',
        'paid_total' => '150.00',
        'status' => SaleStatus::PartiallyReturned,
        'payment_status' => SalePaymentStatus::PartiallyPaid,
        'posted_at' => now(),
    ]);
    $creditLine = SaleLine::factory()->create([
        'business_id' => $business->id,
        'sale_id' => $creditSale->id,
        'product_name_snapshot' => 'Popular Phone',
        'variant_name_snapshot' => '128 GB',
        'sku_snapshot' => 'PHONE-128',
        'quantity' => '2.000',
        'unit_price' => '100.00',
        'cost_snapshot' => '60.00',
        'subtotal' => '200.00',
        'total' => '200.00',
    ]);
    SalePayment::factory()->create([
        'business_id' => $business->id,
        'sale_id' => $creditSale->id,
        'received_by' => $user->id,
        'method' => 'cash',
        'amount' => '150.00',
        'paid_at' => now(),
    ]);
    $return = SaleReturn::factory()->create([
        'business_id' => $business->id,
        'sale_id' => $creditSale->id,
        'location_id' => $location->id,
        'processed_by' => $user->id,
        'total' => '50.00',
        'refund_total' => '20.00',
        'processed_at' => now(),
    ]);
    SaleReturnLine::factory()->create([
        'business_id' => $business->id,
        'sale_return_id' => $return->id,
        'sale_line_id' => $creditLine->id,
        'quantity' => '0.500',
        'unit_price' => '100.00',
        'unit_cost_snapshot' => '60.00',
        'total' => '50.00',
    ]);
    SaleRefund::factory()->create([
        'business_id' => $business->id,
        'sale_id' => $creditSale->id,
        'sale_return_id' => $return->id,
        'processed_by' => $user->id,
        'method' => 'cash',
        'amount' => '20.00',
        'refunded_at' => now(),
    ]);

    $paidSale = Sale::factory()->create([
        'business_id' => $business->id,
        'location_id' => $location->id,
        'cashier_id' => $user->id,
        'total' => '100.00',
        'paid_total' => '100.00',
        'posted_at' => now(),
    ]);
    SaleLine::factory()->create([
        'business_id' => $business->id,
        'sale_id' => $paidSale->id,
        'product_name_snapshot' => 'Charging Cable',
        'variant_name_snapshot' => 'USB-C',
        'sku_snapshot' => 'CABLE-USBC',
        'quantity' => '1.000',
        'unit_price' => '100.00',
        'cost_snapshot' => '60.00',
        'subtotal' => '100.00',
        'total' => '100.00',
    ]);
    SalePayment::factory()->create([
        'business_id' => $business->id,
        'sale_id' => $paidSale->id,
        'received_by' => $user->id,
        'method' => 'card',
        'amount' => '100.00',
        'paid_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('overview.sales_total', '300.00')
            ->where('overview.completed_sales', 2)
            ->where('overview.cash_received', '150.00')
            ->where('overview.returns', '50.00')
            ->where('overview.refunds', '20.00')
            ->where('overview.outstanding', '20.00')
            ->where('performance.net_sales_30_days', '250.00')
            ->where('performance.gross_profit_30_days', '100.00')
            ->has('trend', 30)
            ->where('trend.29.date', '2026-10-16')
            ->where('trend.29.net_sales', '250.00')
            ->where('trend.29.gross_profit', '100.00')
            ->has('paymentMethods', 2)
            ->where('paymentMethods.0.method', 'cash')
            ->where('paymentMethods.0.amount', '150.00')
            ->has('topProducts', 2)
            ->where('topProducts.0.name', 'Popular Phone')
            ->where('topProducts.0.quantity', '2.000')
            ->etc());
});
