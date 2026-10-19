<?php

use App\Models\Business;
use App\Models\CashierShift;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\User;
use App\Services\CashierShiftService;
use App\Services\InventoryService;
use App\Services\SaleService;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

/** @return array{user: User, business: Business, location: Location} */
function shiftContext(): array
{
    $business = Business::factory()->create(['name' => 'Nalaka Test Shop']);
    $location = Location::factory()->for($business)->create();
    $user = User::factory()->create();
    $location->users()->attach($user);
    shiftPermissions($user, ['shifts.view', 'shifts.open', 'shifts.movements', 'shifts.close', 'sales.pos', 'sales.view', 'sales.create', 'sales.credit', 'sale-payments.create', 'sale-returns.create', 'sale-refunds.create', 'inventory.opening']);

    return compact('user', 'business', 'location');
}

function shiftPermissions(User $user, array $permissions): void
{
    foreach ($permissions as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
}

function openTestShift(array $context, string $cash = '1000.00'): CashierShift
{
    return app(CashierShiftService::class)->open($context['user'], [
        'operation_key' => (string) Str::uuid(), 'location_id' => $context['location']->id, 'opening_cash' => $cash,
    ]);
}

/** @return array<string, mixed> */
function shiftSalePayload(array $context, array $payments): array
{
    $product = Product::factory()->for($context['business'])->create();
    $variant = ProductVariant::factory()->for($context['business'])->for($product)->create(['selling_price' => '100.00', 'cost_price' => '20.00']);
    app(InventoryService::class)->receive($context['user'], [
        'operation_key' => (string) Str::uuid(), 'location_id' => $context['location']->id,
        'product_variant_id' => $variant->id, 'mode' => 'quantity', 'quantity' => '5.000', 'reason' => 'Opening test inventory',
    ], true);

    return [
        'operation_key' => (string) Str::uuid(), 'location_id' => $context['location']->id,
        'source' => 'in_store_pos', 'order_discount' => '0.00',
        'lines' => [['product_variant_id' => $variant->id, 'quantity' => '1.000', 'discount_amount' => '0.00']],
        'payments' => $payments,
    ];
}

test('shift routes require authentication and permission', function () {
    $this->get(route('shifts.index'))->assertRedirect(route('login'));
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('shifts.store'), [])->assertForbidden();
});

test('cashier opens own shift and retries safely without trusting supplied cashier or time', function () {
    $context = shiftContext();
    $payload = ['operation_key' => (string) Str::uuid(), 'location_id' => $context['location']->id, 'opening_cash' => '1250.50', 'cashier_id' => $context['business']->owner_id, 'opened_at' => '2000-01-01'];
    $this->actingAs($context['user'])->post(route('shifts.store'), $payload)->assertSessionHasNoErrors();
    $shift = CashierShift::query()->sole();
    $this->post(route('shifts.store'), $payload)->assertRedirect(route('shifts.show', $shift));
    expect($shift->cashier_id)->toBe($context['user']->id)
        ->and($shift->opening_cash)->toBe('1250.50')
        ->and($shift->events()->count())->toBe(1)
        ->and(CashierShift::query()->count())->toBe(1);
    $this->get(route('shifts.show', $shift))->assertOk()->assertInertia(fn (Assert $page) => $page->component('shifts/Show')->where('summary.expected_cash', '1250.50'));
});

test('cashier cannot open shifts at unassigned or inactive locations', function (bool $inactive) {
    $context = shiftContext();
    $location = Location::factory()->for($context['business'])->create(['status' => $inactive ? 'inactive' : 'active']);
    if ($inactive) {
        $location->users()->attach($context['user']);
    }
    $this->actingAs($context['user'])->post(route('shifts.store'), [
        'operation_key' => (string) Str::uuid(), 'location_id' => $location->id, 'opening_cash' => '0',
    ])->assertNotFound();
    expect(CashierShift::query()->count())->toBe(0);
})->with(['unassigned' => false, 'inactive' => true]);

