<?php

use App\Models\Business;
use App\Models\Category;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SerializedDevice;
use App\Models\User;
use App\Models\WebDeliveryArea;
use App\Models\WebLocationDeliveryArea;
use App\Models\WebOrder;
use App\Models\WebPickupLocation;
use App\Models\WebProductListing;
use App\Models\WebQuote;
use App\ProductCondition;
use App\Services\WebReservationService;
use App\VariantTrackingMode;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../../Support/SelectedDeviceSelection.php';

function offerContext(): array
{
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner, 'owner')->create();
    $category = Category::factory()->for($business)->create();
    $location = Location::factory()->for($business)->create(['type' => 'shop']);
    WebPickupLocation::query()->create(['business_id' => $business->id, 'location_id' => $location->id,
        'public_code' => $location->code, 'is_active' => true]);
    $area = WebDeliveryArea::query()->create(['business_id' => $business->id, 'code' => 'central', 'name' => 'Central',
        'base_delivery_fee' => '250.00', 'currency' => $business->currency, 'is_active' => true]);
    WebLocationDeliveryArea::query()->create(['business_id' => $business->id, 'delivery_area_id' => $area->id,
        'location_id' => $location->id, 'selection_priority' => 10, 'is_active' => true]);

    return compact('owner', 'business', 'category', 'location', 'area');
}

function offerProduct(array $ctx, string $slug = 'phone', array $variantData = []): array
{
    $product = Product::factory()->for($ctx['business'])->for($ctx['category'])->create(['name' => 'Phone']);
    $variant = ProductVariant::factory()->for($ctx['business'])->for($product)->create(array_replace([
        'name' => '128 GB', 'selling_price' => '1000.00', 'cost_price' => '500.00',
    ], $variantData));
    WebProductListing::query()->create(['business_id' => $ctx['business']->id, 'product_id' => $product->id,
        'slug' => $slug, 'status' => 'published']);

    return compact('product', 'variant');
}

function offerStock(array $ctx, ProductVariant $variant, string $amount = '5.000'): void
{
    InventoryStock::factory()->for($ctx['business'])->for($ctx['location'])->for($variant, 'variant')
        ->create(['quantity_on_hand' => $amount]);
}

function offerRequestPayload(string $reference): array
{
    return ['quote_reference' => $reference, 'fulfillment_mode' => 'home_delivery',
        'delivery_area_code' => 'central', 'payment_method' => 'bank_transfer',
        'contact' => ['name' => 'Customer', 'phone' => '0771234567', 'email' => 'c@example.test'],
        'address' => ['line1' => 'Main Street', 'city' => 'Colombo']];
}

function preparedOffer($test, array $ctx, Product $product, ProductVariant $variant, int $quantity = 1): array
{
    $selection = [];
    if ($variant->condition === ProductCondition::Used && $variant->tracking_mode === VariantTrackingMode::Serialized) {
        $device = $variant->serializedDevices()->where('status', 'in_stock')->firstOrFail();
        $selection = ['device_selection_token' => selectFixtureDevice($test, $product, $variant, $device)];
    }
    $added = $test->postJson('/api/storefront/v1/cart/items', [...$selection, 'product_id' => $product->id,
        'variant_id' => $variant->id, 'quantity' => $quantity])->assertCreated();
    $token = $added->headers->get('X-Storefront-Cart-Token');
    $quote = $test->postJson('/api/storefront/v1/quotes', [], ['X-Storefront-Cart-Token' => $token])->assertOk();

    return [$token, offerRequestPayload($quote->json('data.reference'))];
}

