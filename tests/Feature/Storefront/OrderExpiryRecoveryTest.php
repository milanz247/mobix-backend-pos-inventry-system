<?php

use App\Models\Business;
use App\Models\Category;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WebBankTransferAttempt;
use App\Models\WebDeliveryArea;
use App\Models\WebLocationDeliveryArea;
use App\Models\WebOrder;
use App\Models\WebOrderPayment;
use App\Models\WebPickupLocation;
use App\Models\WebProductListing;
use App\Services\WebOrderExpiryProcessor;
use App\Services\WebOrderRecoveryService;
use App\Services\WebReservationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function expiryFixture($test, string $method = 'cod'): array
{
    config()->set('storefront.offer_ttl_minutes', 20);
    config()->set('storefront.reservation_ttl_minutes', 30);
    config()->set('storefront.cod_confirmation_ttl_minutes', 20);
    config()->set('storefront.bank_proof_ttl_minutes', 20);
    config()->set('storefront.order_submission_enabled', true);
    config()->set('storefront.order_access_key', str_repeat('k', 32));
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner, 'owner')->create(['setup_key' => 'initial']);
    $location = Location::factory()->for($business)->create(['type' => 'shop']);
    WebPickupLocation::query()->create(['business_id' => $business->id, 'location_id' => $location->id,
        'public_code' => $location->code, 'is_active' => true]);
    $category = Category::factory()->for($business)->create();
    $product = Product::factory()->for($business)->for($category)->create();
    $variant = ProductVariant::factory()->for($business)->for($product)->create(['selling_price' => '1000.00']);
    WebProductListing::query()->create(['business_id' => $business->id, 'product_id' => $product->id,
        'slug' => 'phone', 'status' => 'published']);
    InventoryStock::factory()->for($business)->for($location)->for($variant, 'variant')->create(['quantity_on_hand' => '2.000']);
    $area = WebDeliveryArea::query()->create(['business_id' => $business->id, 'code' => 'central', 'name' => 'Central',
        'base_delivery_fee' => '250.00', 'currency' => $business->currency, 'is_active' => true]);
    WebLocationDeliveryArea::query()->create(['business_id' => $business->id, 'delivery_area_id' => $area->id,
        'location_id' => $location->id, 'selection_priority' => 1, 'is_active' => true]);
    $cart = $test->postJson('/api/storefront/v1/cart/items', ['product_id' => $product->id,
        'variant_id' => $variant->id, 'quantity' => 1])->assertCreated();
    $headers = ['X-Storefront-Cart-Token' => $cart->headers->get('X-Storefront-Cart-Token')];
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

    return compact('business', 'owner', 'location', 'variant', 'order');
}

test('pending COD expires only after its snapshotted confirmation deadline and leaves payment unpaid', function () {
    $fixture = expiryFixture($this);
    $order = $fixture['order'];
    $service = app(WebOrderExpiryProcessor::class);
    expect($service->process($order->business_id, $order->id)['outcome'])->toBe('skipped');
    $order->update(['cod_confirmation_deadline_at' => now()->subMinute()]);
    $result = $service->process($order->business_id, $order->id);
    expect($result)->toBe(['outcome' => 'expired', 'reservations_released' => 1]);
    expect($service->process($order->business_id, $order->id)['outcome'])->toBe('skipped');
    expect($order->fresh()->status)->toBe('expired');
    expect($order->fresh()->payment_status)->toBe('cod_due');
    expect(DB::table('web_order_reservations')->where('web_order_id', $order->id)->value('status'))->toBe('expired');
    expect(DB::table('web_order_status_histories')->where('web_order_id', $order->id)
        ->where('to_status', 'expired')->value('reason_code'))->toBe('cod_confirmation_deadline');
    expect(InventoryStock::query()->where('product_variant_id', $fixture['variant']->id)->value('quantity_on_hand'))->toBe('2.000');
});

test('confirmed COD and missing policy deadlines never trigger automatic expiry', function () {
    $fixture = expiryFixture($this);
    $order = $fixture['order'];
    $order->update(['status' => 'confirmed', 'cod_confirmation_deadline_at' => now()->subMinute()]);
    expect(app(WebReservationService::class)->expire($order))->toBeFalse();
    expect($order->fresh()->status)->toBe('confirmed');
    $order->update(['status' => 'pending_confirmation', 'cod_confirmation_deadline_at' => null,
        'reservation_expires_at' => now()->subMinute()]);
    expect(app(WebReservationService::class)->expire($order))->toBeFalse();
    expect(app(WebOrderExpiryProcessor::class)->process($order->business_id, $order->id)['outcome'])->toBe('manual_review');
    expect($order->fresh()->status)->toBe('pending_confirmation');
});

test('no-proof bank order can expire but submitted evidence and paid states require review', function () {
    $fixture = expiryFixture($this, 'bank_transfer');
    $order = $fixture['order'];
    $order->update(['bank_proof_deadline_at' => now()->subMinute()]);
    expect(app(WebOrderExpiryProcessor::class)->process($order->business_id, $order->id)['outcome'])->toBe('expired');
    expect($order->fresh()->payment_status)->toBe('transfer_awaiting_proof');
});