test('negative opening cash and excessive precision are rejected', function (string $cash) {
    $context = shiftContext();
    $this->actingAs($context['user'])->post(route('shifts.store'), ['operation_key' => (string) Str::uuid(), 'location_id' => $context['location']->id, 'opening_cash' => $cash])
        ->assertSessionHasErrors('opening_cash');
    expect(CashierShift::query()->count())->toBe(0);
})->with(['negative' => '-1.00', 'precision' => '0.001', 'overflow' => '1000000000000']);

test('one active shift per cashier is enforced while different cashiers can share a location', function () {
    $context = shiftContext();
    openTestShift($context);
    $this->actingAs($context['user'])->post(route('shifts.store'), ['operation_key' => (string) Str::uuid(), 'location_id' => $context['location']->id, 'opening_cash' => '0'])
        ->assertSessionHasErrors('location_id');
    $other = User::factory()->create();
    $context['location']->users()->attach($other);
    shiftPermissions($other, ['shifts.view', 'shifts.open']);
    $this->actingAs($other)->post(route('shifts.store'), ['operation_key' => (string) Str::uuid(), 'location_id' => $context['location']->id, 'opening_cash' => '0'])
        ->assertSessionHasNoErrors();
    expect(CashierShift::query()->where('status', 'open')->count())->toBe(2);
});

test('persisted POS payments contribute only net cash including split tender and change', function (array $payments, string $expected) {
    $context = shiftContext();
    $shift = openTestShift($context);
    $payload = shiftSalePayload($context, $payments);
    $this->actingAs($context['user'])->post(route('pos.checkout'), $payload)->assertSessionHasNoErrors();
    $this->post(route('pos.checkout'), $payload)->assertSessionHasNoErrors();
    $sale = Sale::query()->sole();
    expect($sale->cashier_shift_id)->toBe($shift->id)
        ->and(app(CashierShiftService::class)->summary($shift)['expected_cash'])->toBe($expected)
        ->and(app(CashierShiftService::class)->summary($shift)['sales_count'])->toBe(1);
    expect($sale->payments()->where('cashier_shift_id', $shift->id)->count())->toBe(count($payments));
})->with([
    'cash with change' => [[['amount' => '100.00', 'cash_tendered' => '500.00', 'method' => 'cash']], '1100.00'],
    'card' => [[['amount' => '100.00', 'method' => 'card']], '1000.00'],
    'bank transfer' => [[['amount' => '100.00', 'method' => 'bank_transfer']], '1000.00'],
    'digital via existing other method' => [[['amount' => '100.00', 'method' => 'other', 'reference' => 'QR receipt']], '1000.00'],
    'split' => [[['amount' => '40.00', 'cash_tendered' => '100.00', 'method' => 'cash'], ['amount' => '60.00', 'method' => 'card']], '1040.00'],
]);

test('cash and noncash refunds use the processing shift even for sales from an earlier shift', function (string $method, string $expected) {
    $context = shiftContext();
    $payload = shiftSalePayload($context, [['amount' => '100.00', 'method' => 'card']]);
    $this->actingAs($context['user']);
    $sale = app(SaleService::class)->checkout($context['user'], $payload);
    $shift = openTestShift($context);
    $return = [
        'operation_key' => (string) Str::uuid(), 'location_id' => $context['location']->id,
        'reason' => 'Customer return', 'refund_amount' => '100.00', 'refund_method' => $method,
        'lines' => [['sale_line_id' => $sale->lines()->sole()->id, 'quantity' => '1.000', 'restock' => true]],
    ];
    $this->post(route('sales.returns.store', $sale), $return)->assertSessionHasNoErrors();
    $this->post(route('sales.returns.store', $sale), $return)->assertSessionHasNoErrors();
    expect($sale->refunds()->sole()->cashier_shift_id)->toBe($shift->id)
        ->and(app(CashierShiftService::class)->summary($shift)['expected_cash'])->toBe($expected)
        ->and($sale->cashier_shift_id)->toBeNull();
})->with(['cash' => ['cash', '900.00'], 'card' => ['card', '1000.00'], 'bank' => ['bank_transfer', '1000.00']]);

