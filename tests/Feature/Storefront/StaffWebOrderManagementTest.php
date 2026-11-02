<?php

use App\Models\Business;
use App\Models\Category;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SerializedDevice;
use App\Models\User;
use App\Models\WebBankTransferAttempt;
use App\Models\WebBankTransferDecision;
use App\Models\WebDeliveryArea;
use App\Models\WebLocationDeliveryArea;
use App\Models\WebOrder;
use App\Models\WebPickupLocation;
use App\Models\WebProductListing;
use App\ProductCondition;
use App\Services\WebReservationService;
use App\VariantTrackingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

function staffOrderFixture($test, string $method = 'cod', bool $used = false, bool $combo = false): array
{
    config()->set('storefront.offer_ttl_minutes', 20);
    config()->set('storefront.cod_confirmation_ttl_minutes', 20);
    config()->set('storefront.bank_proof_ttl_minutes', 20);
    config()->set('storefront.order_submission_enabled', true);
    config()->set('storefront.reservation_ttl_minutes', 30);
    config()->set('storefront.order_access_key', str_repeat('k', 32));

    $owner = User::factory()->create();
    $business = Business::factory()->for($owner, 'owner')->create(['setup_key' => 'initial']);
    $location = Location::factory()->for($business)->create(['type' => 'shop']);
    WebPickupLocation::query()->create(['business_id' => $business->id, 'location_id' => $location->id,
        'public_code' => $location->code, 'is_active' => true]);
    $category = Category::factory()->for($business)->create();
    $product = Product::factory()->for($business)->for($category)->create([
        'name' => 'Phone', 'product_type' => $combo ? 'combo' : ($used ? 'serialized' : 'simple'),
    ]);
    $variant = ProductVariant::factory()->for($business)->for($product)->create([
        'name' => '128 GB', 'selling_price' => '1000.00', 'cost_price' => '500.00',
        'condition' => $used ? ProductCondition::Used : ProductCondition::New,
        'tracking_mode' => $used ? VariantTrackingMode::Serialized : VariantTrackingMode::QuantityBased,
    ]);
    WebProductListing::query()->create(['business_id' => $business->id, 'product_id' => $product->id,
        'slug' => 'phone', 'status' => 'published']);
    $componentVariant = null;
    if ($combo) {
        $componentProduct = Product::factory()->for($business)->for($category)->create(['name' => 'Shared charger', 'product_type' => 'simple']);
        $componentVariant = ProductVariant::factory()->for($business)->for($componentProduct)->create([
            'name' => 'USB-C', 'selling_price' => '300.00', 'cost_price' => '100.00',
        ]);
        $variant->bundleComponents()->create(['business_id' => $business->id,
            'component_variant_id' => $componentVariant->id, 'quantity' => '2.000']);
        WebProductListing::query()->create(['business_id' => $business->id, 'product_id' => $componentProduct->id,
            'slug' => 'shared-charger', 'status' => 'published']);
        InventoryStock::factory()->for($business)->for($location)->for($componentVariant, 'variant')
            ->create(['quantity_on_hand' => '5.000']);
    } else {
        InventoryStock::factory()->for($business)->for($location)->for($variant, 'variant')
            ->create(['quantity_on_hand' => '5.000']);
    }
    $device = $used ? SerializedDevice::factory()->for($location)->for($variant, 'variant')->create([
        'business_id' => $business->id, 'condition' => ProductCondition::Used, 'unit_selling_price' => '900.00',
    ]) : null;
    $area = WebDeliveryArea::query()->create(['business_id' => $business->id, 'code' => 'central',
        'name' => 'Central', 'base_delivery_fee' => '250.00', 'currency' => $business->currency, 'is_active' => true]);
    WebLocationDeliveryArea::query()->create(['business_id' => $business->id, 'delivery_area_id' => $area->id,
        'location_id' => $location->id, 'selection_priority' => 1, 'is_active' => true]);
    $added = $test->postJson('/api/storefront/v1/cart/items', ['product_id' => $product->id,
        'variant_id' => $variant->id, 'quantity' => 1])->assertCreated();
    $token = $added->headers->get('X-Storefront-Cart-Token');
    $headers = ['X-Storefront-Cart-Token' => $token];
    if ($combo) {
        $test->postJson('/api/storefront/v1/cart/items', ['product_id' => $componentProduct->id,
            'variant_id' => $componentVariant->id, 'quantity' => 1], $headers)->assertCreated();
    }
    $quote = $test->postJson('/api/storefront/v1/quotes', [], $headers)->assertOk();
    $offer = $test->postJson('/api/storefront/v1/order-offers', [
        'quote_reference' => $quote->json('data.reference'),
        'fulfillment_mode' => $method === 'cod' ? 'shop_pickup' : 'home_delivery',
        ...($method === 'cod' ? ['pickup_location_code' => $location->code] : ['delivery_area_code' => 'central']),
        'payment_method' => $method, 'contact' => ['name' => 'Customer', 'phone' => '0771234567'],
        ...($method === 'cod' ? [] : ['address' => ['line1' => 'Main Street', 'city' => 'Colombo']]),
    ], $headers)->assertCreated();
    $submitted = $test->postJson('/api/storefront/v1/orders', [
        'offer_reference' => $offer->json('data.reference'),
        'accepted_terms_digest' => $offer->json('data.terms_digest'), 'accepted' => true,
    ], [...$headers, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    $order = WebOrder::query()->where('reference', $submitted->json('data.reference'))->firstOrFail();

    return compact('business', 'owner', 'location', 'product', 'variant', 'componentVariant', 'device', 'order', 'submitted');
}

function staffWithPermissions(array $permissions, ?Location $location = null): User
{
    $staff = User::factory()->create();
    foreach ($permissions as $name) {
        $staff->givePermissionTo(Permission::findOrCreate($name, 'web'));
    }
    if ($location !== null) {
        $location->users()->attach($staff->id);
    }

    return $staff;
}

function verifyBankPaymentForStaffTest(WebOrder $order, User $reviewer, string $decision = 'verified'): void
{
    $payment = DB::table('web_order_payments')->where('web_order_id', $order->id)->first();
    $attempt = WebBankTransferAttempt::query()->create([
        'business_id' => $order->business_id, 'web_order_id' => $order->id,
        'web_order_payment_id' => $payment->id, 'submission_key' => (string) Str::uuid(),
        'payload_hash' => hash('sha256', (string) Str::uuid()), 'transfer_reference' => 'BANK-REF',
        'evidence_path' => 'web-bank-transfer-proofs/private.jpg', 'evidence_mime' => 'image/jpeg',
        'evidence_size' => 100, 'status' => $decision, 'submitted_at' => now(),
    ]);
    WebBankTransferDecision::query()->create([
        'business_id' => $order->business_id, 'web_order_id' => $order->id,
        'web_bank_transfer_attempt_id' => $attempt->id, 'actor_user_id' => $reviewer->id,
        'actor_name_snapshot' => $reviewer->name, 'decision' => $decision,
        'received_amount' => $decision === 'verified' ? $payment->amount_due : '0.00',
        'internal_note' => null, 'reviewed_at' => now(),
    ]);
    $status = $decision === 'verified' ? 'paid_verified' : 'transfer_awaiting_verification';
    DB::table('web_order_payments')->where('id', $payment->id)->update(['status' => $status]);
    $order->update(['payment_status' => $status]);
}

test('order management requires authentication permission and assigned location', function () {
    $fixture = staffOrderFixture($this);
    $url = '/storefront/orders/'.$fixture['order']->reference;
    $this->get('/storefront/orders')->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->get('/storefront/orders')->assertForbidden();
    $viewer = staffWithPermissions(['storefront.view-orders']);
    $this->actingAs($viewer)->get('/storefront/orders')->assertOk()->assertInertia(fn ($page) => $page
        ->component('storefront/orders/Index')->where('orders.total', 0));
    $this->get($url)->assertNotFound();
    $fixture['location']->users()->attach($viewer->id);
    $this->get('/storefront/orders?search=0771234567')->assertOk()->assertInertia(fn ($page) => $page
        ->component('storefront/orders/Index')->where('orders.total', 1));
    $this->get($url)->assertOk()->assertInertia(fn ($page) => $page
        ->component('storefront/orders/Show')->where('order.total', '1000.00'));
    $this->post($url.'/confirm-cod')->assertForbidden();
});

test('staff confirmation requires the latest customer-confirmed call and keeps COD unpaid', function () {
    $fixture = staffOrderFixture($this);
    expect($fixture['order']->reservations()->firstOrFail()->expires_at->toDateTimeString())
        ->toBeGreaterThan(now()->toDateTimeString());
    $staff = staffWithPermissions(['storefront.view-orders', 'storefront.confirm-orders'], $fixture['location']);
    $url = '/storefront/orders/'.$fixture['order']->reference;
    $this->actingAs($staff)->post($url.'/confirm-cod')->assertSessionHasErrors('call');
    $this->post($url.'/calls', ['outcome' => 'no_answer'])->assertRedirect();
    $this->post($url.'/confirm-cod')->assertSessionHasErrors('call');
    $this->post($url.'/calls', ['outcome' => 'request_callback'])->assertRedirect();
    expect($fixture['order']->fresh()->status)->toBe('pending_confirmation');
    $this->post($url.'/calls', ['outcome' => 'answered_confirmed', 'internal_note' => 'Agreed to exact amount'])
        ->assertRedirect();
    $this->post($url.'/confirm-cod')->assertRedirect();
    $this->post($url.'/confirm-cod')->assertRedirect();
    expect($fixture['order']->fresh()->status)->toBe('confirmed');
    expect($fixture['order']->fresh()->payment_status)->toBe('cod_due');
    expect(DB::table('web_order_status_histories')->where('web_order_id', $fixture['order']->id)
        ->where('to_status', 'confirmed')->count())->toBe(1);
    expect(DB::table('web_order_reservations')->where('web_order_id', $fixture['order']->id)
        ->where('status', 'active')->count())->toBe(1);
    expect(DB::table('web_order_status_histories')->where('web_order_id', $fixture['order']->id)
        ->where('to_status', 'confirmed')->value('actor_id'))->toBe($staff->id);
    expect(DB::table('web_order_call_attempts')->where('web_order_id', $fixture['order']->id)
        ->where('outcome', 'answered_confirmed')->value('actor_user_id'))->toBe($staff->id);
    expect($fixture['variant']->fresh()->selling_price)->toBe('1000.00');
    $fixture['variant']->update(['selling_price' => '1500.00']);
    $this->get($url)->assertOk()->assertInertia(fn ($page) => $page
        ->where('order.total', '1000.00')->where('order.items.0.unit_price', '1000.00'));
    $tracking = $this->getJson('/api/storefront/v1/orders/'.$fixture['order']->reference,
        ['X-Storefront-Order-Credential' => $fixture['submitted']->headers->get('X-Storefront-Order-Credential')])->assertOk();
    expect($tracking->getContent())->not->toContain('Agreed to exact amount');
});

test('declined COD cancellation releases reservations but needs distinct cancel permission', function () {
    $fixture = staffOrderFixture($this);
    $staff = staffWithPermissions(['storefront.view-orders', 'storefront.confirm-orders'], $fixture['location']);
    $url = '/storefront/orders/'.$fixture['order']->reference;
    $this->actingAs($staff)->post($url.'/calls', ['outcome' => 'customer_declined',
        'internal_note' => 'Customer declined the agreed amount'])->assertRedirect();
    $this->post($url.'/cancel-declined')->assertForbidden();
    $staff->givePermissionTo(Permission::findOrCreate('storefront.cancel-orders', 'web'));
    $this->post($url.'/cancel-declined')->assertRedirect();
    $this->post($url.'/cancel-declined')->assertRedirect();
    expect($fixture['order']->fresh()->status)->toBe('cancelled');
    expect(DB::table('web_order_reservations')->where('web_order_id', $fixture['order']->id)
        ->where('status', 'released')->count())->toBe(1);
    expect(DB::table('web_order_status_histories')->where('web_order_id', $fixture['order']->id)
        ->where('to_status', 'cancelled')->count())->toBe(1);
});

test('bank transfer is visible but cannot use COD calls or confirmation', function () {
    $fixture = staffOrderFixture($this, 'bank_transfer');
    $staff = staffWithPermissions(['storefront.view-orders', 'storefront.confirm-orders', 'storefront.cancel-orders'], $fixture['location']);
    $url = '/storefront/orders/'.$fixture['order']->reference;
    $this->actingAs($staff)->get($url)->assertOk()->assertInertia(fn ($page) => $page
        ->where('order.payment_status', 'transfer_awaiting_proof')->where('can_confirm', false));
    $this->post($url.'/calls', ['outcome' => 'answered_confirmed'])->assertSessionHasErrors('order');
    $this->post($url.'/confirm-cod')->assertSessionHasErrors('order');
    $this->post($url.'/cancel-declined')->assertSessionHasErrors('order');
    expect($fixture['order']->fresh()->payment_status)->toBe('transfer_awaiting_proof');
});

test('orders from another business are not listed or shown', function () {
    $fixture = staffOrderFixture($this);
    $other = Business::factory()->for(User::factory()->create(), 'owner')->create(['setup_key' => 'other']);
    $otherLocation = Location::factory()->for($other)->create();
    $otherOrder = WebOrder::query()->create([
        'business_id' => $other->id, 'location_id' => $otherLocation->id,
        'reference' => (string) Str::ulid(), 'submission_key' => (string) Str::uuid(),
        'payload_hash' => hash('sha256', 'other'), 'status' => 'pending_confirmation',
        'payment_method' => 'cod', 'payment_status' => 'cod_due', 'currency' => $other->currency,
        'subtotal' => '1.00', 'delivery_fee' => '0.00', 'total' => '1.00',
    ]);
    $staff = staffWithPermissions(['storefront.view-orders'], $fixture['location']);
    $this->actingAs($staff)->get('/storefront/orders')->assertOk()->assertInertia(fn ($page) => $page
        ->where('orders.total', 1));
    $this->get('/storefront/orders/'.$otherOrder->reference)->assertNotFound();
});

test('an expired or released allocation cannot be confirmed', function () {
    $fixture = staffOrderFixture($this);
    $staff = staffWithPermissions(['storefront.view-orders', 'storefront.confirm-orders'], $fixture['location']);
    $url = '/storefront/orders/'.$fixture['order']->reference;
    $this->actingAs($staff)->post($url.'/calls', ['outcome' => 'answered_confirmed'])->assertRedirect();
    DB::table('web_order_reservations')->where('web_order_id', $fixture['order']->id)
        ->update(['status' => 'released']);
    $this->post($url.'/confirm-cod')->assertSessionHasErrors('reservation');
    expect($fixture['order']->fresh()->status)->toBe('pending_confirmation');
});

test('a failed reservation release leaves COD order and allocation unchanged', function () {
    $fixture = staffOrderFixture($this);
    $staff = staffWithPermissions(['storefront.view-orders', 'storefront.confirm-orders', 'storefront.cancel-orders'], $fixture['location']);
    $url = '/storefront/orders/'.$fixture['order']->reference;
    $this->actingAs($staff)->post($url.'/calls', ['outcome' => 'customer_declined',
        'internal_note' => 'Customer declined'])->assertRedirect();
    $this->partialMock(WebReservationService::class, function ($mock): void {
        $mock->shouldReceive('release')->once()->andThrow(ValidationException::withMessages([
            'reservation' => 'Injected release failure',
        ]));
    });
    $this->post($url.'/cancel-declined')->assertSessionHasErrors('reservation');
    expect($fixture['order']->fresh()->status)->toBe('pending_confirmation');
    expect(DB::table('web_order_reservations')->where('web_order_id', $fixture['order']->id)
        ->where('status', 'active')->count())->toBe(1);
});

test('declined COD cancellation refuses a payment obligation needing reconciliation', function () {
    $fixture = staffOrderFixture($this);
    $staff = staffWithPermissions(['storefront.view-orders', 'storefront.confirm-orders', 'storefront.cancel-orders'], $fixture['location']);
    $url = '/storefront/orders/'.$fixture['order']->reference;
    $this->actingAs($staff)->post($url.'/calls', ['outcome' => 'customer_declined',
        'internal_note' => 'Customer declined'])->assertRedirect();
    DB::table('web_order_payments')->where('web_order_id', $fixture['order']->id)->update(['status' => 'paid_collected']);
    $this->post($url.'/cancel-declined')->assertSessionHasErrors('order');
    expect($fixture['order']->fresh()->status)->toBe('pending_confirmation');
    expect(DB::table('web_order_reservations')->where('web_order_id', $fixture['order']->id)
        ->where('status', 'active')->count())->toBe(1);
});

test('confirmed COD preparation and complete pick checks reach ready without posting stock or sales', function () {
    $fixture = staffOrderFixture($this);
    $staff = staffWithPermissions(['storefront.view-orders', 'storefront.confirm-orders',
        'storefront.prepare-orders', 'storefront.verify-picks', 'storefront.mark-ready'], $fixture['location']);
    $base = '/storefront/orders/'.$fixture['order']->reference;
    $this->actingAs($staff)->post($base.'/calls', ['outcome' => 'answered_confirmed'])->assertRedirect();
    $this->post($base.'/confirm-cod')->assertRedirect();
    $this->post($base.'/preparation/start')->assertRedirect();
    $this->post($base.'/preparation/start')->assertRedirect();
    $reservation = DB::table('web_order_reservations')->where('web_order_id', $fixture['order']->id)->first();
    $this->post($base.'/preparation/ready')->assertSessionHasErrors('order');
    $this->post($base.'/preparation/checks/'.$reservation->id, ['checked_quantity' => '1.000'])->assertRedirect();
    $this->post($base.'/preparation/ready')->assertRedirect();
    $this->post($base.'/preparation/ready')->assertRedirect();
    expect($fixture['order']->fresh()->status)->toBe('ready');
    expect(DB::table('web_order_status_histories')->where('web_order_id', $fixture['order']->id)
        ->where('to_status', 'ready')->count())->toBe(1);
    expect(DB::table('web_order_reservations')->where('id', $reservation->id)->value('status'))->toBe('active');
    expect(InventoryStock::query()->where('product_variant_id', $fixture['variant']->id)->value('quantity_on_hand'))->toBe('5.000');
    expect(DB::table('sales')->count())->toBe(0);
    expect(DB::table('sale_payments')->count())->toBe(0);
    $tracking = $this->getJson('/api/storefront/v1/orders/'.$fixture['order']->reference,
        ['X-Storefront-Order-Credential' => $fixture['submitted']->headers->get('X-Storefront-Order-Credential')])->assertOk();
    expect($tracking->getContent())->not->toContain('web_order_fulfillment_checks');
    expect($tracking->getContent())->not->toContain('performed_by');
    expect($tracking->getContent())->not->toContain('internal_note');
});

test('preparation requires confirmation call permission and assigned location', function () {
    $fixture = staffOrderFixture($this);
    $base = '/storefront/orders/'.$fixture['order']->reference;
    $staff = staffWithPermissions(['storefront.view-orders'], $fixture['location']);
    $this->actingAs($staff)->post($base.'/preparation/start')->assertForbidden();
    $staff->givePermissionTo(Permission::findOrCreate('storefront.prepare-orders', 'web'));
    $this->post($base.'/preparation/start')->assertSessionHasErrors('order');
    $fixture['order']->update(['status' => 'confirmed']);
    $this->post($base.'/preparation/start')->assertSessionHasErrors('order');
    $fixture['location']->users()->detach($staff->id);
    $this->post($base.'/preparation/start')->assertNotFound();
});

test('bank orders remain blocked from preparation even after payment verification', function () {
    $fixture = staffOrderFixture($this, 'bank_transfer');
    $staff = staffWithPermissions(['storefront.view-orders', 'storefront.prepare-orders'], $fixture['location']);
    $base = '/storefront/orders/'.$fixture['order']->reference;
    $this->actingAs($staff)->post($base.'/preparation/start')->assertSessionHasErrors('order');
    $fixture['order']->update(['status' => 'confirmed', 'payment_status' => 'paid_verified']);
    DB::table('web_order_payments')->where('web_order_id', $fixture['order']->id)->update(['status' => 'paid_verified']);
    $this->post($base.'/preparation/start')->assertSessionHasErrors('order');
    expect($fixture['order']->fresh()->status)->toBe('confirmed');
});

test('pick discrepancy is audited and blocks ready until a matching recheck', function () {
    $fixture = staffOrderFixture($this);
    $staff = staffWithPermissions(['storefront.view-orders', 'storefront.confirm-orders',
        'storefront.prepare-orders', 'storefront.verify-picks', 'storefront.mark-ready'], $fixture['location']);
    $base = '/storefront/orders/'.$fixture['order']->reference;
    $this->actingAs($staff)->post($base.'/calls', ['outcome' => 'answered_confirmed'])->assertRedirect();
    $this->post($base.'/confirm-cod')->assertRedirect();
    $this->post($base.'/preparation/start')->assertRedirect();
    $reservation = DB::table('web_order_reservations')->where('web_order_id', $fixture['order']->id)->first();
    $this->post($base.'/preparation/checks/'.$reservation->id, ['checked_quantity' => '0.000',
        'internal_note' => 'Item was not on the shelf'])->assertRedirect();
    $this->post($base.'/preparation/ready')->assertSessionHasErrors('order');
    $this->post($base.'/preparation/checks/'.$reservation->id, ['checked_quantity' => '1.000'])->assertRedirect();
    $this->post($base.'/preparation/ready')->assertRedirect();
    expect(DB::table('web_order_fulfillment_checks')->where('web_order_id', $fixture['order']->id)->count())->toBe(2);
    expect(DB::table('web_order_fulfillment_checks')->where('web_order_id', $fixture['order']->id)
        ->orderByDesc('id')->value('result'))->toBe('matched');
});

test('expired reservation blocks preparation without changing the order', function () {
    $fixture = staffOrderFixture($this);
    $staff = staffWithPermissions(['storefront.view-orders', 'storefront.confirm-orders', 'storefront.prepare-orders'], $fixture['location']);
    $base = '/storefront/orders/'.$fixture['order']->reference;
    $this->actingAs($staff)->post($base.'/calls', ['outcome' => 'answered_confirmed'])->assertRedirect();
    $this->post($base.'/confirm-cod')->assertRedirect();
    $fixture['order']->update(['reservation_expires_at' => now()->subMinute()]);
    $this->post($base.'/preparation/start')->assertSessionHasErrors('order');
    expect($fixture['order']->fresh()->status)->toBe('confirmed');
    expect(DB::table('web_order_status_histories')->where('web_order_id', $fixture['order']->id)
        ->where('to_status', 'preparing')->count())->toBe(0);
});

test('serialized picking requires the exact physical identifier and accepted condition', function () {
    $fixture = staffOrderFixture($this, 'cod', true);
    $staff = staffWithPermissions(['storefront.view-orders', 'storefront.confirm-orders',
        'storefront.prepare-orders', 'storefront.verify-picks', 'storefront.mark-ready'], $fixture['location']);
    $base = '/storefront/orders/'.$fixture['order']->reference;
    $this->actingAs($staff)->post($base.'/calls', ['outcome' => 'answered_confirmed'])->assertRedirect();
    $this->post($base.'/confirm-cod')->assertRedirect();
    $this->post($base.'/preparation/start')->assertRedirect();
    $reservation = DB::table('web_order_reservations')->where('web_order_id', $fixture['order']->id)->first();
    $this->post($base.'/preparation/checks/'.$reservation->id, [
        'checked_quantity' => '1.000', 'device_identifier' => '000000000000000',
        'observed_condition' => 'used', 'internal_note' => 'A different phone was picked',
    ])->assertRedirect();
    $this->post($base.'/preparation/ready')->assertSessionHasErrors('order');
    $this->post($base.'/preparation/checks/'.$reservation->id, [
        'checked_quantity' => '1.000', 'device_identifier' => $fixture['device']->imei,
        'observed_condition' => 'new', 'internal_note' => 'Observed condition did not match',
    ])->assertRedirect();
    $this->post($base.'/preparation/ready')->assertSessionHasErrors('order');
    $this->post($base.'/preparation/checks/'.$reservation->id, [
        'checked_quantity' => '1.000', 'device_identifier' => $fixture['device']->imei,
        'observed_condition' => 'used',
    ])->assertRedirect();
    $this->post($base.'/preparation/ready')->assertRedirect();
    expect($fixture['device']->fresh()->status->value)->toBe('in_stock');
    expect(DB::table('web_order_fulfillment_checks')->where('web_order_id', $fixture['order']->id)
        ->orderBy('id')->pluck('result')->all())->toBe(['discrepancy', 'discrepancy', 'matched']);
    $tracking = $this->getJson('/api/storefront/v1/orders/'.$fixture['order']->reference,
        ['X-Storefront-Order-Credential' => $fixture['submitted']->headers->get('X-Storefront-Order-Credential')])->assertOk();
    expect($tracking->getContent())->not->toContain($fixture['device']->imei);
    expect($tracking->getContent())->not->toContain('A different phone was picked');
});

test('combo and standalone lines sharing stock require separate matching component checks', function () {
    $fixture = staffOrderFixture($this, 'cod', false, true);
    $staff = staffWithPermissions(['storefront.view-orders', 'storefront.confirm-orders',
        'storefront.prepare-orders', 'storefront.verify-picks', 'storefront.mark-ready'], $fixture['location']);
    $base = '/storefront/orders/'.$fixture['order']->reference;
    $this->actingAs($staff)->post($base.'/calls', ['outcome' => 'answered_confirmed'])->assertRedirect();
    $this->post($base.'/confirm-cod')->assertRedirect();
    $this->post($base.'/preparation/start')->assertRedirect();
    $rows = DB::table('web_order_reservations')->where('web_order_id', $fixture['order']->id)->orderBy('id')->get();
    expect($rows)->toHaveCount(2);
    expect($rows->pluck('component_variant_id')->unique()->all())->toHaveCount(1);
    foreach ($rows as $row) {
        $this->post($base.'/preparation/checks/'.$row->id, ['checked_quantity' => (string) $row->quantity])->assertRedirect();
    }
    $this->post($base.'/preparation/ready')->assertRedirect();
    expect($fixture['order']->fresh()->status)->toBe('ready');
    expect(InventoryStock::query()->where('product_variant_id', $fixture['componentVariant']->id)->value('quantity_on_hand'))->toBe('5.000');
    expect(DB::table('web_order_reservations')->where('web_order_id', $fixture['order']->id)->where('status', 'active')->count())->toBe(2);
});

test('bank order confirmation requires exact verified payment and a separate authorized action', function () {
    $fixture = staffOrderFixture($this, 'bank_transfer');
    $staff = staffWithPermissions(['storefront.view-orders', 'storefront.view-payment-proofs',
        'storefront.confirm-bank-orders', 'storefront.prepare-orders', 'storefront.verify-picks',
        'storefront.mark-ready'], $fixture['location']);
    $base = '/storefront/orders/'.$fixture['order']->reference;
    $this->actingAs($staff)->post($base.'/confirm-bank')->assertSessionHasErrors('order');
    $this->post($base.'/preparation/start')->assertSessionHasErrors('order');
    verifyBankPaymentForStaffTest($fixture['order'], $staff);
    expect($fixture['order']->fresh()->status)->toBe('pending_confirmation');
    $tracking = $this->getJson('/api/storefront/v1/orders/'.$fixture['order']->reference,
        ['X-Storefront-Order-Credential' => $fixture['submitted']->headers->get('X-Storefront-Order-Credential')])->assertOk();
    expect($tracking->json('data.status'))->toBe('pending_confirmation');
    expect($tracking->json('data.payment_status'))->toBe('paid_verified');
    expect($tracking->json('data.status_message'))->toContain('Staff order confirmation is still pending');
    $this->post($base.'/preparation/start')->assertSessionHasErrors('order');
    $this->post($base.'/confirm-bank', ['internal_note' => 'Verified against bank statement'])->assertRedirect();
    $this->post($base.'/confirm-bank')->assertRedirect();
    expect($fixture['order']->fresh()->status)->toBe('confirmed');
    expect($fixture['order']->fresh()->payment_status)->toBe('paid_verified');
    expect(DB::table('web_order_payments')->where('web_order_id', $fixture['order']->id)->value('status'))->toBe('paid_verified');
    expect(DB::table('web_order_bank_confirmations')->where('web_order_id', $fixture['order']->id)->count())->toBe(1);
    expect(DB::table('web_order_status_histories')->where('web_order_id', $fixture['order']->id)
        ->where('reason_code', 'bank_order_confirmed')->count())->toBe(1);
    $tracking = $this->getJson('/api/storefront/v1/orders/'.$fixture['order']->reference,
        ['X-Storefront-Order-Credential' => $fixture['submitted']->headers->get('X-Storefront-Order-Credential')])->assertOk();
    expect($tracking->json('data.status'))->toBe('confirmed');
    expect($tracking->getContent())->not->toContain('Verified against bank statement');
    $this->post($base.'/preparation/start')->assertRedirect();
    expect($fixture['order']->fresh()->status)->toBe('preparing');
    $reservation = DB::table('web_order_reservations')->where('web_order_id', $fixture['order']->id)->first();
    $this->post($base.'/preparation/checks/'.$reservation->id, ['checked_quantity' => '1.000'])->assertRedirect();
    $this->post($base.'/preparation/ready')->assertRedirect();
    expect($fixture['order']->fresh()->status)->toBe('ready');
    expect(InventoryStock::query()->where('product_variant_id', $fixture['variant']->id)->value('quantity_on_hand'))->toBe('5.000');
    expect(DB::table('web_order_reservations')->where('web_order_id', $fixture['order']->id)->value('status'))->toBe('active');
    expect(DB::table('sales')->count())->toBe(0);
    expect(DB::table('sale_payments')->count())->toBe(0);
});

test('unverified and exceptional bank states cannot confirm the order', function () {
    $fixture = staffOrderFixture($this, 'bank_transfer');
    $staff = staffWithPermissions(['storefront.view-orders', 'storefront.view-payment-proofs',
        'storefront.confirm-bank-orders'], $fixture['location']);
    $base = '/storefront/orders/'.$fixture['order']->reference;
    $this->actingAs($staff);
    foreach (['transfer_awaiting_proof', 'transfer_awaiting_verification', 'underpaid', 'rejected'] as $state) {
        $fixture['order']->update(['payment_status' => $state]);
        DB::table('web_order_payments')->where('web_order_id', $fixture['order']->id)->update(['status' => $state]);
        $this->post($base.'/confirm-bank')->assertSessionHasErrors('order');
        expect($fixture['order']->fresh()->status)->toBe('pending_confirmation');
    }
});

test('bank confirmation rejects overpayment review and inconsistent verified evidence', function () {
    $fixture = staffOrderFixture($this, 'bank_transfer');
    $staff = staffWithPermissions(['storefront.view-orders', 'storefront.view-payment-proofs',
        'storefront.confirm-bank-orders'], $fixture['location']);
    $base = '/storefront/orders/'.$fixture['order']->reference;
    $fixture['order']->update(['payment_status' => 'paid_verified']);
    DB::table('web_order_payments')->where('web_order_id', $fixture['order']->id)->update(['status' => 'paid_verified']);
    $this->actingAs($staff)->post($base.'/confirm-bank')->assertSessionHasErrors('order');
    verifyBankPaymentForStaffTest($fixture['order'], $staff, 'overpayment_review');
    $fixture['order']->update(['payment_status' => 'paid_verified']);
    DB::table('web_order_payments')->where('web_order_id', $fixture['order']->id)->update(['status' => 'paid_verified']);
    $this->post($base.'/confirm-bank')->assertSessionHasErrors('order');
    expect(DB::table('web_order_bank_confirmations')->where('web_order_id', $fixture['order']->id)->count())->toBe(0);
});

test('bank confirmation requires its own permission and assigned fulfillment location', function () {
    $fixture = staffOrderFixture($this, 'bank_transfer');
    $staff = staffWithPermissions(['storefront.view-orders', 'storefront.view-payment-proofs'], $fixture['location']);
    verifyBankPaymentForStaffTest($fixture['order'], $staff);
    $base = '/storefront/orders/'.$fixture['order']->reference;
    $this->actingAs($staff)->post($base.'/confirm-bank')->assertForbidden();
    $staff->givePermissionTo(Permission::findOrCreate('storefront.confirm-bank-orders', 'web'));
    $fixture['location']->users()->detach($staff->id);
    $this->post($base.'/confirm-bank')->assertNotFound();
    $this->post($base.'/confirm-cod')->assertForbidden();
});

test('expired or incomplete bank reservations block confirmation without partial audit', function () {
    $fixture = staffOrderFixture($this, 'bank_transfer');
    $staff = staffWithPermissions(['storefront.view-orders', 'storefront.view-payment-proofs',
        'storefront.confirm-bank-orders'], $fixture['location']);
    verifyBankPaymentForStaffTest($fixture['order'], $staff);
    $base = '/storefront/orders/'.$fixture['order']->reference;
    $fixture['order']->update(['reservation_expires_at' => now()->subMinute()]);
    $this->actingAs($staff)->post($base.'/confirm-bank')->assertSessionHasErrors('reservation');
    $fixture['order']->update(['reservation_expires_at' => now()->addMinutes(10)]);
    DB::table('web_order_reservations')->where('web_order_id', $fixture['order']->id)->update(['status' => 'released']);
    $this->post($base.'/confirm-bank')->assertSessionHasErrors('reservation');
    expect($fixture['order']->fresh()->status)->toBe('pending_confirmation');
    expect(DB::table('web_order_bank_confirmations')->where('web_order_id', $fixture['order']->id)->count())->toBe(0);
});

test('a failed bank confirmation history write rolls back the order and private audit', function () {
    $fixture = staffOrderFixture($this, 'bank_transfer');
    $staff = staffWithPermissions(['storefront.view-orders', 'storefront.view-payment-proofs',
        'storefront.confirm-bank-orders'], $fixture['location']);
    verifyBankPaymentForStaffTest($fixture['order'], $staff);
    DB::listen(function ($query): void {
        if (str_contains(strtolower($query->sql), 'insert into `web_order_status_histories`')) {
            throw new RuntimeException('Injected history failure');
        }
    });
    $this->withoutExceptionHandling();
    expect(fn () => $this->actingAs($staff)->post('/storefront/orders/'.$fixture['order']->reference.'/confirm-bank'))
        ->toThrow(RuntimeException::class);
    expect($fixture['order']->fresh()->status)->toBe('pending_confirmation');
    expect(DB::table('web_order_bank_confirmations')->where('web_order_id', $fixture['order']->id)->count())->toBe(0);
    expect(DB::table('web_order_payments')->where('web_order_id', $fixture['order']->id)->value('status'))->toBe('paid_verified');
});