test('bank evidence and underpayment block expiry without deleting proof history', function () {
    $fixture = expiryFixture($this, 'bank_transfer');
    $order = $fixture['order'];
    $payment = WebOrderPayment::query()->where('web_order_id', $order->id)->firstOrFail();
    $order->update(['bank_proof_deadline_at' => now()->subMinute(), 'payment_status' => 'transfer_awaiting_verification']);
    $payment->update(['status' => 'transfer_awaiting_verification']);
    WebBankTransferAttempt::query()->create([
        'business_id' => $order->business_id, 'web_order_id' => $order->id, 'web_order_payment_id' => $payment->id,
        'submission_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', 'proof'),
        'transfer_reference' => 'REF', 'evidence_path' => 'web-bank-transfer-proofs/private.jpg',
        'evidence_mime' => 'image/jpeg', 'evidence_size' => 100, 'submitted_at' => now(),
    ]);
    expect(app(WebOrderExpiryProcessor::class)->process($order->business_id, $order->id)['outcome'])->toBe('manual_review');
    expect($order->fresh()->status)->toBe('pending_confirmation');
    $order->update(['payment_status' => 'underpaid']);
    $payment->update(['status' => 'underpaid']);
    expect(app(WebOrderExpiryProcessor::class)->process($order->business_id, $order->id)['outcome'])->toBe('manual_review');
    $order->update(['payment_status' => 'paid_verified']);
    $payment->update(['status' => 'paid_verified']);
    expect(app(WebReservationService::class)->expire($order))->toBeFalse();
    expect(DB::table('web_bank_transfer_attempts')->count())->toBe(1);
    expect(DB::table('web_order_reservations')->where('web_order_id', $order->id)->value('status'))->toBe('active');
});

test('diagnostics report terminal active and confirmed expired reservations without repairing stock', function () {
    $fixture = expiryFixture($this);
    $order = $fixture['order'];
    $recovery = app(WebOrderRecoveryService::class);
    $order->update(['status' => 'cancelled']);
    expect($recovery->issues($order->fresh()))->toContain('terminal_order_has_active_reservations');
    $order->update(['status' => 'confirmed']);
    DB::table('web_order_reservations')->where('web_order_id', $order->id)->update([
        'status' => 'expired', 'active_device_id' => null, 'transitioned_at' => now(),
    ]);
    expect($recovery->issues($order->fresh()))->toContain('confirmed_order_has_expired_reservation');
    expect(InventoryStock::query()->where('product_variant_id', $fixture['variant']->id)->value('quantity_on_hand'))->toBe('2.000');
});

test('a consumed reservation is not converted to expired by a delayed job', function () {
    $fixture = expiryFixture($this);
    $order = $fixture['order'];
    $order->update(['cod_confirmation_deadline_at' => now()->subMinute()]);
    DB::table('web_order_reservations')->where('web_order_id', $order->id)->update([
        'status' => 'consumed', 'active_device_id' => null, 'transitioned_at' => now(),
    ]);
    expect(app(WebReservationService::class)->expire($order))->toBeFalse();
    expect(app(WebOrderExpiryProcessor::class)->process($order->business_id, $order->id)['outcome'])->toBe('manual_review');
    expect($order->fresh()->status)->toBe('pending_confirmation');
    expect(DB::table('web_order_reservations')->where('web_order_id', $order->id)->value('status'))->toBe('consumed');
});

test('an expiry history insertion failure rolls back the reservation transition', function () {
    $fixture = expiryFixture($this);
    $order = $fixture['order'];
    $order->update(['cod_confirmation_deadline_at' => now()->subMinute()]);
    DB::listen(function ($query): void {
        if (str_contains(strtolower($query->sql), 'insert into `web_order_status_histories`')) {
            throw new RuntimeException('Injected history failure');
        }
    });
    expect(fn () => app(WebReservationService::class)->expire($order))->toThrow(RuntimeException::class);
    expect($order->fresh()->status)->toBe('pending_confirmation');
    expect(DB::table('web_order_reservations')->where('web_order_id', $order->id)->value('status'))->toBe('active');
});

test('expiry command is bounded and repeated execution does not create duplicate history', function () {
    $fixture = expiryFixture($this);
    $fixture['order']->update(['cod_confirmation_deadline_at' => now()->subMinute()]);
    $this->artisan('storefront:expire-orders --batch=1 --max=1')->assertExitCode(0);
    $this->artisan('storefront:expire-orders --batch=1 --max=1')->assertExitCode(0);
    expect(DB::table('web_order_status_histories')->where('web_order_id', $fixture['order']->id)
        ->where('to_status', 'expired')->count())->toBe(1);
    expect(DB::table('web_order_reservations')->where('web_order_id', $fixture['order']->id)
        ->where('status', 'expired')->count())->toBe(1);
});
