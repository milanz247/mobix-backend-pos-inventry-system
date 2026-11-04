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
use App\Models\WebOrderOffer;
use App\Models\WebOrderReservation;
use App\Models\WebPickupLocation;
use App\Models\WebProductListing;
use App\ProductCondition;
use App\Services\WebReservationService;
use App\VariantTrackingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;

require_once __DIR__.'/../../Support/SelectedDeviceSelection.php';

function submissionFixture($test, string $method = 'cod', bool $used = false, bool $secondLine = false): array
{
    config()->set('storefront.offer_ttl_minutes', 20);
    config()->set('storefront.cod_confirmation_ttl_minutes', 20);
    config()->set('storefront.bank_proof_ttl_minutes', 20);
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner, 'owner')->create();
    $category = Category::factory()->for($business)->create();
    $location = Location::factory()->for($business)->create(['type' => 'shop']);
    WebPickupLocation::query()->create(['business_id' => $business->id, 'location_id' => $location->id,
        'public_code' => $location->code, 'is_active' => true]);
    $area = WebDeliveryArea::query()->create(['business_id' => $business->id, 'code' => 'central',
        'name' => 'Central', 'base_delivery_fee' => '250.00', 'currency' => $business->currency, 'is_active' => true]);
    WebLocationDeliveryArea::query()->create(['business_id' => $business->id, 'delivery_area_id' => $area->id,
        'location_id' => $location->id, 'selection_priority' => 1, 'is_active' => true]);
    $product = Product::factory()->for($business)->for($category)->create(['name' => 'Phone']);
    if ($used) {
        $product->update(['product_type' => 'serialized']);
    }
    $variant = ProductVariant::factory()->for($business)->for($product)->create(['name' => '128 GB',
        'selling_price' => '1000.00', 'cost_price' => '500.00',
        'condition' => $used ? ProductCondition::Used : ProductCondition::New,
        'tracking_mode' => $used ? VariantTrackingMode::Serialized : VariantTrackingMode::QuantityBased]);
    WebProductListing::query()->create(['business_id' => $business->id, 'product_id' => $product->id,
        'slug' => 'phone', 'status' => 'published']);
    InventoryStock::factory()->for($business)->for($location)->for($variant, 'variant')
        ->create(['quantity_on_hand' => '5.000']);
    $device = $used ? SerializedDevice::factory()->for($location)->for($variant, 'variant')->create([
        'business_id' => $business->id, 'condition' => ProductCondition::Used, 'unit_selling_price' => '900.00',
    ]) : null;
    $selection = $used ? ['device_selection_token' => selectFixtureDevice($test, $product, $variant, $device)] : [];
    $added = $test->postJson('/api/storefront/v1/cart/items', [...$selection, 'product_id' => $product->id,
        'variant_id' => $variant->id, 'quantity' => 1])->assertCreated();
    $token = $added->headers->get('X-Storefront-Cart-Token');
    if ($secondLine) {
        $otherProduct = Product::factory()->for($business)->for($category)->create(['name' => 'Charger']);
        $otherVariant = ProductVariant::factory()->for($business)->for($otherProduct)->create([
            'name' => 'USB-C', 'selling_price' => '500.00', 'cost_price' => '200.00',
        ]);
        WebProductListing::query()->create(['business_id' => $business->id, 'product_id' => $otherProduct->id,
            'slug' => 'charger', 'status' => 'published']);
        InventoryStock::factory()->for($business)->for($location)->for($otherVariant, 'variant')
            ->create(['quantity_on_hand' => '5.000']);
        $test->postJson('/api/storefront/v1/cart/items', ['product_id' => $otherProduct->id,
            'variant_id' => $otherVariant->id, 'quantity' => 1], ['X-Storefront-Cart-Token' => $token])->assertCreated();
    }
    $quote = $test->postJson('/api/storefront/v1/quotes', [], ['X-Storefront-Cart-Token' => $token])->assertOk();
    $offer = $test->postJson('/api/storefront/v1/order-offers', [
        'quote_reference' => $quote->json('data.reference'),
        'fulfillment_mode' => $method === 'cod' ? 'shop_pickup' : 'home_delivery',
        ...($method === 'cod' ? ['pickup_location_code' => $location->code] : ['delivery_area_code' => 'central']),
        'payment_method' => $method, 'contact' => ['name' => 'Customer', 'phone' => '0771234567'],
        ...($method === 'cod' ? [] : ['address' => ['line1' => 'Main Street', 'city' => 'Colombo']]),
    ], ['X-Storefront-Cart-Token' => $token])->assertCreated();

    return compact('business', 'location', 'area', 'product', 'variant', 'device', 'token', 'offer');
}