test('first-release offers accept bank delivery and COD pickup with immutable mode terms', function () {
    config()->set('storefront.offer_ttl_minutes', 20);
    $ctx = offerContext();
    ['product' => $product, 'variant' => $variant] = offerProduct($ctx);
    offerStock($ctx, $variant);
    [$token, $delivery] = preparedOffer($this, $ctx, $product, $variant);
    $headers = ['X-Storefront-Cart-Token' => $token];
    $this->postJson('/api/storefront/v1/order-offers', $delivery, $headers)
        ->assertCreated()->assertJsonPath('data.fulfillment_mode', 'home_delivery')
        ->assertJsonPath('data.delivery_fee', '250.00');

    $pickup = $delivery;
    $pickup['payment_method'] = 'cod';
    $pickup['fulfillment_mode'] = 'shop_pickup';
    $pickup['pickup_location_code'] = $ctx['location']->code;
    unset($pickup['delivery_area_code'], $pickup['address']);
    $response = $this->postJson('/api/storefront/v1/order-offers', $pickup, $headers)
        ->assertCreated()->assertJsonPath('data.fulfillment_mode', 'shop_pickup')
        ->assertJsonPath('data.delivery_fee', '0.00')
        ->assertJsonPath('data.pickup_location.code', $ctx['location']->code)
        ->assertJsonPath('data.pickup_location.address', $ctx['location']->address);
    expect($response->json('data.delivery_area'))->toBeNull();
    $saved = DB::table('web_order_offers')->where('reference', $response->json('data.reference'))->first();
    expect($saved->delivery_area_id)->toBeNull()->and($saved->pickup_location_code_snapshot)->toBe($ctx['location']->code);
    expect(DB::table('sales')->count())->toBe(0);
    expect(DB::table('web_orders')->count())->toBe(0);
    expect(DB::table('web_order_reservations')->count())->toBe(0);
});

test('public pickup endpoint shows only explicitly enabled active shops with safe fields', function () {
    $ctx = offerContext();
    $warehouse = Location::factory()->for($ctx['business'])->create(['type' => 'warehouse']);
    WebPickupLocation::query()->create(['business_id' => $ctx['business']->id, 'location_id' => $warehouse->id,
        'public_code' => 'warehouse', 'is_active' => true]);
    $inactive = Location::factory()->for($ctx['business'])->create(['type' => 'shop', 'status' => 'inactive']);
    WebPickupLocation::query()->create(['business_id' => $ctx['business']->id, 'location_id' => $inactive->id,
        'public_code' => 'inactive', 'is_active' => true]);
    $hidden = Location::factory()->for($ctx['business'])->create(['type' => 'shop']);
    $hiddenMapping = WebPickupLocation::query()->create(['business_id' => $ctx['business']->id, 'location_id' => $hidden->id,
        'public_code' => 'hidden']);
    expect($hiddenMapping->fresh()->is_active)->toBeFalse();
    $response = $this->getJson('/api/storefront/v1/pickup-locations')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', $ctx['location']->code)
        ->assertJsonPath('data.0.address', $ctx['location']->address);
    expect(array_keys($response->json('data.0')))->toBe(['code', 'name', 'address', 'city']);
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
});

test('pickup mapping is business scoped and cannot duplicate a public code or location', function () {
    $ctx = offerContext();
    $second = Location::factory()->for($ctx['business'])->create(['type' => 'shop']);
    expect(fn () => WebPickupLocation::query()->create(['business_id' => $ctx['business']->id,
        'location_id' => $second->id, 'public_code' => $ctx['location']->code]))->toThrow(QueryException::class);
    expect(fn () => WebPickupLocation::query()->create(['business_id' => $ctx['business']->id,
        'location_id' => $ctx['location']->id, 'public_code' => 'different']))->toThrow(QueryException::class);
    $otherOwner = User::factory()->create();
    $other = Business::factory()->for($otherOwner, 'owner')->create(['setup_key' => 'secondary']);
    expect(fn () => WebPickupLocation::query()->create(['business_id' => $other->id,
        'location_id' => $ctx['location']->id, 'public_code' => 'foreign']))->toThrow(QueryException::class);
});

test('other business pickup shops are neither listed nor selectable', function () {
    config()->set('storefront.offer_ttl_minutes', 20);
    $ctx = offerContext();
    $otherOwner = User::factory()->create();
    $otherBusiness = Business::factory()->for($otherOwner, 'owner')->create(['setup_key' => 'secondary']);
    $otherShop = Location::factory()->for($otherBusiness)->create(['type' => 'shop']);
    WebPickupLocation::query()->create(['business_id' => $otherBusiness->id, 'location_id' => $otherShop->id,
        'public_code' => 'other-business-shop', 'is_active' => true]);
    $this->getJson('/api/storefront/v1/pickup-locations')->assertJsonCount(1, 'data');
    ['product' => $product, 'variant' => $variant] = offerProduct($ctx);
    offerStock($ctx, $variant);
    [$token, $payload] = preparedOffer($this, $ctx, $product, $variant);
    $payload['fulfillment_mode'] = 'shop_pickup';
    $payload['payment_method'] = 'cod';
    $payload['pickup_location_code'] = 'other-business-shop';
    unset($payload['delivery_area_code'], $payload['address']);
    $this->postJson('/api/storefront/v1/order-offers', $payload, ['X-Storefront-Cart-Token' => $token])
        ->assertUnprocessable()->assertJsonPath('error.code', 'PICKUP_LOCATION_UNAVAILABLE');
});

