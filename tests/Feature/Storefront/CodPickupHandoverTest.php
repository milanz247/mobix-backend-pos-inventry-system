<?php

use App\Models\CashierShift;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WebOrderFulfillment;
use App\Services\CashierShiftService;
use App\Services\SaleService;
use App\Support\SalePageData;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

require_once __DIR__.'/../../Support/CodPickupFixture.php';

test('COD pickup posts accepted sale cash stock and fulfillment exactly once', function () {
    $f = codPickupFixture($this);
    readyCodPickup($f);
    $key = (string) Str::uuid();
    $f['variant']->update(['selling_price' => '1500.00']);
    $result = pickupHandover($f, $key, '1100.00');
    $sale = $result['sale']->fresh(['lines', 'payments']);
    expect($sale->source->value)->toBe('ecommerce')
        ->and($sale->web_order_id)->toBe($f['order']->id)
        ->and((string) $sale->lines->first()->unit_price)->toBe('1000.00')
        ->and((string) $sale->total)->toBe('1000.00')
        ->and((string) $sale->tax_total)->toBe('90.91')
        ->and($sale->inventory_operation_id)->not->toBeNull()
        ->and($sale->payments->count())->toBe(1)
        ->and((string) $sale->payments->first()->cash_tendered)->toBe('1100.00')
        ->and((string) $sale->payments->first()->change_given)->toBe('100.00')
        ->and($sale->payments->first()->received_by)->toBe($f['actor']->id)
        ->and((string) InventoryStock::query()->where('product_variant_id', $f['variant']->id)->value('quantity_on_hand'))->toBe('4.000')
        ->and($f['order']->fresh()->status)->toBe('fulfilled')
        ->and($f['order']->fresh()->payment_status)->toBe('paid_collected');
    $shift = $sale->payments->first()->cashier_shift_id;
    expect($shift)->toBe($sale->cashier_shift_id)
        ->and(DB::table('web_order_reservations')->where('web_order_id', $f['order']->id)->value('status'))->toBe('consumed')
        ->and(DB::table('web_order_fulfillments')->where('web_order_id', $f['order']->id)->count())->toBe(1)
        ->and(app(CashierShiftService::class)->summary(CashierShift::query()->findOrFail($shift))['expected_cash'])->toBe('1000.00');
    expect(pickupHandover($f, $key, '1100.00')['replayed'])->toBeTrue();
    expect(Sale::query()->count())->toBe(1)->and(SalePayment::query()->count())->toBe(1)
        ->and(StockMovement::query()->count())->toBe(1);
});

test('serialized pickup sells only the exact accepted device and hides IMEI on invoice data', function () {
    $f = codPickupFixture($this, serialized: true);
    readyCodPickup($f);
    $result = pickupHandover($f);
    $sale = $result['sale']->fresh(['lines']);
    expect($sale->lines->first()->serialized_device_id)->toBe($f['device']->id)
        ->and($sale->lines->first()->imei_snapshot)->toBe($f['device']->imei)
        ->and($f['device']->fresh()->status->value)->toBe('sold')
        ->and(app(SalePageData::class)->sale($sale)['lines'][0]['imei'])->toBeNull();
    $tracking = $this->getJson('/api/storefront/v1/orders/'.$f['order']->reference, [
        'X-Storefront-Order-Credential' => $f['submitted']->headers->get('X-Storefront-Order-Credential'),
    ])->assertOk()->assertJsonPath('data.status_message', 'Your order was collected at the shop.');
    expect($tracking->getContent())->not->toContain($f['device']->imei);
});

test('combo pickup consumes accepted component even if the current recipe changes', function () {
    $f = codPickupFixture($this, combo: true);
    readyCodPickup($f);
    $f['variant']->bundleComponents()->update(['quantity' => '3.000']);
    $acceptedName = $f['component']->product->name.' — '.$f['component']->name;
    $f['component']->update(['name' => 'Renamed after acceptance', 'sku' => 'NEW-SKU']);
    $result = pickupHandover($f);
    $sale = $result['sale']->fresh(['lines.components']);
    expect($sale->lines->first()->components->first()->product_variant_id)->toBe($f['component']->id)
        ->and($sale->lines->first()->components->first()->name_snapshot)->toBe($acceptedName)
        ->and((string) $sale->lines->first()->components->first()->quantity)->toBe('2.000')
        ->and((string) InventoryStock::query()->where('product_variant_id', $f['component']->id)->value('quantity_on_hand'))->toBe('3.000');
});