test('later credit collections attach once to the current shift without importing earlier payments', function () {
    $context = shiftContext();
    $payload = shiftSalePayload($context, [['amount' => '20.00', 'method' => 'cash']]);
    $this->actingAs($context['user']);
    $sale = app(SaleService::class)->checkout($context['user'], $payload);
    $shift = openTestShift($context);
    $payment = ['operation_key' => (string) Str::uuid(), 'amount' => '80.00', 'method' => 'cash', 'cash_tendered' => '100.00'];
    $this->post(route('sales.payments.store', $sale), $payment)->assertSessionHasNoErrors();
    $this->post(route('sales.payments.store', $sale), $payment)->assertSessionHasNoErrors();
    expect(app(CashierShiftService::class)->summary($shift)['expected_cash'])->toBe('1080.00')
        ->and($sale->payments()->whereNull('cashier_shift_id')->count())->toBe(1)
        ->and($sale->payments()->where('cashier_shift_id', $shift->id)->count())->toBe(1);
});

test('cash movements follow the drawer formula and retries cannot double count', function () {
    $context = shiftContext();
    $shift = openTestShift($context);
    foreach (['cash_in' => '200.00', 'cash_out' => '50.00', 'expense' => '30.00', 'drop' => '100.00'] as $type => $amount) {
        $payload = ['operation_key' => (string) Str::uuid(), 'type' => $type, 'amount' => $amount, 'reason' => 'Documented cash movement'];
        $this->actingAs($context['user'])->post(route('shifts.store-movement', $shift), $payload)->assertSessionHasNoErrors();
        $this->post(route('shifts.store-movement', $shift), $payload)->assertSessionHasNoErrors();
    }
    expect($shift->movements()->count())->toBe(4)
        ->and(app(CashierShiftService::class)->summary($shift)['expected_cash'])->toBe('1020.00')
        ->and($shift->events()->where('type', 'movement_recorded')->count())->toBe(4);
});

test('cash movements reject invalid amounts and require meaningful reasons', function (string $amount, string $reason, string $field) {
    $context = shiftContext();
    $shift = openTestShift($context);
    $this->actingAs($context['user'])->post(route('shifts.store-movement', $shift), [
        'operation_key' => (string) Str::uuid(), 'type' => 'cash_out', 'amount' => $amount, 'reason' => $reason,
    ])->assertSessionHasErrors($field);
    expect($shift->movements()->count())->toBe(0);
})->with([['0.00', 'Cash out', 'amount'], ['-1.00', 'Cash out', 'amount'], ['5.00', '', 'reason'], ['5.00', 'x', 'reason']]);

test('cashier cannot change another cashier shift even with view all', function () {
    $context = shiftContext();
    $shift = openTestShift($context);
    $manager = User::factory()->create();
    $context['location']->users()->attach($manager);
    shiftPermissions($manager, ['shifts.view', 'shifts.view-all', 'shifts.movements', 'shifts.close']);
    $this->actingAs($manager)->post(route('shifts.store-movement', $shift), [
        'operation_key' => (string) Str::uuid(), 'type' => 'cash_in', 'amount' => '10', 'reason' => 'Unauthorized adjustment',
    ])->assertForbidden();
    $this->post(route('shifts.close', $shift), ['actual_cash' => '1000'])->assertForbidden();
});

