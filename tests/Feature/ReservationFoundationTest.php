<?php

use App\LocationStatus;
use App\Models\Business;
use App\Models\Category;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SerializedDevice;
use App\Models\User;
use App\Models\WebOrder;
use App\Models\WebOrderReservation;
use App\Services\InventoryService;
use App\Services\LocationAdministrationService;
use App\Services\SaleService;
use App\Services\StockAvailabilityService;
use App\Services\WebReservationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** @return array{user: User, business: Business, location: Location, variant: ProductVariant} */
function reservationFixture(bool $serialized = false): array
{
    $user = User::factory()->create();
    $business = Business::factory()->for($user, 'owner')->create();
    $location = Location::factory()->for($business)->create();
    $category = Category::factory()->for($business)->create();
    $productFactory = Product::factory()->for($business)->for($category);
    if ($serialized) {
        $productFactory = $productFactory->serialized();
    }
    $product = $productFactory->create();
    $variantFactory = ProductVariant::factory()->for($business)->for($product);
    if ($serialized) {
        $variantFactory = $variantFactory->serialized();
    }
    $variant = $variantFactory->create(['selling_price' => '100.00']);

    return compact('user', 'business', 'location', 'variant');
}

function fixtureOrder(Business $business, Location $location, ProductVariant $variant, string $status = 'pending_confirmation'): WebOrder
{
    $order = WebOrder::query()->create([
        'business_id' => $business->id, 'location_id' => $location->id,
        'reference' => (string) Str::ulid(), 'submission_key' => (string) Str::uuid(),
        'payload_hash' => hash('sha256', Str::random()), 'status' => $status,
        'payment_method' => 'cod', 'payment_status' => 'cod_due', 'currency' => 'LKR',
        'subtotal' => '100.00', 'total' => '100.00',
    ]);
    $item = DB::table('web_order_items')->insertGetId([
        'business_id' => $business->id, 'web_order_id' => $order->id,
        'product_id' => $variant->product_id, 'product_variant_id' => $variant->id,
        'line_number' => 1, 'product_name_snapshot' => 'Test', 'product_slug_snapshot' => 'test',
        'variant_name_snapshot' => 'Option', 'quantity' => '1.000', 'unit_price' => '100.00',
        'line_total' => '100.00', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $order->setAttribute('fixture_item_id', $item)->syncOriginalAttribute('fixture_item_id');

    return $order;
}

function fixtureReservation(WebOrder $order, ProductVariant $component, string $quantity = '1.000', ?SerializedDevice $device = null): WebOrderReservation
{
    return app(WebReservationService::class)->reserveComponent(
        $order, $order->getAttribute('fixture_item_id'), $component->id,
        $quantity, (string) Str::ulid(), $device?->id,
    );
}

function fixtureAvailability(Business $business, Location $location, ProductVariant $variant): string
{
    $row = app(StockAvailabilityService::class)->query($business, $location)
        ->with('product')->where('product_variants.id', $variant->id)->firstOrFail();

    return app(StockAvailabilityService::class)->describe($row)['available_quantity'];
}

function fixturePosSale(User $user, Location $location, ProductVariant $variant, string $quantity = '1.000', ?string $imei = null): void
{
    $line = ['product_variant_id' => $variant->id, 'quantity' => $quantity, 'discount_amount' => '0.00'];
    if ($imei !== null) {
        $line['device_identifier'] = $imei;
    }
    app(SaleService::class)->checkout($user, [
        'operation_key' => (string) Str::uuid(), 'location_id' => $location->id,
        'source' => 'in_store_pos', 'order_discount' => '0.00',
        'lines' => [$line], 'payments' => [['amount' => '100.00', 'method' => 'cash']],
    ]);
}

test('quantity availability subtracts active reservations and a POS sale cannot cross the reserved floor', function () {
    ['user' => $user, 'business' => $business, 'location' => $location, 'variant' => $variant] = reservationFixture();
    InventoryStock::factory()->for($business)->for($location)->for($variant, 'variant')->create(['quantity_on_hand' => '3.000']);
    expect(fixtureAvailability($business, $location, $variant))->toBe('3.000');
    $order = fixtureOrder($business, $location, $variant);
    fixtureReservation($order, $variant, '2.000');
    expect(fixtureAvailability($business, $location, $variant))->toBe('1.000');

    fixturePosSale($user, $location, $variant);
    expect((string) InventoryStock::query()->sole()->quantity_on_hand)->toBe('2.000');
    expect(fn () => fixturePosSale($user, $location, $variant))->toThrow(ValidationException::class);
    expect((string) InventoryStock::query()->sole()->quantity_on_hand)->toBe('2.000');
});

test('unreserved quantity still sells through the existing POS path', function () {
    ['user' => $user, 'business' => $business, 'location' => $location, 'variant' => $variant] = reservationFixture();
    InventoryStock::factory()->for($business)->for($location)->for($variant, 'variant')->create(['quantity_on_hand' => '1.000']);
    fixturePosSale($user, $location, $variant);
    expect((string) InventoryStock::query()->sole()->quantity_on_hand)->toBe('0.000');
});

test('allocation rejects insufficient stock and repeats an identical line key without duplication', function () {
    ['business' => $business, 'location' => $location, 'variant' => $variant] = reservationFixture();
    InventoryStock::factory()->for($business)->for($location)->for($variant, 'variant')->create(['quantity_on_hand' => '1.000']);
    $order = fixtureOrder($business, $location, $variant);
    $service = app(WebReservationService::class);
    $itemId = $order->getAttribute('fixture_item_id');
    expect(fn () => $service->reserveComponent($order, $itemId, $variant->id, '2.000', 'first'))->toThrow(ValidationException::class);
    $first = $service->reserveComponent($order, $itemId, $variant->id, '1.000', 'first');
    expect($service->reserveComponent($order, $itemId, $variant->id, '1.000', 'first')->id)->toBe($first->id)
        ->and(WebOrderReservation::query()->count())->toBe(1);
    expect(fn () => $service->reserveComponent($order, $itemId, $variant->id, '1.000', 'second'))->toThrow(ValidationException::class);
});

test('quantity transfer cannot consume reserved stock', function () {
    ['user' => $user, 'business' => $business, 'location' => $location, 'variant' => $variant] = reservationFixture();
    InventoryStock::factory()->for($business)->for($location)->for($variant, 'variant')->create(['quantity_on_hand' => '2.000']);
    fixtureReservation(fixtureOrder($business, $location, $variant), $variant, '2.000');
    $destination = Location::factory()->for($business)->create();
    expect(fn () => app(InventoryService::class)->transfer($user, [
        'operation_key' => (string) Str::uuid(), 'source_location_id' => $location->id,
        'destination_location_id' => $destination->id, 'items' => [['product_variant_id' => $variant->id, 'quantity' => '1.000']],
    ]))->toThrow(ValidationException::class);
    expect((string) InventoryStock::query()->where('location_id', $location->id)->sole()->quantity_on_hand)->toBe('2.000');
});

test('reserved serialized device is excluded from automatic POS allocation and cannot be sold by IMEI', function () {
    ['user' => $user, 'business' => $business, 'location' => $location, 'variant' => $variant] = reservationFixture(true);
    $device = SerializedDevice::factory()->for($location)->for($variant, 'variant')->create(['business_id' => $business->id]);
    fixtureReservation(fixtureOrder($business, $location, $variant), $variant, '1.000', $device);
    expect(fixtureAvailability($business, $location, $variant))->toBe('0.000');
    expect(fn () => fixturePosSale($user, $location, $variant, '1.000', $device->imei))->toThrow(ValidationException::class);
    $business->update(['pos_require_imei_on_sale' => false]);
    expect(fn () => fixturePosSale($user, $location, $variant))->toThrow(ValidationException::class);
    expect($device->fresh()->status->value)->toBe('in_stock');
});

test('reserved serialized device cannot be transferred or changed to damaged', function () {
    ['user' => $user, 'business' => $business, 'location' => $location, 'variant' => $variant] = reservationFixture(true);
    $device = SerializedDevice::factory()->for($location)->for($variant, 'variant')->create(['business_id' => $business->id]);
    fixtureReservation(fixtureOrder($business, $location, $variant), $variant, '1.000', $device);
    $destination = Location::factory()->for($business)->create();
    expect(fn () => app(InventoryService::class)->transfer($user, [
        'operation_key' => (string) Str::uuid(), 'source_location_id' => $location->id,
        'destination_location_id' => $destination->id, 'items' => [], 'device_ids' => [$device->id],
    ]))->toThrow(ValidationException::class);
    expect(fn () => app(InventoryService::class)->changeDeviceStatus($user, $device, [
        'operation_key' => (string) Str::uuid(), 'status' => 'damaged', 'reason' => 'test',
    ]))->toThrow(ValidationException::class);
    expect($device->fresh()->location_id)->toBe($location->id);
});

test('a serialized device cannot have two active allocations', function () {
    ['business' => $business, 'location' => $location, 'variant' => $variant] = reservationFixture(true);
    $device = SerializedDevice::factory()->for($location)->for($variant, 'variant')->create(['business_id' => $business->id]);
    fixtureReservation(fixtureOrder($business, $location, $variant), $variant, '1.000', $device);
    $second = fixtureOrder($business, $location, $variant);
    expect(fn () => fixtureReservation($second, $variant, '1.000', $device))->toThrow(ValidationException::class);
    expect(WebOrderReservation::query()->where('active_device_id', $device->id)->count())->toBe(1);
});

test('a location with active allocations cannot be deactivated', function () {
    ['business' => $business, 'location' => $location, 'variant' => $variant] = reservationFixture();
    InventoryStock::factory()->for($business)->for($location)->for($variant, 'variant')->create(['quantity_on_hand' => '1.000']);
    fixtureReservation(fixtureOrder($business, $location, $variant), $variant);
    expect(fn () => app(LocationAdministrationService::class)->updateStatus($location, LocationStatus::Inactive))->toThrow(ValidationException::class);
    expect($location->fresh()->status)->toBe(LocationStatus::Active);
});

test('combo availability and POS consumption use component free stock', function () {
    ['user' => $user, 'business' => $business, 'location' => $location, 'variant' => $component] = reservationFixture();
    InventoryStock::factory()->for($business)->for($location)->for($component, 'variant')->create(['quantity_on_hand' => '3.000']);
    $comboProduct = Product::factory()->combo()->for($business)->for($component->product->category)->create();
    $combo = ProductVariant::factory()->for($business)->for($comboProduct, 'product')->create(['selling_price' => '100.00']);
    $combo->bundleComponents()->create(['business_id' => $business->id, 'component_variant_id' => $component->id, 'quantity' => '2.000']);
    fixtureReservation(fixtureOrder($business, $location, $component), $component, '2.000');
    expect(fixtureAvailability($business, $location, $combo))->toBe('0.000');
    expect(fn () => fixturePosSale($user, $location, $combo))->toThrow(ValidationException::class);
    expect((string) InventoryStock::query()->sole()->quantity_on_hand)->toBe('3.000');
});

test('a combo reservation protects its component from standalone POS sales', function () {
    ['user' => $user, 'business' => $business, 'location' => $location, 'variant' => $component] = reservationFixture();
    InventoryStock::factory()->for($business)->for($location)->for($component, 'variant')->create(['quantity_on_hand' => '2.000']);
    $comboProduct = Product::factory()->combo()->for($business)->for($component->product->category)->create();
    $combo = ProductVariant::factory()->for($business)->for($comboProduct, 'product')->create(['selling_price' => '100.00']);
    $combo->bundleComponents()->create(['business_id' => $business->id, 'component_variant_id' => $component->id, 'quantity' => '2.000']);
    fixtureReservation(fixtureOrder($business, $location, $combo), $component, '2.000');
    expect(fixtureAvailability($business, $location, $component))->toBe('0.000');
    expect(fn () => fixturePosSale($user, $location, $component))->toThrow(ValidationException::class);
});

test('a combo reservation protects its serialized component device', function () {
    ['user' => $user, 'business' => $business, 'location' => $location, 'variant' => $component] = reservationFixture(true);
    $device = SerializedDevice::factory()->for($business)->for($location)->for($component, 'variant')->create();
    $comboProduct = Product::factory()->combo()->for($business)->for($component->product->category)->create();
    $combo = ProductVariant::factory()->for($business)->for($comboProduct, 'product')->create(['selling_price' => '100.00']);
    $combo->bundleComponents()->create(['business_id' => $business->id, 'component_variant_id' => $component->id, 'quantity' => '1.000']);
    fixtureReservation(fixtureOrder($business, $location, $combo), $component, '1.000', $device);
    expect(fixtureAvailability($business, $location, $combo))->toBe('0.000');
    expect(fn () => fixturePosSale($user, $location, $combo))->toThrow(ValidationException::class);
    expect($device->fresh()->status->value)->toBe('in_stock');
});

test('release is idempotent and restores availability', function () {
    ['business' => $business, 'location' => $location, 'variant' => $variant] = reservationFixture();
    InventoryStock::factory()->for($business)->for($location)->for($variant, 'variant')->create(['quantity_on_hand' => '1.000']);
    $order = fixtureOrder($business, $location, $variant);
    fixtureReservation($order, $variant);
    DB::table('web_order_payments')->insert(['business_id' => $business->id, 'web_order_id' => $order->id,
        'method' => 'cod', 'status' => 'cod_due', 'currency' => 'LKR', 'amount_due' => '100.00',
        'created_at' => now(), 'updated_at' => now()]);
    $service = app(WebReservationService::class);
    expect(fixtureAvailability($business, $location, $variant))->toBe('0.000')
        ->and($service->release($order))->toBeTrue()
        ->and($service->release($order))->toBeFalse()
        ->and(fixtureAvailability($business, $location, $variant))->toBe('1.000');
});

test('expiry leaves confirmed orders reserved and has no invented deadline', function () {
    ['business' => $business, 'location' => $location, 'variant' => $variant] = reservationFixture();
    InventoryStock::factory()->for($business)->for($location)->for($variant, 'variant')->create(['quantity_on_hand' => '1.000']);
    $order = fixtureOrder($business, $location, $variant);
    fixtureReservation($order, $variant);
    $order->update(['status' => 'confirmed', 'reservation_expires_at' => now()->subMinute()]);
    expect(app(WebReservationService::class)->expire($order))->toBeFalse()
        ->and(app(WebReservationService::class)->activeQuantity($business->id, $location->id, $variant->id))->toBe('1.000');
    $order->update(['status' => 'pending_confirmation', 'reservation_expires_at' => null]);
    expect(app(WebReservationService::class)->expire($order))->toBeFalse();
    $order->update(['reservation_expires_at' => now()->subMinute()]);
    expect(app(WebReservationService::class)->expire($order))->toBeFalse();
    $order->update(['cod_confirmation_deadline_at' => now()->subMinute()]);
    DB::table('web_order_payments')->insert(['business_id' => $business->id, 'web_order_id' => $order->id,
        'method' => 'cod', 'status' => 'cod_due', 'currency' => 'LKR', 'amount_due' => '100.00',
        'created_at' => now(), 'updated_at' => now()]);
    expect(app(WebReservationService::class)->expire($order))->toBeTrue()
        ->and(app(WebReservationService::class)->expire($order))->toBeFalse();
});

test('consumption and physical deduction share one transaction', function () {
    ['business' => $business, 'location' => $location, 'variant' => $variant] = reservationFixture();
    InventoryStock::factory()->for($business)->for($location)->for($variant, 'variant')->create(['quantity_on_hand' => '1.000']);
    $order = fixtureOrder($business, $location, $variant);
    fixtureReservation($order, $variant);
    $order->update(['status' => 'confirmed']);
    $service = app(WebReservationService::class);
    expect(fn () => $service->consumeForFulfillment($order, function () use ($location, $variant): void {
        DB::table('inventory_stocks')->where('location_id', $location->id)->where('product_variant_id', $variant->id)->update(['quantity_on_hand' => '0.000']);
        throw new RuntimeException('simulate stock post failure');
    }))->toThrow(RuntimeException::class);
    expect((string) InventoryStock::query()->sole()->quantity_on_hand)->toBe('1.000')
        ->and($order->fresh()->status)->toBe('confirmed')
        ->and($service->activeQuantity($business->id, $location->id, $variant->id))->toBe('1.000');
    expect($service->consumeForFulfillment($order, function () use ($location, $variant): void {
        DB::table('inventory_stocks')->where('location_id', $location->id)->where('product_variant_id', $variant->id)->update(['quantity_on_hand' => '0.000']);
    }))->toBeTrue();
    expect($service->consumeForFulfillment($order, fn () => null))->toBeFalse()
        ->and($service->activeQuantity($business->id, $location->id, $variant->id))->toBe('0.000');
});

test('reservation quantity is business scoped', function () {
    ['business' => $business, 'location' => $location, 'variant' => $variant] = reservationFixture();
    InventoryStock::factory()->for($business)->for($location)->for($variant, 'variant')->create(['quantity_on_hand' => '1.000']);
    $order = fixtureOrder($business, $location, $variant);
    fixtureReservation($order, $variant);
    $other = Business::factory()->create(['setup_key' => 'second']);
    $otherCategory = Category::factory()->for($other)->create();
    $otherProduct = Product::factory()->for($other)->for($otherCategory)->create();
    $otherVariant = ProductVariant::factory()->for($other)->for($otherProduct)->create();
    expect(app(WebReservationService::class)->activeQuantity($other->id, $location->id, $variant->id))->toBe('0.000');
    expect(fn () => app(WebReservationService::class)->reserveComponent(
        $order, $order->getAttribute('fixture_item_id'), $otherVariant->id, '1.000', 'cross-business',
    ))->toThrow(ValidationException::class);
});
