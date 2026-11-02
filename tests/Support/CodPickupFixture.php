<?php

use App\Models\Business;
use App\Models\Category;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SerializedDevice;
use App\Models\User;
use App\Models\WebOrder;
use App\Models\WebPickupLocation;
use App\Models\WebProductListing;
use App\ProductCondition;
use App\Services\CashierShiftService;
use App\Services\WebOrderFulfillmentService;
use App\Services\WebOrderPreparationService;
use App\Services\WebOrderStaffService;
use App\VariantTrackingMode;
use Illuminate\Support\Str;

function codPickupFixture($test, bool $serialized = false, bool $combo = false, bool $shared = false, string $componentQuantity = '2.000', bool $componentSerialized = false): array
{
    config()->set('storefront.order_submission_enabled', true);
    config()->set('storefront.offer_ttl_minutes', 20);
    config()->set('storefront.cod_confirmation_ttl_minutes', 20);
    config()->set('storefront.reservation_ttl_minutes', 30);
    config()->set('storefront.order_access_key', str_repeat('k', 32));
    $actor = User::factory()->create();
    $business = Business::factory()->for($actor, 'owner')->create(['setup_key' => 'initial', 'tax_rate' => '10.00']);
    $location = Location::factory()->for($business)->create(['type' => 'shop']);
    WebPickupLocation::query()->create(['business_id' => $business->id, 'location_id' => $location->id,
        'public_code' => $location->code, 'is_active' => true]);
    $category = Category::factory()->for($business)->create();
    $product = Product::factory()->for($business)->for($category)->create([
        'product_type' => $combo ? 'combo' : ($serialized ? 'serialized' : 'simple'),
    ]);
    $variant = ProductVariant::factory()->for($business)->for($product)->create([
        'selling_price' => '1000.00', 'cost_price' => '500.00',
        'condition' => $serialized ? ProductCondition::Used : ProductCondition::New,
        'tracking_mode' => $serialized ? VariantTrackingMode::Serialized : VariantTrackingMode::QuantityBased,
    ]);
    WebProductListing::query()->create(['business_id' => $business->id, 'product_id' => $product->id,
        'slug' => 'handover-product', 'status' => 'published']);
    $component = null;
    if ($combo) {
        $componentProduct = Product::factory()->for($business)->for($category)->create(['product_type' => $componentSerialized ? 'serialized' : 'simple']);
        $component = ProductVariant::factory()->for($business)->for($componentProduct)->create(['cost_price' => '100.00',
            'tracking_mode' => $componentSerialized ? VariantTrackingMode::Serialized : VariantTrackingMode::QuantityBased,
            'condition' => $componentSerialized ? ProductCondition::Used : ProductCondition::New]);
        $variant->bundleComponents()->create(['business_id' => $business->id,
            'component_variant_id' => $component->id, 'quantity' => $componentQuantity]);
        WebProductListing::query()->create(['business_id' => $business->id, 'product_id' => $componentProduct->id,
            'slug' => 'shared-component', 'status' => 'published']);
        InventoryStock::factory()->for($business)->for($location)->for($component, 'variant')
            ->create(['quantity_on_hand' => '5.000']);
    } else {
        InventoryStock::factory()->for($business)->for($location)->for($variant, 'variant')
            ->create(['quantity_on_hand' => '5.000']);
    }
    $device = $serialized || $componentSerialized ? SerializedDevice::factory()->for($location)->for($componentSerialized ? $component : $variant, 'variant')->create([
        'business_id' => $business->id, 'condition' => ProductCondition::Used, 'unit_selling_price' => '900.00',
    ]) : null;
    $added = $test->postJson('/api/storefront/v1/cart/items', [
        'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1,
    ])->assertCreated();
    $token = $added->headers->get('X-Storefront-Cart-Token');
    $headers = ['X-Storefront-Cart-Token' => $token];
    if ($shared && $component !== null) {
        $test->postJson('/api/storefront/v1/cart/items', [
            'product_id' => $component->product_id, 'variant_id' => $component->id, 'quantity' => 1,
        ], $headers)->assertCreated();
    }
    $quote = $test->postJson('/api/storefront/v1/quotes', [], $headers)->assertOk();
    $offer = $test->postJson('/api/storefront/v1/order-offers', [
        'quote_reference' => $quote->json('data.reference'), 'fulfillment_mode' => 'shop_pickup',
        'pickup_location_code' => $location->code, 'payment_method' => 'cod',
        'contact' => ['name' => 'Pickup Customer', 'phone' => '0771234567'],
    ], $headers)->assertCreated();
    $submitted = $test->postJson('/api/storefront/v1/orders', [
        'offer_reference' => $offer->json('data.reference'),
        'accepted_terms_digest' => $offer->json('data.terms_digest'), 'accepted' => true,
    ], [...$headers, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    $order = WebOrder::query()->sole();

    return compact('actor', 'business', 'location', 'variant', 'component', 'device', 'order', 'submitted');
}

function readyCodPickup(array $fixture, bool $openShift = true): void
{
    $actor = $fixture['actor'];
    $order = $fixture['order'];
    app(WebOrderStaffService::class)->recordCall($actor, $order->reference, 'answered_confirmed', null);
    app(WebOrderStaffService::class)->confirmCod($actor, $order->reference);
    app(WebOrderPreparationService::class)->start($actor, $order->reference);
    foreach ($order->reservations as $reservation) {
        app(WebOrderPreparationService::class)->check($actor, $order->reference, $reservation->id,
            (string) $reservation->quantity, $fixture['device']?->imei,
            $fixture['device']?->condition->value, null);
    }
    app(WebOrderPreparationService::class)->markReady($actor, $order->reference);
    if ($openShift) {
        app(CashierShiftService::class)->open($actor, [
            'location_id' => $fixture['location']->id, 'operation_key' => (string) Str::uuid(),
            'opening_cash' => '0.00',
        ]);
    }
}

function pickupHandover(array $fixture, ?string $key = null, ?string $tender = null): array
{
    return app(WebOrderFulfillmentService::class)->collectPickup($fixture['actor'],
        $fixture['order']->reference, $key ?? (string) Str::uuid(),
        $tender ?? (string) $fixture['order']->total);
}