function submissionHeaders(array $fixture, ?string $key = null): array
{
    return ['X-Storefront-Cart-Token' => $fixture['token'], 'Idempotency-Key' => $key ?? (string) Str::uuid()];
}

function submissionBody(array $fixture): array
{
    return ['offer_reference' => $fixture['offer']->json('data.reference'),
        'accepted_terms_digest' => $fixture['offer']->json('data.terms_digest'), 'accepted' => true];
}

function enableSubmissionForTest(): void
{
    config()->set('storefront.order_submission_enabled', true);
    config()->set('storefront.reservation_ttl_minutes', 30);
    config()->set('storefront.order_access_key', str_repeat('k', 32));
}

test('order submission is disabled by default and writes nothing', function () {
    $fixture = submissionFixture($this);
    config()->set('storefront.order_submission_enabled', false);
    $this->postJson('/api/storefront/v1/orders', submissionBody($fixture), submissionHeaders($fixture))
        ->assertStatus(503)->assertJsonPath('error.code', 'ORDER_SUBMISSION_DISABLED');
    expect(WebOrder::query()->count())->toBe(0);
    expect(DB::table('web_order_reservations')->count())->toBe(0);
});

test('submission fails closed without an approved reservation deadline or access key', function () {
    $fixture = submissionFixture($this);
    config()->set('storefront.order_submission_enabled', true);
    config()->set('storefront.reservation_ttl_minutes', null);
    $this->postJson('/api/storefront/v1/orders', submissionBody($fixture), submissionHeaders($fixture))
        ->assertStatus(503)->assertJsonPath('error.code', 'ORDER_CONFIGURATION_REQUIRED');
    expect(WebOrder::query()->count())->toBe(0);
});

test('submission fails closed when the applicable operational deadline is missing or exceeds reservation lifetime', function () {
    $fixture = submissionFixture($this);
    enableSubmissionForTest();
    config()->set('storefront.cod_confirmation_ttl_minutes', null);
    $this->postJson('/api/storefront/v1/orders', submissionBody($fixture), submissionHeaders($fixture))
        ->assertStatus(503)->assertJsonPath('error.code', 'ORDER_CONFIGURATION_REQUIRED');
    config()->set('storefront.cod_confirmation_ttl_minutes', 31);
    $this->postJson('/api/storefront/v1/orders', submissionBody($fixture), submissionHeaders($fixture))
        ->assertStatus(503)->assertJsonPath('error.code', 'ORDER_CONFIGURATION_REQUIRED');
    expect(WebOrder::query()->count())->toBe(0);
});