test('staff pickup settings require permission and only active shops can be enabled', function () {
    $ctx = offerContext();
    $staff = User::factory()->create();
    $ctx['location']->users()->attach($staff->id);
    $this->actingAs($staff)->get('/settings/storefront-pickup')->assertForbidden();
    $staff->givePermissionTo(Permission::findOrCreate('storefront.manage-pickup-locations', 'web'));
    $this->actingAs($staff)->get('/settings/storefront-pickup')->assertOk();
    $this->actingAs($staff)->put('/settings/storefront-pickup/'.$ctx['location']->id, [
        'public_code' => 'new-shop', 'display_name' => 'Customer Shop', 'selection_priority' => 5,
        'is_active' => true,
    ])->assertRedirect();
    $this->getJson('/api/storefront/v1/pickup-locations')->assertJsonPath('data.0.name', 'Customer Shop');
    $this->actingAs($staff)->put('/settings/storefront-pickup/'.$ctx['location']->id, [
        'public_code' => 'new-shop', 'display_name' => 'Customer Shop', 'selection_priority' => 5,
        'is_active' => false,
    ])->assertRedirect();
    $this->getJson('/api/storefront/v1/pickup-locations')->assertJsonCount(0, 'data');
    $warehouse = Location::factory()->for($ctx['business'])->create(['type' => 'warehouse']);
    $warehouse->users()->attach($staff->id);
    $this->actingAs($staff)->put('/settings/storefront-pickup/'.$warehouse->id, [
        'public_code' => 'warehouse', 'selection_priority' => 100, 'is_active' => true,
    ])->assertSessionHasErrors('is_active');
    $unassigned = Location::factory()->for($ctx['business'])->create(['type' => 'shop']);
    $this->actingAs($staff)->put('/settings/storefront-pickup/'.$unassigned->id, [
        'public_code' => 'unassigned', 'selection_priority' => 100, 'is_active' => true,
    ])->assertNotFound();
});

test('pickup offer never reroutes to another enabled shop with stock', function () {
    config()->set('storefront.offer_ttl_minutes', 20);
    $ctx = offerContext();
    $otherShop = Location::factory()->for($ctx['business'])->create(['type' => 'shop']);
    WebPickupLocation::query()->create(['business_id' => $ctx['business']->id, 'location_id' => $otherShop->id,
        'public_code' => 'other-shop', 'is_active' => true]);
    ['product' => $product, 'variant' => $variant] = offerProduct($ctx);
    InventoryStock::factory()->for($ctx['business'])->for($otherShop)->for($variant, 'variant')
        ->create(['quantity_on_hand' => '2.000']);
    [$token, $payload] = preparedOffer($this, $ctx, $product, $variant);
    $payload['fulfillment_mode'] = 'shop_pickup';
    $payload['payment_method'] = 'cod';
    $payload['pickup_location_code'] = $ctx['location']->code;
    unset($payload['delivery_area_code'], $payload['address']);
    $this->postJson('/api/storefront/v1/order-offers', $payload, ['X-Storefront-Cart-Token' => $token])
        ->assertStatus(409)->assertJsonPath('error.code', 'PICKUP_LOCATION_UNAVAILABLE');
    $payload['pickup_location_code'] = 'other-shop';
    $this->postJson('/api/storefront/v1/order-offers', $payload, ['X-Storefront-Cart-Token' => $token])
        ->assertCreated()->assertJsonPath('data.pickup_location.code', 'other-shop');
});