test('closing saves balanced shortage and surplus figures and safely ignores duplicate closes', function (string $actual, string $variance, string $status) {
    $context = shiftContext();
    $shift = openTestShift($context);
    $payload = ['actual_cash' => $actual, 'variance_reason' => 'Count checked twice', 'closing_note' => 'End of day'];
    $this->actingAs($context['user'])->post(route('shifts.close', $shift), $payload)->assertRedirect(route('shifts.report', $shift))->assertSessionHasNoErrors();
    $this->post(route('shifts.close', $shift), ['actual_cash' => '0.00'])->assertRedirect(route('shifts.report', $shift));
    $shift->refresh();
    expect($shift->status)->toBe($status)->and($shift->variance)->toBe($variance)
        ->and($shift->actual_cash)->toBe($actual)->and($shift->active_cashier_id)->toBeNull()
        ->and($shift->events()->where('type', 'closed')->count())->toBe(1);
    $this->get(route('shifts.report', $shift))->assertOk()->assertInertia(fn (Assert $page) => $page->component('shifts/Report')->where('summary.expected_cash', '1000.00')->where('shift.actual_cash', $actual));
    $this->post(route('shifts.store-movement', $shift), ['operation_key' => (string) Str::uuid(), 'type' => 'cash_in', 'amount' => '10', 'reason' => 'After close attempt'])->assertSessionHasErrors('shift');
})->with(['balanced' => ['1000.00', '0.00', 'closed'], 'shortage' => ['980.00', '-20.00', 'pending_approval'], 'surplus' => ['1025.00', '25.00', 'pending_approval']]);

test('variance requires a reason and the expected figure supplied by the browser is ignored', function () {
    $context = shiftContext();
    $shift = openTestShift($context);
    $this->actingAs($context['user'])->post(route('shifts.close', $shift), ['actual_cash' => '0.00', 'expected_cash' => '0.00'])
        ->assertSessionHasErrors(['variance_reason' => 'Explain the cash shortage or surplus (at least 5 characters).']);
    expect($shift->fresh()->status)->toBe('open');
});

test('only another authorized manager can approve a variance and corrections preserve history', function () {
    $context = shiftContext();
    $shift = openTestShift($context);
    $manager = User::factory()->create(['name' => 'Review Manager']);
    $context['location']->users()->attach($manager);
    shiftPermissions($manager, ['shifts.view', 'shifts.view-all', 'shifts.approve', 'shifts.correct']);
    $movement = app(CashierShiftService::class)->movement($context['user'], $shift, ['operation_key' => (string) Str::uuid(), 'type' => 'expense', 'amount' => '50', 'reason' => 'Mistaken expense entry']);
    $this->actingAs($context['user'])->post(route('shifts.reverse', [$shift, $movement]), ['reason' => 'Correct mistaken expense'])->assertForbidden();
    $this->actingAs($manager)->post(route('shifts.reverse', [$shift, $movement]), ['reason' => 'Correct mistaken expense'])->assertSessionHasNoErrors();
    $this->post(route('shifts.reverse', [$shift, $movement]), ['reason' => 'Correct mistaken expense'])->assertSessionHasNoErrors();
    expect($shift->movements()->count())->toBe(2)->and(app(CashierShiftService::class)->summary($shift)['expected_cash'])->toBe('1000.00');
    $this->actingAs($context['user'])->post(route('shifts.close', $shift), ['actual_cash' => '990.00', 'variance_reason' => 'Missing ten rupees'])->assertSessionHasNoErrors();
    shiftPermissions($context['user'], ['shifts.approve', 'shifts.view-all']);
    $this->post(route('shifts.approve', $shift))->assertForbidden();
    $this->actingAs($manager)->post(route('shifts.approve', $shift))->assertSessionHasNoErrors();
    $this->post(route('shifts.approve', $shift))->assertSessionHasNoErrors();
    expect($shift->fresh()->status)->toBe('approved')->and($shift->fresh()->approver_name)->toBe('Review Manager')
        ->and($shift->events()->where('type', 'approved')->count())->toBe(1);
    $this->post(route('shifts.reverse', [$shift, $movement]), ['reason' => 'Too late adjustment'])->assertSessionHasErrors('shift');
});

