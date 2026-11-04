<?php

use App\Models\Business;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SerializedDevice;
use App\Models\User;
use App\Models\WebCart;
use App\Models\WebPickupLocation;
use App\Models\WebProductListing;
use App\ProductCondition;

require_once __DIR__.'/SelectedDeviceSelection.php';

function usedContinuityFixture($test, bool $authenticated = false): array
{
    config(['storefront.order_submission_enabled' => true, 'storefront.offer_ttl_minutes' => 10,
        'storefront.reservation_ttl_minutes' => 30, 'storefront.cod_confirmation_ttl_minutes' => 20,
        'storefront.order_access_key' => str_repeat('k', 32)]);
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner, 'owner')->create();
    $category = Category::factory()->for($business)->create();
    $location = Location::factory()->for($business)->create(['type' => 'shop']);
    WebPickupLocation::query()->create(['business_id' => $business->id, 'location_id' => $location->id,
        'public_code' => $location->code, 'is_active' => true]);
    $product = Product::factory()->for($business)->for($category)->serialized()->create();
    $variant = ProductVariant::factory()->for($business)->for($product)->serialized(ProductCondition::Used)
        ->create(['selling_price' => '1500.00']);
    WebProductListing::query()->create(['business_id' => $business->id, 'product_id' => $product->id,
        'slug' => 'continuity-phone', 'status' => 'published']);
    $device = SerializedDevice::factory()->for($location)->for($variant, 'variant')->create([
        'business_id' => $business->id, 'condition' => ProductCondition::Used, 'unit_selling_price' => '1200.00',
        'imei' => '359111111111111', 'serial_number' => 'PRIVATE-SELECTED-PHONE',
    ]);
    $cheaper = SerializedDevice::factory()->for($location)->for($variant, 'variant')->create([
        'business_id' => $business->id, 'condition' => ProductCondition::Used, 'unit_selling_price' => '700.00',
    ]);
    if ($authenticated) {
        $test->actingAs($owner);
    }
    $selection = selectFixtureDevice($test, $product, $variant, $device);
    $added = $test->postJson('/api/storefront/v1/cart/items', [
        'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1,
        'device_selection_token' => $selection, 'selected_unit_price' => '0.01',
    ])->assertCreated()->assertJsonPath('data.items.0.unit_price', '1200.00');
    $token = $added->headers->get('X-Storefront-Cart-Token');
    $headers = ['X-Storefront-Cart-Token' => $token];
    $cart = WebCart::query()->where('token_hash', hash('sha256', $token))->sole();

    return compact('owner', 'business', 'category', 'location', 'product', 'variant', 'device', 'cheaper', 'token', 'headers', 'cart');
}

function usedContinuityOffer($test, array $f, ?string $quoteReference = null)
{
    $quoteReference ??= $test->postJson('/api/storefront/v1/quotes', [], $f['headers'])
        ->assertOk()->json('data.reference');

    return $test->postJson('/api/storefront/v1/order-offers', [
        'quote_reference' => $quoteReference, 'fulfillment_mode' => 'shop_pickup',
        'pickup_location_code' => $f['location']->code, 'payment_method' => 'cod',
        'contact' => ['name' => 'Customer', 'phone' => '0771234567'],
    ], $f['headers']);
}

function usedContinuityAcceptance($test, array $f, $offer, string $key)
{
    return $test->postJson('/api/storefront/v1/orders', [
        'offer_reference' => $offer->json('data.reference'),
        'accepted_terms_digest' => $offer->json('data.terms_digest'), 'accepted' => true,
    ], [...$f['headers'], 'Idempotency-Key' => $key]);
}