test('disabled pickup mapping rejects offer even when its shop is active', function () {
    config()->set('storefront.offer_ttl_minutes', 20);
    $ctx = offerContext();
    ['product' => $product, 'variant' => $variant] = offerProduct($ctx);
    offerStock($ctx, $variant);
    [$token, $payload] = preparedOffer($this, $ctx, $product, $variant);
    $payload['fulfillment_mode'] = 'shop_pickup';
    $payload['payment_method'] = 'cod';
    $payload['pickup_location_code'] = $ctx['location']->code;
    unset($payload['delivery_area_code'], $payload['address']);
    WebPickupLocation::query()->where('location_id', $ctx['location']->id)->update(['is_active' => false]);
    $this->postJson('/api/storefront/v1/order-offers', $payload, ['X-Storefront-Cart-Token' => $token])
        ->assertUnprocessable()->assertJsonPath('error.code', 'PICKUP_LOCATION_UNAVAILABLE');
});

test('first-release offers reject crossed modes and unapproved courier COD', function () {
    config()->set('storefront.offer_ttl_minutes', 20);
    $ctx = offerContext();
    ['product' => $product, 'variant' => $variant] = offerProduct($ctx);
    offerStock($ctx, $variant);
    [$token, $delivery] = preparedOffer($this, $ctx, $product, $variant);
    $headers = ['X-Storefront-Cart-Token' => $token];
    $delivery['payment_method'] = 'cod';
    $this->postJson('/api/storefront/v1/order-offers', $delivery, $headers)
        ->assertUnprocessable()->assertJsonPath('error.code', 'FULFILLMENT_MODE_UNAVAILABLE');
    $delivery['fulfillment_mode'] = 'shop_pickup';
    $delivery['payment_method'] = 'bank_transfer';
    $delivery['pickup_location_code'] = $ctx['location']->code;
    unset($delivery['delivery_area_code'], $delivery['address']);
    $this->postJson('/api/storefront/v1/order-offers', $delivery, $headers)
        ->assertUnprocessable()->assertJsonPath('error.code', 'FULFILLMENT_MODE_UNAVAILABLE');
    $delivery['fulfillment_mode'] = 'courier_cod';
    $delivery['payment_method'] = 'cod';
    $this->postJson('/api/storefront/v1/order-offers', $delivery, $headers)->assertUnprocessable();
});

test('delivery area endpoint returns only supported active business areas without location identifiers', function () {
    $ctx = offerContext();
    WebDeliveryArea::query()->create(['business_id' => $ctx['business']->id, 'code' => 'hidden', 'name' => 'Hidden',
        'base_delivery_fee' => '0.00', 'currency' => $ctx['business']->currency, 'is_active' => false]);
    $response = $this->getJson('/api/storefront/v1/delivery-areas')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', 'central');
    expect($response->getContent())->not->toContain('location_id', 'business_id', 'selection_priority');
    $ctx['location']->update(['status' => 'inactive']);
    $this->getJson('/api/storefront/v1/delivery-areas')->assertJsonCount(0, 'data');
});

test('delivery area mappings enforce same business and unique location', function () {
    $ctx = offerContext();
    $this->expectException(UniqueConstraintViolationException::class);
    WebLocationDeliveryArea::query()->create(['business_id' => $ctx['business']->id, 'delivery_area_id' => $ctx['area']->id,
        'location_id' => $ctx['location']->id, 'is_active' => true]);
});

test('delivery areas from another business are not listed or accepted', function () {
    config()->set('storefront.offer_ttl_minutes', 20);
    $ctx = offerContext();
    $otherOwner = User::factory()->create();
    $otherBusiness = Business::factory()->for($otherOwner, 'owner')->create(['setup_key' => 'secondary']);
    $otherLocation = Location::factory()->for($otherBusiness)->create();
    $otherArea = WebDeliveryArea::query()->create(['business_id' => $otherBusiness->id,
        'code' => 'elsewhere', 'name' => 'Elsewhere', 'base_delivery_fee' => '5.00',
        'currency' => $otherBusiness->currency, 'is_active' => true]);
    WebLocationDeliveryArea::query()->create(['business_id' => $otherBusiness->id,
        'delivery_area_id' => $otherArea->id, 'location_id' => $otherLocation->id, 'is_active' => true]);
    $this->getJson('/api/storefront/v1/delivery-areas')->assertJsonCount(1, 'data');
    ['product' => $product, 'variant' => $variant] = offerProduct($ctx);
    offerStock($ctx, $variant);
    [$token, $payload] = preparedOffer($this, $ctx, $product, $variant);
    $payload['delivery_area_code'] = 'elsewhere';
    $this->postJson('/api/storefront/v1/order-offers', $payload, ['X-Storefront-Cart-Token' => $token])
        ->assertUnprocessable()->assertJsonPath('error.code', 'DELIVERY_AREA_UNAVAILABLE');
});

