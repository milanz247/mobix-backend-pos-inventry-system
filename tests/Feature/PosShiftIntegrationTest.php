<?php

use App\Models\Business;
use App\Models\CashierShift;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\User;
use App\Services\CashierShiftService;
use App\Services\InventoryService;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

/** @return array{user: User, business: Business, location: Location} */
function posShiftContext(): array
{
    $business = Business::factory()->create();
    $location = Location::factory()->for($business)->create();
    $user = User::factory()->create();
    $location->users()->attach($user);
    foreach (['sales.pos', 'sales.create', 'sales.view', 'sales.credit', 'inventory.opening', 'sale-returns.create', 'sale-refunds.create', 'shifts.view', 'shifts.open', 'shifts.movements', 'shifts.close'] as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return compact('user', 'business', 'location');
}

/** @return array<string, mixed> */
function posShiftSale(array $context, array $payments): array
{
    $category = Category::factory()->for($context['business'])->create();
    $product = Product::factory()->for($context['business'])->for($category)->create();
    $variant = ProductVariant::factory()->for($context['business'])->for($product)->create(['selling_price' => '100.00', 'cost_price' => '20.00']);
    app(InventoryService::class)->receive($context['user'], [
        'operation_key' => (string) Str::uuid(), 'location_id' => $context['location']->id,
        'product_variant_id' => $variant->id, 'mode' => 'quantity', 'quantity' => '5.000', 'reason' => 'Opening test stock',
    ], true);

    return [
        'operation_key' => (string) Str::uuid(), 'location_id' => $context['location']->id,
        'source' => 'in_store_pos', 'order_discount' => '0.00',
        'lines' => [['product_variant_id' => $variant->id, 'quantity' => '1.000', 'discount_amount' => '0.00']], 'payments' => $payments,
    ];
}

function posShiftOpenPayload(Location $location, string $amount = '1000.00'): array
{
    return ['operation_key' => (string) Str::uuid(), 'location_id' => $location->id, 'opening_cash' => $amount, 'opening_note' => 'POS opening float'];
}

function assertPosDrawer(object $test, Location $location, string $expected): void
{
    $test->get(route('pos.index', ['location_id' => $location->id]), [
        'X-Inertia-Partial-Component' => 'sales/Pos', 'X-Inertia-Partial-Data' => 'drawer',
    ])->assertInertia(fn (Assert $page) => $page->component('sales/Pos')->where('drawer.location_id', $location->id)->where('drawer.summary.expected_cash', $expected)->missing('variants'));
}

test('POS resolves an existing own shift and uses it as the default authorized location', function () {
    $context = posShiftContext();
    $other = Location::factory()->for($context['business'])->create();
    $other->users()->attach($context['user']);
    $shift = app(CashierShiftService::class)->open($context['user'], posShiftOpenPayload($other, '250.25'));
    $this->actingAs($context['user'])->get(route('pos.index'))->assertInertia(fn (Assert $page) => $page
        ->component('sales/Pos')->where('selectedLocation.id', $other->id)
        ->where('drawer.shift.id', $shift->id)->where('drawer.shift.opening_cash', '250.25')->where('drawer.summary.expected_cash', '250.25'));
    $this->get(route('shifts.index'))->assertInertia(fn (Assert $page) => $page->where('activeShift.id', $shift->id));
});

test('POS without an active shift exposes the inline opening context and blocks checkout', function () {
    $context = posShiftContext();
    $this->actingAs($context['user'])->get(route('pos.index'))->assertInertia(fn (Assert $page) => $page
        ->component('sales/Pos')->where('drawer.shift', null)->where('drawer.permissions.open', true)->where('drawer.location_id', $context['location']->id));
    $payload = posShiftSale($context, [['amount' => '100.00', 'method' => 'cash']]);
    $this->post(route('pos.checkout'), $payload)->assertSessionHasErrors(['shift' => 'Open a cashier shift at the selected location before checkout.']);
    expect(Sale::query()->count())->toBe(0);
});

test('inline opening saves entered cash including zero and redirects back to POS idempotently', function (string $amount) {
    $context = posShiftContext();
    $payload = posShiftOpenPayload($context['location'], $amount);
    $this->actingAs($context['user'])->post(route('pos.shifts.store'), $payload)->assertSessionHasNoErrors()
        ->assertRedirect(route('pos.index', ['location_id' => $context['location']->id]));
    $this->post(route('pos.shifts.store'), $payload)->assertSessionHasNoErrors()->assertRedirect(route('pos.index', ['location_id' => $context['location']->id]));
    expect(CashierShift::query()->count())->toBe(1);
    assertPosDrawer($this, $context['location'], $amount);
})->with(['empty drawer' => '0.00', 'edited opening amount' => '1250.75']);

test('inline opening rejects invalid amounts and keeps the submitted input after validation failure', function (string $amount) {
    $context = posShiftContext();
    $this->actingAs($context['user'])->from(route('pos.index', ['location_id' => $context['location']->id]))
        ->post(route('pos.shifts.store'), posShiftOpenPayload($context['location'], $amount))
        ->assertSessionHasErrors('opening_cash')->assertSessionHasInput('opening_cash', $amount);
    expect(CashierShift::query()->count())->toBe(0);
})->with(['negative' => '-10.00', 'too precise' => '1.001', 'invalid text' => 'abc']);

test('switching location refreshes the drawer context and cannot authorize a sale using another location shift', function () {
    $context = posShiftContext();
    $shift = app(CashierShiftService::class)->open($context['user'], posShiftOpenPayload($context['location']));
    $other = Location::factory()->for($context['business'])->create();
    $other->users()->attach($context['user']);
    $this->actingAs($context['user'])->get(route('pos.index', ['location_id' => $other->id]), [
        'X-Inertia-Partial-Component' => 'sales/Pos', 'X-Inertia-Partial-Data' => 'drawer,selectedLocation',
    ])->assertInertia(fn (Assert $page) => $page->where('selectedLocation.id', $other->id)->where('drawer.location_id', $other->id)
        ->where('drawer.shift', null)->where('drawer.other_location.id', $context['location']->id));
    $payload = posShiftSale([...$context, 'location' => $other], [['amount' => '100.00', 'method' => 'cash']]);
    $this->post(route('pos.checkout'), [...$payload, 'cashier_shift_id' => $shift->id])->assertSessionHasErrors('shift');
    $this->post(route('pos.shifts.store'), posShiftOpenPayload($other))->assertSessionHasErrors('location_id');
    expect(Sale::query()->count())->toBe(0)->and(CashierShift::query()->count())->toBe(1);
});

test('cash movements and expenses update POS reconciliation once', function (string $type, string $expected) {
    $context = posShiftContext();
    $shift = app(CashierShiftService::class)->open($context['user'], posShiftOpenPayload($context['location']));
    $payload = [
        'operation_key' => (string) Str::uuid(), 'location_id' => $context['location']->id,
        'type' => $type, 'amount' => '75.25', 'reason' => 'Documented drawer movement', 'supporting_reference' => 'Receipt 001',
    ];
    $this->actingAs($context['user'])->post(route('pos.shifts.movements', $shift), $payload)->assertSessionHasNoErrors()
        ->assertRedirect(route('pos.index', ['location_id' => $context['location']->id]));
    $this->post(route('pos.shifts.movements', $shift), $payload)->assertSessionHasNoErrors();
    expect($shift->movements()->count())->toBe(1);
    assertPosDrawer($this, $context['location'], $expected);
})->with(['cash in' => ['cash_in', '1075.25'], 'cash out' => ['cash_out', '924.75'], 'expense' => ['expense', '924.75'], 'cash drop' => ['drop', '924.75']]);

test('POS cash reconciliation follows net cash split tender and cash versus noncash refunds', function (string $refundMethod, string $expected) {
    $context = posShiftContext();
    $shift = app(CashierShiftService::class)->open($context['user'], posShiftOpenPayload($context['location'], '0.00'));
    $payload = posShiftSale($context, [
        ['amount' => '40.00', 'method' => 'cash', 'cash_tendered' => '100.00'],
        ['amount' => '60.00', 'method' => 'card'],
    ]);
    $this->actingAs($context['user'])->post(route('pos.checkout'), $payload)->assertSessionHasNoErrors();
    assertPosDrawer($this, $context['location'], '40.00');
    $sale = Sale::query()->sole();
    expect($sale->cashier_shift_id)->toBe($shift->id);
    $this->post(route('sales.returns.store', $sale), [
        'operation_key' => (string) Str::uuid(), 'location_id' => $context['location']->id, 'reason' => 'Returned order',
        'refund_amount' => '100.00', 'refund_method' => $refundMethod,
        'lines' => [['sale_line_id' => $sale->lines()->sole()->id, 'quantity' => '1', 'restock' => true]],
    ])->assertSessionHasNoErrors();
    assertPosDrawer($this, $context['location'], $expected);
})->with(['cash refund' => ['cash', '-60.00'], 'card refund' => ['card', '40.00'], 'bank refund' => ['bank_transfer', '40.00']]);

test('inline closing persists counted cash and prevents additional sales and movements until reopening', function (string $actual, string $variance, string $status) {
    $context = posShiftContext();
    $shift = app(CashierShiftService::class)->open($context['user'], posShiftOpenPayload($context['location']));
    $this->actingAs($context['user'])->post(route('pos.shifts.close', $shift), [
        'location_id' => $context['location']->id, 'actual_cash' => $actual, 'variance_reason' => 'Physical count checked',
    ])->assertSessionHasNoErrors()->assertRedirect(route('pos.index', ['location_id' => $context['location']->id]));
    $this->post(route('pos.shifts.close', $shift), ['location_id' => $context['location']->id, 'actual_cash' => '0'])->assertSessionHasNoErrors();
    expect($shift->fresh()->status)->toBe($status)->and($shift->fresh()->variance)->toBe($variance)
        ->and($shift->fresh()->actual_cash)->toBe($actual)->and($shift->events()->where('type', 'closed')->count())->toBe(1);
    $this->get(route('pos.index'))->assertInertia(fn (Assert $page) => $page->where('drawer.shift', null));
    $payload = posShiftSale($context, [['amount' => '100.00', 'method' => 'cash']]);
    $this->post(route('pos.checkout'), $payload)->assertSessionHasErrors('shift');
    $this->post(route('pos.shifts.movements', $shift), [
        'location_id' => $context['location']->id, 'operation_key' => (string) Str::uuid(), 'type' => 'cash_in', 'amount' => '5', 'reason' => 'Too late top up',
    ])->assertSessionHasErrors('shift');
    $this->post(route('pos.shifts.store'), posShiftOpenPayload($context['location'], '0.00'))->assertSessionHasNoErrors();
    $this->post(route('pos.checkout'), $payload)->assertSessionHasNoErrors();
    expect(Sale::query()->sole()->cashier_shift_id)->not->toBe($shift->id);
})->with(['balanced' => ['1000.00', '0.00', 'closed'], 'shortage' => ['975.00', '-25.00', 'pending_approval'], 'surplus' => ['1010.00', '10.00', 'pending_approval']]);

test('inline closing requires a variance explanation and uses backend totals', function () {
    $context = posShiftContext();
    $shift = app(CashierShiftService::class)->open($context['user'], posShiftOpenPayload($context['location']));
    $this->actingAs($context['user'])->post(route('pos.shifts.close', $shift), [
        'location_id' => $context['location']->id, 'actual_cash' => '0.00', 'expected_cash' => '0.00',
    ])->assertSessionHasErrors('variance_reason');
    expect($shift->fresh()->status)->toBe('open');
});

test('POS endpoints enforce location ownership permissions and selected location context', function () {
    $context = posShiftContext();
    $shift = app(CashierShiftService::class)->open($context['user'], posShiftOpenPayload($context['location']));
    $other = Location::factory()->for($context['business'])->create();
    $this->actingAs($context['user'])->get(route('pos.index', ['location_id' => $other->id]))->assertNotFound();
    $this->post(route('pos.shifts.store'), posShiftOpenPayload($other))->assertNotFound();
    $this->post(route('pos.shifts.close', $shift), ['location_id' => $other->id, 'actual_cash' => '1000'])->assertNotFound();
    $this->post(route('pos.shifts.movements', $shift), [
        'location_id' => $other->id, 'operation_key' => (string) Str::uuid(), 'type' => 'expense', 'amount' => '10', 'reason' => 'Wrong branch expense',
    ])->assertNotFound();
    $context['user']->revokePermissionTo('shifts.movements');
    $this->post(route('pos.shifts.movements', $shift), [
        'location_id' => $context['location']->id, 'operation_key' => (string) Str::uuid(), 'type' => 'expense', 'amount' => '10', 'reason' => 'No permission expense',
    ])->assertForbidden();
    expect($shift->movements()->count())->toBe(0);
});

test('another cashier shift cannot be used for POS management', function () {
    $context = posShiftContext();
    $other = User::factory()->create();
    $context['location']->users()->attach($other);
    $shift = CashierShift::factory()->for($context['business'])->for($context['location'])->for($other, 'cashier')->create();
    $this->actingAs($context['user'])->get(route('pos.index'))->assertInertia(fn (Assert $page) => $page->where('drawer.shift', null));
    $this->post(route('pos.shifts.close', $shift), ['location_id' => $context['location']->id, 'actual_cash' => '1000'])->assertNotFound();
});

test('inline opening endpoints require authentication', function () {
    $this->post(route('pos.shifts.store'), [])->assertRedirect(route('login'));
});