test('shared fractional component quantities preserve all three decimal places', function () {
    $f = codPickupFixture($this, combo: true, shared: true, componentQuantity: '0.125');
    readyCodPickup($f);
    pickupHandover($f);
    expect((string) InventoryStock::query()->where('product_variant_id', $f['component']->id)
        ->value('quantity_on_hand'))->toBe('3.875');
});

test('serialized combo preserves its exact device IMEI and accepted device event price', function () {
    $f = codPickupFixture($this, combo: true, componentQuantity: '1.000', componentSerialized: true);
    readyCodPickup($f);
    $f['device']->update(['unit_selling_price' => '1200.00']);
    $sale = pickupHandover($f)['sale']->fresh(['lines.components']);
    $component = $sale->lines->sole()->components->sole();
    expect($component->serialized_device_id)->toBe($f['device']->id)
        ->and($component->imei_snapshot)->toBe($f['device']->imei)
        ->and($f['device']->fresh()->status->value)->toBe('sold')
        ->and((string) DB::table('inventory_operation_lines')->where('inventory_operation_id', $sale->inventory_operation_id)
            ->value('unit_selling_price'))->toBe('900.00');
});

test('changed device identity or status never selects a replacement', function (string $field, string $value) {
    $f = codPickupFixture($this, serialized: true);
    readyCodPickup($f);
    $f['device']->update([$field => $value]);
    expect(fn () => pickupHandover($f))->toThrow(ValidationException::class);
    expect(Sale::query()->count())->toBe(0)
        ->and(SalePayment::query()->count())->toBe(0)
        ->and(WebOrderFulfillment::query()->count())->toBe(0)
        ->and($f['order']->reservations()->value('status'))->toBe('active');
})->with([['imei', '999999999999999'], ['status', 'sold'], ['condition', 'new']]);

test('an open shift at another shop cannot receive pickup cash', function () {
    $f = codPickupFixture($this);
    readyCodPickup($f, openShift: false);
    $other = Location::factory()->for($f['business'])->create(['type' => 'shop']);
    app(CashierShiftService::class)->open($f['actor'], ['location_id' => $other->id,
        'operation_key' => (string) Str::uuid(), 'opening_cash' => '0.00']);
    expect(fn () => pickupHandover($f))->toThrow(ValidationException::class);
    expect(Sale::query()->count())->toBe(0);
});

test('missing accepted inventory snapshots block legacy handover without posting', function () {
    $f = codPickupFixture($this);
    readyCodPickup($f);
    DB::table('web_order_items')->where('web_order_id', $f['order']->id)->update(['private_inventory_snapshot' => null]);
    expect(fn () => pickupHandover($f))->toThrow(ValidationException::class);
    expect(Sale::query()->count())->toBe(0);
});