test('database rejects a location mapping across businesses', function () {
    $ctx = offerContext();
    $otherOwner = User::factory()->create();
    $otherBusiness = Business::factory()->for($otherOwner, 'owner')->create(['setup_key' => 'secondary']);
    $otherLocation = Location::factory()->for($otherBusiness)->create();
    $this->expectException(QueryException::class);
    WebLocationDeliveryArea::query()->create(['business_id' => $ctx['business']->id,
        'delivery_area_id' => $ctx['area']->id, 'location_id' => $otherLocation->id,
        'is_active' => true]);
});

test('delivery management requires dedicated permission', function () {
    $ctx = offerContext();
    $staff = User::factory()->create();
    $this->actingAs($staff)->get('/settings/storefront-delivery')->assertForbidden();
    $this->actingAs($staff)->post('/settings/storefront-delivery', ['name' => 'Other', 'code' => 'other',
        'base_delivery_fee' => '0.00', 'is_active' => true])->assertForbidden();
    $staff->givePermissionTo(Permission::findOrCreate('storefront.manage-delivery', 'web'));
    $this->actingAs($staff)->get('/settings/storefront-delivery')->assertOk();
    $this->actingAs($staff)->put("/settings/storefront-delivery/{$ctx['area']->id}", [
        'name' => 'Central edited', 'code' => 'central', 'description' => 'Delivery available',
        'base_delivery_fee' => '300.00', 'is_active' => true])->assertRedirect();
    expect($ctx['area']->fresh()->base_delivery_fee)->toBe('300.00');
});

test('offer stays disabled until expiry policy is configured', function () {
    config()->set('storefront.offer_ttl_minutes', null);
    $ctx = offerContext();
    ['product' => $product, 'variant' => $variant] = offerProduct($ctx);
    offerStock($ctx, $variant);
    [$token, $payload] = preparedOffer($this, $ctx, $product, $variant);
    $this->postJson('/api/storefront/v1/order-offers', $payload, ['X-Storefront-Cart-Token' => $token])
        ->assertStatus(503)->assertJsonPath('error.code', 'OFFER_NOT_CONFIGURED');
    expect(DB::table('web_order_offers')->count())->toBe(0);
});

test('offer computes final total and fee override without creating orders or reservations', function () {
    config()->set('storefront.offer_ttl_minutes', 20);
    $ctx = offerContext();
    ['product' => $product, 'variant' => $variant] = offerProduct($ctx);
    offerStock($ctx, $variant, '2.000');
    $ctx['area']->mappings()->update(['delivery_fee_override' => '75.00']);
    [$token, $payload] = preparedOffer($this, $ctx, $product, $variant, 2);
    $payload['total'] = '0.01';
    $response = $this->postJson('/api/storefront/v1/order-offers', $payload, ['X-Storefront-Cart-Token' => $token])
        ->assertCreated()->assertJsonPath('data.subtotal', '2000.00')->assertJsonPath('data.delivery_fee', '75.00')
        ->assertJsonPath('data.total', '2075.00')->assertJsonPath('data.inventory_reserved', false)
        ->assertJsonPath('data.total_is_final', true);
    expect($response->getContent())->not->toContain('location_id', 'serialized_device_id', 'imei', 'unit_cost');
    expect(DB::table('web_orders')->count())->toBe(0);
    expect(DB::table('web_order_reservations')->count())->toBe(0);
    expect(DB::table('sales')->count())->toBe(0);
    expect((string) DB::table('inventory_stocks')->value('quantity_on_hand'))->toBe('2.000');
    expect(Carbon::parse($response->json('data.expires_at'))->lessThanOrEqualTo(WebQuote::query()->firstOrFail()->expires_at))->toBeTrue();
});