test('location reports and shift history are scoped even for managers', function () {
    $context = shiftContext();
    $ownShift = openTestShift($context);
    $otherLocation = Location::factory()->for($context['business'])->create();
    $otherCashier = User::factory()->create();
    $otherShift = CashierShift::factory()->for($context['business'])->for($otherLocation)->for($otherCashier, 'cashier')->create();
    shiftPermissions($context['user'], ['shifts.view-all']);
    $this->actingAs($context['user'])->get(route('shifts.show', $otherShift))->assertNotFound();
    $this->get(route('shifts.report', $otherShift))->assertNotFound();
    $this->get(route('shifts.index'))->assertInertia(fn (Assert $page) => $page->has('shifts.data', 1)->where('shifts.data.0.id', $ownShift->id));
});

test('closing report stays historically consistent and later transactions do not change it', function () {
    $context = shiftContext();
    $shift = openTestShift($context);
    $payload = shiftSalePayload($context, [['amount' => '70.00', 'method' => 'cash'], ['amount' => '30.00', 'method' => 'card']]);
    $this->actingAs($context['user'])->post(route('pos.checkout'), $payload)->assertSessionHasNoErrors();
    $this->post(route('shifts.close', $shift), ['actual_cash' => '1070.00'])->assertSessionHasNoErrors();
    $context['business']->update(['name' => 'Renamed Shop']);
    $context['location']->update(['name' => 'Renamed Branch']);
    $context['user']->update(['name' => 'Renamed Cashier']);
    $newShift = openTestShift($context);
    $this->post(route('pos.checkout'), shiftSalePayload($context, [['amount' => '100.00', 'method' => 'cash']]))->assertSessionHasNoErrors();
    $this->get(route('shifts.report', $shift))->assertInertia(fn (Assert $page) => $page
        ->where('summary.expected_cash', '1070.00')->where('summary.payments.card', '30.00')
        ->where('summary.identity.business', 'Nalaka Test Shop')->where('summary.sales_count', 1));
    expect(app(CashierShiftService::class)->summary($newShift)['expected_cash'])->toBe('1100.00');
});

test('overview movements and closing screens render for the active cashier', function () {
    $context = shiftContext();
    $shift = openTestShift($context);
    $this->actingAs($context['user'])->get(route('shifts.index'))->assertOk();
    $this->get(route('shifts.create'))->assertOk();
    $this->get(route('shifts.movements', $shift))->assertOk();
    $this->get(route('shifts.closing', $shift))->assertOk();
    $this->get(route('shifts.report', $shift))->assertNotFound();
});

test('reusing opening or movement keys for changed data fails without modifying the original', function () {
    $context = shiftContext();
    $shift = openTestShift($context);
    $this->actingAs($context['user'])->post(route('shifts.store'), [
        'operation_key' => $shift->operation_key, 'location_id' => $context['location']->id, 'opening_cash' => '2',
    ])->assertSessionHasErrors('operation_key');
    $key = (string) Str::uuid();
    $payload = ['operation_key' => $key, 'type' => 'cash_in', 'amount' => '10', 'reason' => 'Documented top up'];
    $this->post(route('shifts.store-movement', $shift), $payload)->assertSessionHasNoErrors();
    $this->post(route('shifts.store-movement', $shift), [...$payload, 'amount' => '20'])->assertSessionHasErrors('operation_key');
    $this->post(route('shifts.close', $shift), ['actual_cash' => '1010'])->assertSessionHasNoErrors();
    $newShift = openTestShift($context);
    $this->post(route('shifts.store-movement', $newShift), $payload)->assertSessionHasErrors('operation_key');
    expect($shift->movements()->count())->toBe(1)->and($newShift->movements()->count())->toBe(0);
});