test('browser supplied payment and location values cannot bypass bank delivery restriction', function () {
    $f = codPickupFixture($this);
    $f['order']->update(['payment_method' => 'bank_transfer', 'fulfillment_mode' => 'home_delivery']);
    $this->actingAs($f['actor'])->postJson('/storefront/orders/'.$f['order']->reference.'/handover/pickup', [
        'operation_key' => (string) Str::uuid(), 'cash_tendered' => '1000.00',
        'payment_method' => 'cod', 'fulfillment_mode' => 'shop_pickup', 'payment_status' => 'cod_due',
        'cashier_id' => $f['actor']->id, 'location_id' => $f['location']->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('handover');
    expect(Sale::query()->count())->toBe(0)->and(SalePayment::query()->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0)
        ->and($f['order']->reservations()->value('status'))->toBe('active');
});

test('COD database origin constraints enforce cash shift and matching sale order', function () {
    $f = codPickupFixture($this);
    readyCodPickup($f);
    $sale = pickupHandover($f)['sale'];
    $id = $sale->payments()->sole()->id;
    expect(fn () => DB::table('sale_payments')->where('id', $id)->update(['method' => 'card']))
        ->toThrow(QueryException::class);
    expect(fn () => DB::table('sale_payments')->where('id', $id)->update(['cashier_shift_id' => null]))
        ->toThrow(QueryException::class);
    expect(fn () => DB::table('sale_payments')->where('id', $id)->update(['web_cod_order_id' => $f['order']->id + 100]))
        ->toThrow(QueryException::class);
});

test('standalone and combo lines share the same reserved component pool without double deduction', function () {
    $f = codPickupFixture($this, combo: true, shared: true);
    readyCodPickup($f);
    $sale = pickupHandover($f)['sale']->fresh(['lines.components']);
    expect($sale->lines->count())->toBe(2)
        ->and(DB::table('web_order_reservations')->where('component_variant_id', $f['component']->id)->count())->toBe(2)
        ->and((string) InventoryStock::query()->where('product_variant_id', $f['component']->id)->value('quantity_on_hand'))->toBe('2.000')
        ->and(StockMovement::query()->where('product_variant_id', $f['component']->id)->count())->toBe(2);
});

test('pickup refuses missing shift insufficient cash nonzero fee and incomplete reservation without posting', function () {
    $f = codPickupFixture($this);
    readyCodPickup($f, openShift: false);
    expect(fn () => pickupHandover($f))->toThrow(ValidationException::class);
    app(CashierShiftService::class)->open($f['actor'], ['location_id' => $f['location']->id,
        'operation_key' => (string) Str::uuid(), 'opening_cash' => '0.00']);
    expect(fn () => pickupHandover($f, tender: '999.00'))->toThrow(ValidationException::class);
    $f['order']->update(['delivery_fee' => '10.00']);
    expect(fn () => pickupHandover($f))->toThrow(ValidationException::class);
    $f['order']->update(['delivery_fee' => '0.00']);
    $f['order']->reservations()->update(['status' => 'released']);
    expect(fn () => pickupHandover($f))->toThrow(ValidationException::class);
    expect(Sale::query()->count())->toBe(0)->and(SalePayment::query()->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0);
});

test('different retry key cannot collect COD twice and generic POS payment and return are blocked', function () {
    $f = codPickupFixture($this);
    readyCodPickup($f);
    $key = (string) Str::uuid();
    $sale = pickupHandover($f, $key)['sale'];
    expect(fn () => pickupHandover($f, $key, '1200.00'))->toThrow(ConflictHttpException::class);
    expect(fn () => pickupHandover($f))->toThrow(ConflictHttpException::class);
    expect(fn () => app(SaleService::class)->recordPayment($f['actor'], $sale, [
        'operation_key' => (string) Str::uuid(), 'amount' => '1.00', 'method' => 'cash',
    ]))->toThrow(ValidationException::class);
    expect(fn () => app(SaleService::class)->processReturn($f['actor'], $sale, [
        'operation_key' => (string) Str::uuid(), 'location_id' => $f['location']->id,
    ]))->toThrow(ValidationException::class);
    expect(Sale::query()->count())->toBe(1)->and(SalePayment::query()->count())->toBe(1);
});

test('failed payment or fulfillment insert rolls the sale stock and reservations back', function (string $failureModel) {
    $f = codPickupFixture($this);
    readyCodPickup($f);
    $failureModel::creating(fn () => throw new RuntimeException('forced rollback'));
    expect(fn () => pickupHandover($f))->toThrow(RuntimeException::class);
    expect(Sale::query()->count())->toBe(0)->and(SalePayment::query()->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0)
        ->and(WebOrderFulfillment::query()->count())->toBe(0)
        ->and($f['order']->fresh()->status)->toBe('ready')
        ->and(DB::table('web_order_reservations')->where('web_order_id', $f['order']->id)->value('status'))->toBe('active');
})->with([Sale::class, SalePayment::class, StockMovement::class, WebOrderFulfillment::class]);

test('failure on a later shared component movement rolls earlier deductions back', function () {
    $f = codPickupFixture($this, combo: true, shared: true);
    readyCodPickup($f);
    $seen = 0;
    StockMovement::creating(function () use (&$seen): void {
        if (++$seen === 2) {
            throw new RuntimeException('second movement failed');
        }
    });
    expect(fn () => pickupHandover($f))->toThrow(RuntimeException::class);
    expect(Sale::query()->count())->toBe(0)
        ->and(SalePayment::query()->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0)
        ->and((string) InventoryStock::query()->where('product_variant_id', $f['component']->id)->value('quantity_on_hand'))->toBe('5.000')
        ->and($f['order']->fresh()->status)->toBe('ready');
});

test('bank delivery and unready COD cannot enter the pickup posting path', function () {
    $f = codPickupFixture($this);
    app(CashierShiftService::class)->open($f['actor'], ['location_id' => $f['location']->id,
        'operation_key' => (string) Str::uuid(), 'opening_cash' => '0.00']);
    expect(fn () => pickupHandover($f))->toThrow(ValidationException::class);
    $f['order']->update(['fulfillment_mode' => 'home_delivery', 'payment_method' => 'bank_transfer']);
    expect(fn () => pickupHandover($f))->toThrow(ValidationException::class);
    expect(Sale::query()->count())->toBe(0)->and(StockMovement::query()->count())->toBe(0);
});

test('handover requires the approved phone audit and an unexpired exact reservation', function () {
    $f = codPickupFixture($this);
    readyCodPickup($f);
    DB::table('web_order_call_attempts')->where('web_order_id', $f['order']->id)
        ->update(['outcome' => 'no_answer']);
    expect(fn () => pickupHandover($f))->toThrow(ValidationException::class);
    DB::table('web_order_call_attempts')->where('web_order_id', $f['order']->id)
        ->update(['outcome' => 'answered_confirmed']);
    $f['order']->reservations()->update(['expires_at' => now()->subMinute()]);
    expect(fn () => pickupHandover($f))->toThrow(ValidationException::class);
    expect(Sale::query()->count())->toBe(0);
});

test('pickup code mismatch and a moved reserved device block posting', function () {
    $f = codPickupFixture($this, serialized: true);
    readyCodPickup($f);
    $f['order']->update(['pickup_location_code_snapshot' => 'different-shop']);
    expect(fn () => pickupHandover($f))->toThrow(ValidationException::class);
    $f['order']->update(['pickup_location_code_snapshot' => $f['location']->code]);
    $other = Location::factory()->for($f['business'])->create(['type' => 'shop']);
    $f['device']->update(['location_id' => $other->id]);
    expect(fn () => pickupHandover($f))->toThrow(ValidationException::class);
    expect(Sale::query()->count())->toBe(0)
        ->and($f['device']->fresh()->status->value)->toBe('in_stock');
});

test('unassigned staff and staff without handover permission cannot collect pickup cash', function () {
    $f = codPickupFixture($this);
    readyCodPickup($f);
    $staff = User::factory()->create();
    $key = (string) Str::uuid();
    $url = '/storefront/orders/'.$f['order']->reference.'/handover/pickup';
    $this->post($url, ['operation_key' => $key, 'cash_tendered' => '1000.00'])->assertRedirect('/login');
    $this->actingAs($staff)->post($url, ['operation_key' => $key, 'cash_tendered' => '1000.00'])->assertForbidden();
    $staff->givePermissionTo(Permission::findOrCreate('storefront.view-orders', 'web'));
    $staff->givePermissionTo(Permission::findOrCreate('storefront.handover-orders', 'web'));
    $staff->givePermissionTo(Permission::findOrCreate('shifts.view', 'web'));
    $this->post($url, ['operation_key' => $key, 'cash_tendered' => '1000.00'])->assertNotFound();
    expect(Sale::query()->count())->toBe(0);
});

test('generic POS add-item path rejects an Ecommerce sale', function () {
    $f = codPickupFixture($this);
    readyCodPickup($f);
    $sale = pickupHandover($f)['sale'];
    expect(fn () => app(SaleService::class)->checkout($f['actor'], [
        'operation_key' => (string) Str::uuid(), 'location_id' => $f['location']->id,
        'source' => 'in_store_pos', 'credit_sale_id' => $sale->id,
        'lines' => [['product_variant_id' => $f['variant']->id, 'quantity' => '1.000']],
        'payments' => [],
    ]))->toThrow(ValidationException::class);
    expect(Sale::query()->count())->toBe(1);
});