test('accepted COD offer creates one order payment obligation and quantity reservation', function () {
    $fixture = submissionFixture($this);
    config()->set('storefront.order_submission_enabled', true);
    config()->set('storefront.reservation_ttl_minutes', 30);
    config()->set('storefront.order_access_key', str_repeat('k', 32));
    $response = $this->postJson('/api/storefront/v1/orders', submissionBody($fixture), submissionHeaders($fixture))
        ->assertCreated()->assertJsonPath('data.status', 'pending_confirmation')
        ->assertJsonPath('data.payment_status', 'cod_due')->assertJsonPath('data.inventory_reserved', true);
    expect($response->headers->get('X-Storefront-Order-Credential'))->toHaveLength(64);
    expect($response->getContent())->not->toContain('guest_access_token_hash', 'serialized_device_id', 'imei', 'cost_price');
    expect(WebOrder::query()->count())->toBe(1);
    expect(DB::table('web_order_items')->count())->toBe(1);
    expect(DB::table('web_order_status_histories')->count())->toBe(1);
    expect(DB::table('web_order_payments')->where('status', 'cod_due')->count())->toBe(1);
    expect(DB::table('web_order_reservations')->where('status', 'active')->count())->toBe(1);
    expect($fixture['variant']->fresh()->selling_price)->toBe('1000.00');
    expect(InventoryStock::query()->where('product_variant_id', $fixture['variant']->id)->value('quantity_on_hand'))->toBe('5.000');
});

test('bank transfer remains unverified and cannot prepare itself', function () {
    $fixture = submissionFixture($this, 'bank_transfer');
    config()->set('storefront.order_submission_enabled', true);
    config()->set('storefront.reservation_ttl_minutes', 30);
    config()->set('storefront.order_access_key', str_repeat('k', 32));
    $this->postJson('/api/storefront/v1/orders', submissionBody($fixture), submissionHeaders($fixture))
        ->assertCreated()->assertJsonPath('data.payment_status', 'transfer_awaiting_proof')
        ->assertJsonPath('data.status', 'pending_confirmation');
    expect(DB::table('web_order_payments')->where('status', 'transfer_awaiting_proof')->count())->toBe(1);
});

test('same key and terms replay committed order even after offer expiry, changed terms conflict', function () {
    $fixture = submissionFixture($this);
    config()->set('storefront.order_submission_enabled', true);
    config()->set('storefront.reservation_ttl_minutes', 30);
    config()->set('storefront.order_access_key', str_repeat('k', 32));
    $headers = submissionHeaders($fixture);
    $body = submissionBody($fixture);
    $first = $this->postJson('/api/storefront/v1/orders', $body, $headers)->assertCreated();
    WebOrderOffer::query()->where('reference', $body['offer_reference'])->update(['expires_at' => now()->subMinute()]);
    $this->postJson('/api/storefront/v1/orders', $body, $headers)->assertOk()
        ->assertJsonPath('data.reference', $first->json('data.reference'));
    $this->postJson('/api/storefront/v1/orders', [...$body, 'accepted_terms_digest' => str_repeat('a', 64)], $headers)
        ->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
    expect(WebOrder::query()->count())->toBe(1);
    expect(DB::table('web_order_reservations')->count())->toBe(1);
});

test('changed price rejects accepted offer without partial order', function () {
    $fixture = submissionFixture($this);
    config()->set('storefront.order_submission_enabled', true);
    config()->set('storefront.reservation_ttl_minutes', 30);
    config()->set('storefront.order_access_key', str_repeat('k', 32));
    $fixture['variant']->update(['selling_price' => '1200.00']);
    $this->postJson('/api/storefront/v1/orders', submissionBody($fixture), submissionHeaders($fixture))
        ->assertStatus(409)->assertJsonPath('error.code', 'OFFER_STALE');
    expect(WebOrder::query()->count())->toBe(0);
    expect(DB::table('web_order_reservations')->count())->toBe(0);
});