test('full drawer formula reconciles real POS sales refunds and all cash movement types', function () {
    $context = shiftContext();
    $shift = openTestShift($context, '500.00');
    $this->actingAs($context['user'])->post(route('pos.checkout'), shiftSalePayload($context, [
        ['amount' => '70.00', 'method' => 'cash', 'cash_tendered' => '100.00'],
        ['amount' => '30.00', 'method' => 'card'],
    ]))->assertSessionHasNoErrors();
    $sale = Sale::query()->sole();
    $this->post(route('sales.returns.store', $sale), [
        'operation_key' => (string) Str::uuid(), 'location_id' => $context['location']->id,
        'reason' => 'Customer cash refund', 'refund_amount' => '100.00', 'refund_method' => 'cash',
        'lines' => [['sale_line_id' => $sale->lines()->sole()->id, 'quantity' => '1', 'restock' => true]],
    ])->assertSessionHasNoErrors();
    foreach (['cash_in' => '200', 'cash_out' => '20', 'expense' => '10', 'drop' => '50'] as $type => $amount) {
        $this->post(route('shifts.store-movement', $shift), [
            'operation_key' => (string) Str::uuid(), 'type' => $type, 'amount' => $amount, 'reason' => 'Daily drawer movement',
        ])->assertSessionHasNoErrors();
    }
    $this->post(route('shifts.close', $shift), ['actual_cash' => '590.00'])->assertSessionHasNoErrors();
    $this->get(route('shifts.report', $shift))->assertInertia(fn (Assert $page) => $page
        ->where('summary.expected_cash', '590.00')->where('summary.cash_sales', '70.00')
        ->where('summary.cash_refunds', '100.00')->where('summary.payments.card', '30.00')
        ->where('summary.movements.cash_in', '200.00')->where('summary.movements.expense', '10.00')
        ->where('shift.variance', '0.00')->where('shift.status', 'closed'));
});

test('history filters respect local calendar boundaries status variance and pagination', function () {
    $context = shiftContext();
    CashierShift::factory()->count(22)->for($context['business'])->for($context['location'])->for($context['user'], 'cashier')
        ->create(['active_cashier_id' => null, 'opened_at' => '2026-10-19 23:45:00', 'closed_at' => '2026-10-19 23:55:00', 'status' => 'closed', 'variance' => '-1.00']);
    CashierShift::factory()->for($context['business'])->for($context['location'])->for($context['user'], 'cashier')
        ->create(['active_cashier_id' => null, 'opened_at' => '2026-10-18 23:45:00', 'closed_at' => '2026-10-18 23:55:00', 'status' => 'closed', 'variance' => '2.00']);
    $this->actingAs($context['user'])->get(route('shifts.index', [
        'date_from' => '2026-10-19', 'date_to' => '2026-10-19', 'status' => 'closed', 'variance' => 'shortage',
    ]))->assertInertia(fn (Assert $page) => $page->has('shifts.data', 20)->where('shifts.total', 22));
    $this->get(route('shifts.index', ['date_to' => '2026-10-18']))->assertSessionHasNoErrors()
        ->assertInertia(fn (Assert $page) => $page->has('shifts.data', 1)->where('shifts.total', 1));
});

test('closed totals and manual movement records reject direct model edits', function () {
    $context = shiftContext();
    $shift = openTestShift($context);
    $movement = app(CashierShiftService::class)->movement($context['user'], $shift, [
        'operation_key' => (string) Str::uuid(), 'type' => 'cash_in', 'amount' => '10', 'reason' => 'Opening cash topup',
    ]);
    app(CashierShiftService::class)->close($context['user'], $shift, ['actual_cash' => '1010']);
    expect(fn () => $shift->fresh()->update(['actual_cash' => '0']))->toThrow(LogicException::class)
        ->and(fn () => $movement->update(['amount' => '99']))->toThrow(LogicException::class);
    expect($shift->fresh()->actual_cash)->toBe('1010.00');
});