test('offer rejects expired quote cart changes and unpublished product', function () {
    config()->set('storefront.offer_ttl_minutes', 20);
    $ctx = offerContext();
    ['product' => $product, 'variant' => $variant] = offerProduct($ctx);
    offerStock($ctx, $variant);
    [$token, $payload] = preparedOffer($this, $ctx, $product, $variant);
    WebQuote::query()->first()->update(['expires_at' => now()->subMinute()]);
    $this->postJson('/api/storefront/v1/order-offers', $payload, ['X-Storefront-Cart-Token' => $token])->assertStatus(409)->assertJsonPath('error.code', 'QUOTE_STALE');
    $fresh = $this->postJson('/api/storefront/v1/quotes', [], ['X-Storefront-Cart-Token' => $token])->assertOk();
    $payload['quote_reference'] = $fresh->json('data.reference');
    $this->postJson('/api/storefront/v1/cart/items', ['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1], ['X-Storefront-Cart-Token' => $token])->assertCreated();
    $this->postJson('/api/storefront/v1/order-offers', $payload, ['X-Storefront-Cart-Token' => $token])->assertStatus(409)->assertJsonPath('error.code', 'QUOTE_STALE');
    $fresh = $this->postJson('/api/storefront/v1/quotes', [], ['X-Storefront-Cart-Token' => $token])->assertOk();
    $payload['quote_reference'] = $fresh->json('data.reference');
    $product->webListing()->update(['status' => 'draft']);
    $this->postJson('/api/storefront/v1/order-offers', $payload, ['X-Storefront-Cart-Token' => $token])->assertStatus(409)->assertJsonPath('error.code', 'QUOTE_STALE');
});

test('offer selects exact used device price privately and never exposes IMEI', function () {
    config()->set('storefront.offer_ttl_minutes', 20);
    $ctx = offerContext();
    ['product' => $product, 'variant' => $variant] = offerProduct($ctx, 'used-phone', [
        'condition' => ProductCondition::Used, 'tracking_mode' => 'serialized', 'selling_price' => '1200.00',
    ]);
    $product->update(['product_type' => 'serialized']);
    $device = SerializedDevice::factory()->for($ctx['location'])->for($variant, 'variant')->create([
        'business_id' => $ctx['business']->id, 'condition' => ProductCondition::Used,
        'unit_selling_price' => '900.00', 'imei' => '123456789012345',
    ]);
    [$token, $payload] = preparedOffer($this, $ctx, $product, $variant);
    $response = $this->postJson('/api/storefront/v1/order-offers', $payload, ['X-Storefront-Cart-Token' => $token])
        ->assertCreated()->assertJsonPath('data.items.0.unit_price', '900.00')->assertJsonPath('data.items.0.condition', 'used');
    expect($response->getContent())->not->toContain($device->imei, 'serialized_device_id', 'private_allocation');
    expect(DB::table('web_order_offer_items')->value('serialized_device_id'))->toBe($device->id);
    expect($device->fresh()->status->value)->toBe('in_stock');
    $payload['fulfillment_mode'] = 'shop_pickup';
    $payload['payment_method'] = 'cod';
    $payload['pickup_location_code'] = $ctx['location']->code;
    unset($payload['delivery_area_code'], $payload['address']);
    $pickup = $this->postJson('/api/storefront/v1/order-offers', $payload, ['X-Storefront-Cart-Token' => $token])
        ->assertCreated()->assertJsonPath('data.items.0.unit_price', '900.00');
    expect($pickup->getContent())->not->toContain($device->imei, 'serialized_device_id');
});

test('offer rejects unsupported area, invalid payment and foreign guest token', function () {
    config()->set('storefront.offer_ttl_minutes', 20);
    $ctx = offerContext();
    ['product' => $product, 'variant' => $variant] = offerProduct($ctx);
    offerStock($ctx, $variant);
    [$token, $payload] = preparedOffer($this, $ctx, $product, $variant);
    $payload['payment_method'] = 'card';
    $this->postJson('/api/storefront/v1/order-offers', $payload, ['X-Storefront-Cart-Token' => $token])->assertUnprocessable();
    $payload['payment_method'] = 'bank_transfer';
    $this->postJson('/api/storefront/v1/order-offers', $payload, ['X-Storefront-Cart-Token' => str_repeat('a', 64)])->assertNotFound();
    $payload['delivery_area_code'] = 'unknown';
    $this->postJson('/api/storefront/v1/order-offers', $payload, ['X-Storefront-Cart-Token' => $token])
        ->assertUnprocessable()->assertJsonPath('error.code', 'DELIVERY_AREA_UNAVAILABLE');
});

test('offer chooses the first location that can fulfill the whole cart and applies its fee', function () {
    config()->set('storefront.offer_ttl_minutes', 20);
    $ctx = offerContext();
    ['product' => $product, 'variant' => $variant] = offerProduct($ctx);
    offerStock($ctx, $variant, '2.000');
    $second = Location::factory()->for($ctx['business'])->create();
    InventoryStock::factory()->for($ctx['business'])->for($second)->for($variant, 'variant')->create(['quantity_on_hand' => '2.000']);
    WebLocationDeliveryArea::query()->create(['business_id' => $ctx['business']->id, 'delivery_area_id' => $ctx['area']->id,
        'location_id' => $second->id, 'selection_priority' => 20, 'delivery_fee_override' => '500.00', 'is_active' => true]);
    [$token, $payload] = preparedOffer($this, $ctx, $product, $variant, 2);
    $first = $this->postJson('/api/storefront/v1/order-offers', $payload, ['X-Storefront-Cart-Token' => $token])
        ->assertCreated()->assertJsonPath('data.delivery_fee', '250.00');
    expect(DB::table('web_order_offers')->where('reference', $first->json('data.reference'))->value('location_id'))->toBe($ctx['location']->id);
    DB::table('inventory_stocks')->where('location_id', $ctx['location']->id)->update(['quantity_on_hand' => '1.000']);
    $secondOffer = $this->postJson('/api/storefront/v1/order-offers', $payload, ['X-Storefront-Cart-Token' => $token])
        ->assertCreated()->assertJsonPath('data.delivery_fee', '500.00');
    expect(DB::table('web_order_offers')->where('reference', $secondOffer->json('data.reference'))->value('location_id'))->toBe($second->id);
    DB::table('inventory_stocks')->where('location_id', $second->id)->update(['quantity_on_hand' => '1.000']);
    $this->postJson('/api/storefront/v1/order-offers', $payload, ['X-Storefront-Cart-Token' => $token])
        ->assertStatus(409)->assertJsonPath('error.code', 'NO_SINGLE_LOCATION');
});

test('offer rejects inactive variant and unavailable serialized device after quote', function () {
    config()->set('storefront.offer_ttl_minutes', 20);
    $ctx = offerContext();
    ['product' => $product, 'variant' => $variant] = offerProduct($ctx, 'used-phone', [
        'condition' => ProductCondition::Used, 'tracking_mode' => 'serialized', 'selling_price' => '1200.00',
    ]);
    $product->update(['product_type' => 'serialized']);
    $device = SerializedDevice::factory()->for($ctx['location'])->for($variant, 'variant')->create([
        'business_id' => $ctx['business']->id, 'condition' => ProductCondition::Used,
        'unit_selling_price' => '900.00',
    ]);
    [$token, $payload] = preparedOffer($this, $ctx, $product, $variant);
    $variant->update(['status' => 'inactive']);
    $this->postJson('/api/storefront/v1/order-offers', $payload, ['X-Storefront-Cart-Token' => $token])
        ->assertStatus(409)->assertJsonPath('error.code', 'QUOTE_STALE');
    $variant->update(['status' => 'active']);
    $device->update(['status' => 'sold']);
    $this->postJson('/api/storefront/v1/order-offers', $payload, ['X-Storefront-Cart-Token' => $token])
        ->assertStatus(409)->assertJsonPath('error.code', 'QUOTE_STALE');
});

test('offer aggregates shared bundle and standalone component demand', function () {
    config()->set('storefront.offer_ttl_minutes', 20);
    $ctx = offerContext();
    ['product' => $bundleProduct, 'variant' => $bundle] = offerProduct($ctx, 'bundle');
    $bundleProduct->update(['product_type' => 'combo']);
    ['product' => $componentProduct, 'variant' => $component] = offerProduct($ctx, 'component');
    DB::table('bundle_components')->insert(['business_id' => $ctx['business']->id,
        'bundle_variant_id' => $bundle->id, 'component_variant_id' => $component->id,
        'quantity' => '2.000', 'created_at' => now(), 'updated_at' => now()]);
    offerStock($ctx, $component, '3.000');
    $added = $this->postJson('/api/storefront/v1/cart/items', ['product_id' => $bundleProduct->id,
        'variant_id' => $bundle->id, 'quantity' => 1])->assertCreated();
    $token = $added->headers->get('X-Storefront-Cart-Token');
    $this->postJson('/api/storefront/v1/cart/items', ['product_id' => $componentProduct->id,
        'variant_id' => $component->id, 'quantity' => 1], ['X-Storefront-Cart-Token' => $token])->assertCreated();
    $quote = $this->postJson('/api/storefront/v1/quotes', [], ['X-Storefront-Cart-Token' => $token])->assertOk();
    $payload = offerRequestPayload($quote->json('data.reference'));
    DB::table('inventory_stocks')->where('product_variant_id', $component->id)->update(['quantity_on_hand' => '2.000']);
    $this->postJson('/api/storefront/v1/order-offers', $payload, ['X-Storefront-Cart-Token' => $token])
        ->assertStatus(409)->assertJsonPath('error.code', 'NO_SINGLE_LOCATION');
    DB::table('inventory_stocks')->where('product_variant_id', $component->id)->update(['quantity_on_hand' => '3.000']);
    $this->postJson('/api/storefront/v1/order-offers', $payload, ['X-Storefront-Cart-Token' => $token])
        ->assertCreated()->assertJsonPath('data.total', '2250.00');
    $payload['fulfillment_mode'] = 'shop_pickup';
    $payload['payment_method'] = 'cod';
    $payload['pickup_location_code'] = $ctx['location']->code;
    unset($payload['delivery_area_code'], $payload['address']);
    $this->postJson('/api/storefront/v1/order-offers', $payload, ['X-Storefront-Cart-Token' => $token])
        ->assertCreated()->assertJsonPath('data.delivery_fee', '0.00');
});

test('offer excludes quantity already reserved by an existing web order', function () {
    config()->set('storefront.offer_ttl_minutes', 20);
    $ctx = offerContext();
    ['product' => $product, 'variant' => $variant] = offerProduct($ctx);
    offerStock($ctx, $variant, '2.000');
    [$token, $payload] = preparedOffer($this, $ctx, $product, $variant, 1);
    $order = WebOrder::query()->create([
        'business_id' => $ctx['business']->id, 'location_id' => $ctx['location']->id,
        'reference' => (string) Str::ulid(), 'submission_key' => (string) Str::uuid(),
        'payload_hash' => hash('sha256', 'test'), 'status' => 'pending_confirmation',
        'payment_method' => 'cod', 'payment_status' => 'cod_due', 'currency' => $ctx['business']->currency,
        'subtotal' => '1000.00', 'total' => '1000.00',
    ]);
    $orderItemId = DB::table('web_order_items')->insertGetId([
        'business_id' => $ctx['business']->id, 'web_order_id' => $order->id,
        'product_id' => $product->id, 'product_variant_id' => $variant->id,
        'line_number' => 1, 'product_name_snapshot' => 'Phone', 'product_slug_snapshot' => 'phone',
        'variant_name_snapshot' => '128 GB', 'quantity' => '1.000', 'unit_price' => '1000.00',
        'line_total' => '1000.00', 'created_at' => now(), 'updated_at' => now(),
    ]);
    app(WebReservationService::class)->reserveComponent($order, $orderItemId, $variant->id, '1.000', 'test-line');
    $this->postJson('/api/storefront/v1/order-offers', $payload, ['X-Storefront-Cart-Token' => $token])
        ->assertCreated();
    expect(DB::table('web_order_reservations')->count())->toBe(1);
    $payload['fulfillment_mode'] = 'shop_pickup';
    $payload['payment_method'] = 'cod';
    $payload['pickup_location_code'] = $ctx['location']->code;
    unset($payload['delivery_area_code'], $payload['address']);
    $this->postJson('/api/storefront/v1/order-offers', $payload, ['X-Storefront-Cart-Token' => $token])
        ->assertCreated();
    DB::table('inventory_stocks')->where('product_variant_id', $variant->id)->update(['quantity_on_hand' => '1.000']);
    $this->postJson('/api/storefront/v1/order-offers', $payload, ['X-Storefront-Cart-Token' => $token])
        ->assertStatus(409)->assertJsonPath('error.code', 'PICKUP_LOCATION_UNAVAILABLE');
    DB::table('inventory_stocks')->where('product_variant_id', $variant->id)->update(['quantity_on_hand' => '2.000']);
    $this->postJson('/api/storefront/v1/cart/items', ['product_id' => $product->id,
        'variant_id' => $variant->id, 'quantity' => 1], ['X-Storefront-Cart-Token' => $token])->assertStatus(409);
});