test('missing acceptance and invalid key are rejected without writes', function () {
    $fixture = submissionFixture($this);
    enableSubmissionForTest();
    $this->postJson('/api/storefront/v1/orders', [...submissionBody($fixture), 'accepted' => false], submissionHeaders($fixture))
        ->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_REQUEST');
    $this->postJson('/api/storefront/v1/orders', submissionBody($fixture),
        ['X-Storefront-Cart-Token' => $fixture['token'], 'Idempotency-Key' => 'short'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_IDEMPOTENCY_KEY');
    expect(WebOrder::query()->count())->toBe(0);
});

test('expired offer and changed delivery fee require a fresh offer', function () {
    $fixture = submissionFixture($this, 'bank_transfer');
    enableSubmissionForTest();
    $offer = WebOrderOffer::query()->where('reference', $fixture['offer']->json('data.reference'))->firstOrFail();
    $offer->update(['expires_at' => now()->subMinute()]);
    $this->postJson('/api/storefront/v1/orders', submissionBody($fixture), submissionHeaders($fixture))
        ->assertStatus(410)->assertJsonPath('error.code', 'OFFER_EXPIRED');
    $offer->update(['expires_at' => now()->addMinutes(10)]);
    $fixture['area']->update(['base_delivery_fee' => '300.00']);
    $this->postJson('/api/storefront/v1/orders', submissionBody($fixture), submissionHeaders($fixture))
        ->assertStatus(409)->assertJsonPath('error.code', 'OFFER_STALE');
    expect(WebOrder::query()->count())->toBe(0);
});

test('inactive delivery area and location mapping reject the accepted offer', function () {
    $fixture = submissionFixture($this, 'bank_transfer');
    enableSubmissionForTest();
    $fixture['area']->update(['is_active' => false]);
    $this->postJson('/api/storefront/v1/orders', submissionBody($fixture), submissionHeaders($fixture))
        ->assertStatus(409)->assertJsonPath('error.code', 'OFFER_STALE');
    $fixture['area']->update(['is_active' => true]);
    WebLocationDeliveryArea::query()->where('delivery_area_id', $fixture['area']->id)->update(['is_active' => false]);
    $this->postJson('/api/storefront/v1/orders', submissionBody($fixture), submissionHeaders($fixture))
        ->assertStatus(409)->assertJsonPath('error.code', 'OFFER_STALE');
    expect(WebOrder::query()->count())->toBe(0);
});

test('submission rejects a changed or legacy missing fulfillment mode without creating an order', function () {
    $fixture = submissionFixture($this);
    enableSubmissionForTest();
    $offer = WebOrderOffer::query()->where('reference', $fixture['offer']->json('data.reference'))->firstOrFail();
    $offer->update(['fulfillment_mode' => 'home_delivery']);
    $this->postJson('/api/storefront/v1/orders', submissionBody($fixture), submissionHeaders($fixture))
        ->assertUnprocessable()->assertJsonPath('error.code', 'FULFILLMENT_MODE_UNAVAILABLE');
    $offer->update(['fulfillment_mode' => null]);
    $this->postJson('/api/storefront/v1/orders', submissionBody($fixture), submissionHeaders($fixture))
        ->assertUnprocessable()->assertJsonPath('error.code', 'FULFILLMENT_MODE_UNAVAILABLE');
    expect(WebOrder::query()->count())->toBe(0);
});

test('insufficient stock after offer rejects the entire order', function () {
    $fixture = submissionFixture($this);
    enableSubmissionForTest();
    InventoryStock::query()->where('product_variant_id', $fixture['variant']->id)
        ->update(['quantity_on_hand' => '0.000']);
    $this->postJson('/api/storefront/v1/orders', submissionBody($fixture), submissionHeaders($fixture))
        ->assertStatus(409);
    expect(WebOrder::query()->count())->toBe(0);
    expect(DB::table('web_order_reservations')->count())->toBe(0);
});

test('a different submission key cannot reuse an already submitted offer', function () {
    $fixture = submissionFixture($this);
    enableSubmissionForTest();
    $this->postJson('/api/storefront/v1/orders', submissionBody($fixture), submissionHeaders($fixture))->assertCreated();
    $this->postJson('/api/storefront/v1/orders', submissionBody($fixture), submissionHeaders($fixture))
        ->assertStatus(409)->assertJsonPath('error.code', 'OFFER_UNAVAILABLE');
    expect(WebOrder::query()->count())->toBe(1);
    expect(DB::table('web_order_reservations')->count())->toBe(1);
});

test('another guest token cannot submit or replay an accepted offer', function () {
    $fixture = submissionFixture($this);
    enableSubmissionForTest();
    $other = $this->postJson('/api/storefront/v1/cart/items', ['product_id' => $fixture['product']->id,
        'variant_id' => $fixture['variant']->id, 'quantity' => 1])->assertCreated();
    $headers = submissionHeaders($fixture);
    $headers['X-Storefront-Cart-Token'] = $other->headers->get('X-Storefront-Cart-Token');
    $this->postJson('/api/storefront/v1/orders', submissionBody($fixture), $headers)
        ->assertNotFound()->assertJsonPath('error.code', 'OFFER_NOT_FOUND');
    $original = submissionHeaders($fixture, $headers['Idempotency-Key']);
    $this->postJson('/api/storefront/v1/orders', submissionBody($fixture), $original)->assertCreated();
    $this->postJson('/api/storefront/v1/orders', submissionBody($fixture), $headers)
        ->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
    expect(WebOrder::query()->count())->toBe(1);
});

test('accepted offer rejects a cart changed after the offer was created', function () {
    $fixture = submissionFixture($this);
    enableSubmissionForTest();
    $this->postJson('/api/storefront/v1/cart/items', ['product_id' => $fixture['product']->id,
        'variant_id' => $fixture['variant']->id, 'quantity' => 1],
        ['X-Storefront-Cart-Token' => $fixture['token']])->assertCreated();
    $this->postJson('/api/storefront/v1/orders', submissionBody($fixture), submissionHeaders($fixture))
        ->assertStatus(409)->assertJsonPath('error.code', 'OFFER_STALE');
    expect(DB::table('web_order_reservations')->count())->toBe(0);
});

test('exact used device is reserved privately at its accepted price', function () {
    $fixture = submissionFixture($this, 'cod', true);
    enableSubmissionForTest();
    $response = $this->postJson('/api/storefront/v1/orders', submissionBody($fixture), submissionHeaders($fixture))
        ->assertCreated()->assertJsonPath('data.items.0.unit_price', '900.00');
    expect($response->getContent())->not->toContain($fixture['device']->imei, 'serialized_device_id', 'private_allocation');
    expect(DB::table('web_order_reservations')->where('active_device_id', $fixture['device']->id)->count())->toBe(1);
    expect($fixture['device']->fresh()->status->value)->toBe('in_stock');
});

test('changed used device price or condition requires a new accepted offer', function () {
    $fixture = submissionFixture($this, 'cod', true);
    enableSubmissionForTest();
    $fixture['device']->update(['unit_selling_price' => '950.00']);
    $this->postJson('/api/storefront/v1/orders', submissionBody($fixture), submissionHeaders($fixture))
        ->assertStatus(409)->assertJsonPath('error.code', 'DEVICE_UNAVAILABLE');
    $fixture['device']->update(['unit_selling_price' => '900.00', 'condition' => ProductCondition::Refurbished]);
    $this->postJson('/api/storefront/v1/orders', submissionBody($fixture), submissionHeaders($fixture))
        ->assertStatus(409)->assertJsonPath('error.code', 'DEVICE_UNAVAILABLE');
    expect(WebOrder::query()->count())->toBe(0);
    expect(DB::table('web_order_reservations')->count())->toBe(0);
});

test('a sold exact device cannot be substituted at order submission', function () {
    $fixture = submissionFixture($this, 'cod', true);
    enableSubmissionForTest();
    $fixture['device']->update(['status' => 'sold']);
    $this->postJson('/api/storefront/v1/orders', submissionBody($fixture), submissionHeaders($fixture))
        ->assertStatus(409);
    expect(WebOrder::query()->count())->toBe(0);
});

test('disabled pickup mapping makes an accepted offer stale without creating an order', function () {
    $fixture = submissionFixture($this);
    enableSubmissionForTest();
    WebPickupLocation::query()->where('business_id', $fixture['business']->id)
        ->where('location_id', $fixture['location']->id)->update(['is_active' => false]);
    $this->postJson('/api/storefront/v1/orders', submissionBody($fixture), submissionHeaders($fixture))
        ->assertStatus(409)->assertJsonPath('error.code', 'OFFER_STALE');
    expect(WebOrder::query()->count())->toBe(0);
    expect(DB::table('web_order_reservations')->count())->toBe(0);
});

test('accepted pickup address and name remain immutable after shop edits', function () {
    $fixture = submissionFixture($this);
    enableSubmissionForTest();
    $accepted = $this->postJson('/api/storefront/v1/orders', submissionBody($fixture), submissionHeaders($fixture))
        ->assertCreated();
    $order = WebOrder::query()->where('reference', $accepted->json('data.reference'))->sole();
    $name = $order->pickup_location_name_snapshot;
    $address = $order->pickup_location_address_snapshot;
    $fixture['location']->update(['name' => 'Renamed shop', 'address' => 'Different street']);
    WebPickupLocation::query()->where('location_id', $fixture['location']->id)->update(['is_active' => false]);
    expect($order->fresh()->pickup_location_name_snapshot)->toBe($name)
        ->and($order->fresh()->pickup_location_address_snapshot)->toBe($address);
    expect(DB::table('web_order_offers')->where('id', $order->web_order_offer_id)->value('pickup_location_address_snapshot'))->toBe($address);
});

test('reservation failure rolls back order payment history and items', function () {
    $fixture = submissionFixture($this);
    config()->set('storefront.order_submission_enabled', true);
    config()->set('storefront.reservation_ttl_minutes', 30);
    config()->set('storefront.order_access_key', str_repeat('k', 32));
    $this->partialMock(WebReservationService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('reserveComponent')->once()->andThrow(new RuntimeException('injected reservation failure'));
    });
    $this->withoutExceptionHandling();
    $thrown = false;
    try {
        $this->postJson('/api/storefront/v1/orders', submissionBody($fixture), submissionHeaders($fixture));
    } catch (RuntimeException $exception) {
        $thrown = true;
        expect($exception->getMessage())->toBe('injected reservation failure');
    }
    expect($thrown)->toBeTrue();
    expect(WebOrder::query()->count())->toBe(0);
    expect(DB::table('web_order_items')->count())->toBe(0);
    expect(DB::table('web_order_status_histories')->count())->toBe(0);
    expect(DB::table('web_order_payments')->count())->toBe(0);
    expect(DB::table('web_order_reservations')->count())->toBe(0);
});

test('failure on the last allocation rolls back the earlier reservation and complete order', function () {
    $fixture = submissionFixture($this, 'cod', false, true);
    enableSubmissionForTest();
    app()->bind(WebReservationService::class, fn () => new class extends WebReservationService
    {
        private int $calls = 0;

        public function reserveComponent(WebOrder $order, int $itemId, int $componentVariantId, string $quantity, string $lineKey, ?int $deviceId = null): WebOrderReservation
        {
            $this->calls++;
            if ($this->calls === 2) {
                throw new RuntimeException('injected last allocation failure');
            }

            return parent::reserveComponent($order, $itemId, $componentVariantId, $quantity, $lineKey, $deviceId);
        }
    });
    $this->withoutExceptionHandling();
    $thrown = false;
    try {
        $this->postJson('/api/storefront/v1/orders', submissionBody($fixture), submissionHeaders($fixture));
    } catch (RuntimeException $exception) {
        $thrown = true;
        expect($exception->getMessage())->toBe('injected last allocation failure');
    }
    expect($thrown)->toBeTrue();
    expect(WebOrder::query()->count())->toBe(0);
    expect(DB::table('web_order_items')->count())->toBe(0);
    expect(DB::table('web_order_payments')->count())->toBe(0);
    expect(DB::table('web_order_reservations')->count())->toBe(0);
});
