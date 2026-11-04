<?php

use App\Models\Business;
use App\Models\Category;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SerializedDevice;
use App\Models\User;
use App\Models\WebCart;
use App\Models\WebOrder;
use App\Models\WebProductListing;
use App\ProductCondition;
use App\Services\WebReservationService;
use App\VariantTrackingMode;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/SelectedDeviceSelection.php';

function webCartContext(): array
{
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner, 'owner')->create();
    $category = Category::factory()->for($business)->create();
    $location = Location::factory()->for($business)->create();

    return compact('business', 'category', 'location');
}

function webCartProduct(array $ctx, string $slug = 'phone', array $variantData = []): array
{
    $product = Product::factory()->for($ctx['business'])->for($ctx['category'])->create(['name' => 'Phone']);
    $variant = ProductVariant::factory()->for($ctx['business'])->for($product)->create(array_replace([
        'name' => '128 GB', 'selling_price' => '100.00', 'cost_price' => '50.00',
        'minimum_selling_price' => '80.00',
    ], $variantData));
    WebProductListing::query()->create(['business_id' => $ctx['business']->id, 'product_id' => $product->id,
        'slug' => $slug, 'status' => 'published']);

    return compact('product', 'variant');
}

function webCartStock(array $ctx, ProductVariant $variant, string $quantity = '5.000'): void
{
    InventoryStock::factory()->for($ctx['business'])->for($ctx['location'])->for($variant, 'variant')
        ->create(['quantity_on_hand' => $quantity]);
}

function webCartAdd($test, Product $product, ProductVariant|int $variant, int $quantity = 1, ?string $token = null)
{
    $selection = [];
    if ($variant instanceof ProductVariant && $variant->condition === ProductCondition::Used
        && $variant->tracking_mode === VariantTrackingMode::Serialized && $quantity === 1) {
        $device = $variant->serializedDevices()->where('status', 'in_stock')->firstOrFail();
        $selection = ['device_selection_token' => selectFixtureDevice($test, $product, $variant, $device)];
    }

    return $test->postJson('/api/storefront/v1/cart/items', [...$selection, 'product_id' => $product->id,
        'variant_id' => $variant instanceof ProductVariant ? $variant->id : $variant, 'quantity' => $quantity], $token ? ['X-Storefront-Cart-Token' => $token] : []);
}

test('guest cart starts empty and a valid add creates a hashed persistent token', function () {
    $ctx = webCartContext();
    ['product' => $product, 'variant' => $variant] = webCartProduct($ctx);
    webCartStock($ctx, $variant);
    $empty = $this->getJson('/api/storefront/v1/cart')->assertOk()->assertJsonPath('data.item_count', 0);
    expect($empty->headers->get('Cache-Control'))->toContain('no-store');
    $response = webCartAdd($this, $product, $variant)->assertCreated()->assertJsonPath('data.item_count', 1);
    $token = $response->headers->get('X-Storefront-Cart-Token');
    expect($token)->toMatch('/^[a-f0-9]{64}$/');
    expect(WebCart::query()->first()->token_hash)->toBe(hash('sha256', $token));
    expect($response->getContent())->not->toContain($token);
    $this->getJson('/api/storefront/v1/cart', ['X-Storefront-Cart-Token' => $token])->assertJsonPath('data.item_count', 1);
});

test('guest token protects cart items and opaque item keys are scoped to the cart', function () {
    $ctx = webCartContext();
    ['product' => $product, 'variant' => $variant] = webCartProduct($ctx);
    webCartStock($ctx, $variant);
    $first = webCartAdd($this, $product, $variant)->assertCreated();
    $token = $first->headers->get('X-Storefront-Cart-Token');
    $key = $first->json('data.items.0.key');
    $this->getJson('/api/storefront/v1/cart', ['X-Storefront-Cart-Token' => str_repeat('a', 64)])->assertNotFound();
    $this->patchJson("/api/storefront/v1/cart/items/{$key}", ['quantity' => 2])->assertNotFound();
    $other = webCartAdd($this, $product, $variant)->assertCreated();
    $otherToken = $other->headers->get('X-Storefront-Cart-Token');
    $this->deleteJson("/api/storefront/v1/cart/items/{$key}", [], ['X-Storefront-Cart-Token' => $otherToken])->assertNotFound();
    $this->getJson('/api/storefront/v1/cart', ['X-Storefront-Cart-Token' => $token])->assertJsonPath('data.item_count', 1);
});

test('cart quantity update remove and clear affect only the owner cart', function () {
    $ctx = webCartContext();
    ['product' => $product, 'variant' => $variant] = webCartProduct($ctx);
    webCartStock($ctx, $variant);
    $added = webCartAdd($this, $product, $variant)->assertCreated();
    $token = $added->headers->get('X-Storefront-Cart-Token');
    $key = $added->json('data.items.0.key');
    webCartAdd($this, $product, $variant, 1, $token)->assertJsonPath('data.items.0.quantity', 2);
    $this->patchJson("/api/storefront/v1/cart/items/{$key}", ['quantity' => 3], ['X-Storefront-Cart-Token' => $token])->assertJsonPath('data.items.0.line_total', '300.00');
    $this->deleteJson("/api/storefront/v1/cart/items/{$key}", [], ['X-Storefront-Cart-Token' => $token])->assertJsonPath('data.item_count', 0);
    webCartAdd($this, $product, $variant, 1, $token)->assertCreated();
    $this->deleteJson('/api/storefront/v1/cart', [], ['X-Storefront-Cart-Token' => $token])->assertJsonPath('data.item_count', 0);
});

test('expired carts return 410 and cannot be revived by a mutation', function () {
    $ctx = webCartContext();
    ['product' => $product, 'variant' => $variant] = webCartProduct($ctx);
    webCartStock($ctx, $variant);
    $token = webCartAdd($this, $product, $variant)->headers->get('X-Storefront-Cart-Token');
    WebCart::query()->first()->update(['expires_at' => now()->subMinute()]);
    $this->getJson('/api/storefront/v1/cart', ['X-Storefront-Cart-Token' => $token])->assertStatus(410)->assertJsonPath('error.code', 'CART_EXPIRED');
    webCartAdd($this, $product, $variant, 1, $token)->assertStatus(410);
});

test('inactive unpublished and cross business products or variants cannot be added', function () {
    $ctx = webCartContext();
    ['product' => $product, 'variant' => $variant] = webCartProduct($ctx);
    webCartStock($ctx, $variant);
    $product->webListing()->update(['status' => 'draft']);
    webCartAdd($this, $product, $variant)->assertUnprocessable()->assertJsonPath('error.code', 'PRODUCT_UNAVAILABLE');
    $product->webListing()->update(['status' => 'published']);
    $product->update(['status' => 'inactive']);
    webCartAdd($this, $product, $variant)->assertUnprocessable();
    $product->update(['status' => 'active']);
    $variant->update(['status' => 'inactive']);
    webCartAdd($this, $product, $variant)->assertUnprocessable();
    $variant->update(['status' => 'active']);
    webCartAdd($this, $product, $variant->id + 999999)->assertUnprocessable();
    $otherOwner = User::factory()->create();
    $otherBusiness = Business::factory()->for($otherOwner, 'owner')->create(['setup_key' => 'secondary']);
    $otherCategory = Category::factory()->for($otherBusiness)->create();
    $otherProduct = Product::factory()->for($otherBusiness)->for($otherCategory)->create();
    $otherVariant = ProductVariant::factory()->for($otherBusiness)->for($otherProduct)->create();
    WebProductListing::query()->create(['business_id' => $otherBusiness->id, 'product_id' => $otherProduct->id, 'slug' => 'other', 'status' => 'published']);
    webCartAdd($this, $otherProduct, $otherVariant)->assertUnprocessable();
    expect(WebCart::query()->count())->toBe(0);
});

test('quantity-based stock is checked for requested quantity and does not mutate inventory', function () {
    $ctx = webCartContext();
    ['product' => $product, 'variant' => $variant] = webCartProduct($ctx);
    webCartStock($ctx, $variant, '2.000');
    webCartAdd($this, $product, $variant, 3)->assertStatus(409)->assertJsonPath('error.code', 'INSUFFICIENT_AVAILABILITY');
    webCartAdd($this, $product, $variant, 2)->assertCreated();
    expect((string) InventoryStock::query()->first()->quantity_on_hand)->toBe('2.000');
    expect(DB::table('stock_movements')->count())->toBe(0);
});

test('used serialized phones quote the selected device price without exposing IMEI', function () {
    $ctx = webCartContext();
    ['product' => $product, 'variant' => $variant] = webCartProduct($ctx, 'used', [
        'condition' => ProductCondition::Used, 'tracking_mode' => 'serialized', 'selling_price' => '1200.00',
    ]);
    $product->update(['product_type' => 'serialized']);
    SerializedDevice::factory()->for($ctx['location'])->for($variant, 'variant')->create([
        'business_id' => $ctx['business']->id, 'condition' => ProductCondition::Used,
        'unit_selling_price' => '900.00', 'imei' => '123456789012345',
    ]);
    $response = webCartAdd($this, $product, $variant)->assertCreated()->assertJsonPath('data.items.0.unit_price', '900.00')
        ->assertJsonPath('data.items.0.requires_device_confirmation', true);
    $token = $response->headers->get('X-Storefront-Cart-Token');
    $quote = $this->postJson('/api/storefront/v1/quotes', [], ['X-Storefront-Cart-Token' => $token])->assertOk()
        ->assertJsonPath('data.total', '900.00')->assertJsonPath('data.total_is_final', false);
    expect($quote->getContent())->not->toContain('123456789012345');
    webCartAdd($this, $product, $variant, 2, $token)->assertUnprocessable();
});

test('bundle availability follows component capacity without stock movement', function () {
    $ctx = webCartContext();
    ['product' => $product, 'variant' => $bundle] = webCartProduct($ctx, 'bundle');
    $product->update(['product_type' => 'combo']);
    ['variant' => $component] = webCartProduct($ctx, 'component');
    DB::table('bundle_components')->insert(['business_id' => $ctx['business']->id,
        'bundle_variant_id' => $bundle->id, 'component_variant_id' => $component->id,
        'quantity' => '2.000', 'created_at' => now(), 'updated_at' => now()]);
    webCartStock($ctx, $component, '3.000');
    webCartAdd($this, $product, $bundle, 2)->assertStatus(409);
    webCartAdd($this, $product, $bundle, 1)->assertCreated();
    expect(DB::table('stock_movements')->count())->toBe(0);
});

test('quotes persist decimal snapshots, expire, and recalculate after price changes', function () {
    $ctx = webCartContext();
    ['product' => $product, 'variant' => $variant] = webCartProduct($ctx, 'priced', ['selling_price' => '123.45']);
    webCartStock($ctx, $variant);
    $token = webCartAdd($this, $product, $variant, 2)->headers->get('X-Storefront-Cart-Token');
    $first = $this->postJson('/api/storefront/v1/quotes', ['total' => '0.01', 'discount' => '9999'], ['X-Storefront-Cart-Token' => $token])
        ->assertOk()->assertJsonPath('data.total', '246.90')->assertJsonPath('data.inventory_reserved', false);
    expect($first->json('data.reference'))->toHaveLength(26);
    expect(now()->diffInMinutes($first->json('data.expires_at')))->toBeGreaterThanOrEqual(14);
    $variant->update(['selling_price' => '130.00']);
    $second = $this->postJson('/api/storefront/v1/quotes', [], ['X-Storefront-Cart-Token' => $token])
        ->assertJsonPath('data.total', '260.00');
    expect($second->json('data.reference'))->not->toBe($first->json('data.reference'));
    expect(DB::table('web_quote_items')->count())->toBe(2);
    expect(DB::table('stock_movements')->count())->toBe(0);
});

test('quote rejects an empty cart and cart tampering with invalid quantities', function () {
    webCartContext();
    $this->postJson('/api/storefront/v1/quotes')->assertNotFound();
    $this->postJson('/api/storefront/v1/cart/items', ['product_id' => 1, 'variant_id' => 1, 'quantity' => 0])
        ->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_REQUEST');
});

test('quotes reject stock changes and products unpublished after they entered a cart', function () {
    $ctx = webCartContext();
    ['product' => $product, 'variant' => $variant] = webCartProduct($ctx);
    webCartStock($ctx, $variant, '2.000');
    $token = webCartAdd($this, $product, $variant, 2)->headers->get('X-Storefront-Cart-Token');
    InventoryStock::query()->first()->update(['quantity_on_hand' => '1.000']);
    $this->postJson('/api/storefront/v1/quotes', [], ['X-Storefront-Cart-Token' => $token])
        ->assertStatus(409)->assertJsonPath('error.code', 'INSUFFICIENT_AVAILABILITY');
    InventoryStock::query()->first()->update(['quantity_on_hand' => '2.000']);
    $product->webListing()->update(['status' => 'draft']);
    $this->getJson('/api/storefront/v1/cart', ['X-Storefront-Cart-Token' => $token])
        ->assertJsonPath('data.can_quote', false)->assertJsonPath('data.items.0.slug', null);
    $this->postJson('/api/storefront/v1/quotes', [], ['X-Storefront-Cart-Token' => $token])
        ->assertUnprocessable()->assertJsonPath('error.code', 'PRODUCT_UNAVAILABLE');
    expect(DB::table('web_quotes')->count())->toBe(0);
});

test('quote requires one location to have every item', function () {
    $ctx = webCartContext();
    ['product' => $firstProduct, 'variant' => $firstVariant] = webCartProduct($ctx, 'first');
    ['product' => $secondProduct, 'variant' => $secondVariant] = webCartProduct($ctx, 'second');
    webCartStock($ctx, $firstVariant);
    $otherLocation = Location::factory()->for($ctx['business'])->create();
    InventoryStock::factory()->for($ctx['business'])->for($otherLocation)->for($secondVariant, 'variant')
        ->create(['quantity_on_hand' => '5.000']);
    $token = webCartAdd($this, $firstProduct, $firstVariant)->headers->get('X-Storefront-Cart-Token');
    webCartAdd($this, $secondProduct, $secondVariant, 1, $token)->assertCreated();
    $this->postJson('/api/storefront/v1/quotes', [], ['X-Storefront-Cart-Token' => $token])
        ->assertStatus(409)->assertJsonPath('error.code', 'NO_SINGLE_LOCATION');
});

test('quote does not overstate availability when a bundle and its component share stock', function () {
    $ctx = webCartContext();
    ['product' => $bundleProduct, 'variant' => $bundle] = webCartProduct($ctx, 'bundle');
    $bundleProduct->update(['product_type' => 'combo']);
    ['product' => $componentProduct, 'variant' => $component] = webCartProduct($ctx, 'component');
    DB::table('bundle_components')->insert(['business_id' => $ctx['business']->id,
        'bundle_variant_id' => $bundle->id, 'component_variant_id' => $component->id,
        'quantity' => '2.000', 'created_at' => now(), 'updated_at' => now()]);
    webCartStock($ctx, $component, '2.000');
    $token = webCartAdd($this, $bundleProduct, $bundle)->headers->get('X-Storefront-Cart-Token');
    webCartAdd($this, $componentProduct, $component, 1, $token)->assertCreated();
    $this->postJson('/api/storefront/v1/quotes', [], ['X-Storefront-Cart-Token' => $token])
        ->assertStatus(409)->assertJsonPath('error.code', 'NO_SINGLE_LOCATION');
    DB::table('inventory_stocks')->where('product_variant_id', $component->id)->update(['quantity_on_hand' => '3.000']);
    $this->postJson('/api/storefront/v1/quotes', [], ['X-Storefront-Cart-Token' => $token])
        ->assertOk()->assertJsonPath('data.total', '200.00');
});

test('quote aggregates fractional component demand across multiple combo lines', function () {
    $ctx = webCartContext();
    ['product' => $firstProduct, 'variant' => $firstBundle] = webCartProduct($ctx, 'first-bundle');
    ['product' => $secondProduct, 'variant' => $secondBundle] = webCartProduct($ctx, 'second-bundle');
    ['variant' => $component] = webCartProduct($ctx, 'component');
    $firstProduct->update(['product_type' => 'combo']);
    $secondProduct->update(['product_type' => 'combo']);
    foreach ([[$firstBundle, '1.500'], [$secondBundle, '1.000']] as [$bundle, $quantity]) {
        DB::table('bundle_components')->insert(['business_id' => $ctx['business']->id,
            'bundle_variant_id' => $bundle->id, 'component_variant_id' => $component->id,
            'quantity' => $quantity, 'created_at' => now(), 'updated_at' => now()]);
    }
    webCartStock($ctx, $component, '2.000');
    $token = webCartAdd($this, $firstProduct, $firstBundle)->headers->get('X-Storefront-Cart-Token');
    webCartAdd($this, $secondProduct, $secondBundle, 1, $token)->assertCreated();
    $this->postJson('/api/storefront/v1/quotes', [], ['X-Storefront-Cart-Token' => $token])
        ->assertStatus(409)->assertJsonPath('error.code', 'NO_SINGLE_LOCATION');
    DB::table('inventory_stocks')->where('product_variant_id', $component->id)->update(['quantity_on_hand' => '2.500']);
    $this->postJson('/api/storefront/v1/quotes', [], ['X-Storefront-Cart-Token' => $token])
        ->assertOk()->assertJsonPath('data.total', '200.00');
});

test('active web reservations reduce shared component quote availability', function () {
    $ctx = webCartContext();
    ['product' => $bundleProduct, 'variant' => $bundle] = webCartProduct($ctx, 'bundle');
    $bundleProduct->update(['product_type' => 'combo']);
    ['product' => $componentProduct, 'variant' => $component] = webCartProduct($ctx, 'component');
    DB::table('bundle_components')->insert(['business_id' => $ctx['business']->id,
        'bundle_variant_id' => $bundle->id, 'component_variant_id' => $component->id,
        'quantity' => '2.000', 'created_at' => now(), 'updated_at' => now()]);
    webCartStock($ctx, $component, '4.000');
    $token = webCartAdd($this, $bundleProduct, $bundle)->headers->get('X-Storefront-Cart-Token');
    webCartAdd($this, $componentProduct, $component, 1, $token)->assertCreated();
    $this->postJson('/api/storefront/v1/quotes', [], ['X-Storefront-Cart-Token' => $token])->assertOk();

    $order = WebOrder::query()->create(['business_id' => $ctx['business']->id, 'location_id' => $ctx['location']->id,
        'reference' => (string) Str::ulid(), 'submission_key' => (string) Str::uuid(),
        'payload_hash' => hash('sha256', 'quote-reservation-test'), 'status' => 'pending_confirmation',
        'payment_method' => 'cod', 'payment_status' => 'cod_due', 'currency' => $ctx['business']->currency,
        'subtotal' => '100.00', 'total' => '100.00']);
    $orderItem = DB::table('web_order_items')->insertGetId(['business_id' => $ctx['business']->id,
        'web_order_id' => $order->id, 'product_id' => $componentProduct->id,
        'product_variant_id' => $component->id, 'line_number' => 1,
        'product_name_snapshot' => 'Component', 'product_slug_snapshot' => 'component',
        'variant_name_snapshot' => $component->name, 'quantity' => '1.000',
        'unit_price' => '100.00', 'line_total' => '100.00', 'created_at' => now(), 'updated_at' => now()]);
    app(WebReservationService::class)->reserveComponent($order, $orderItem, $component->id, '1.000', 'shared-quote');
    $this->postJson('/api/storefront/v1/quotes', [], ['X-Storefront-Cart-Token' => $token])->assertOk();
    DB::table('inventory_stocks')->where('product_variant_id', $component->id)->update(['quantity_on_hand' => '3.000']);
    $this->postJson('/api/storefront/v1/quotes', [], ['X-Storefront-Cart-Token' => $token])
        ->assertStatus(409)->assertJsonPath('error.code', 'NO_SINGLE_LOCATION');
});

test('quote snapshot expiry and public response hide cart and inventory internals', function () {
    $ctx = webCartContext();
    ['product' => $product, 'variant' => $variant] = webCartProduct($ctx);
    webCartStock($ctx, $variant);
    $token = webCartAdd($this, $product, $variant)->headers->get('X-Storefront-Cart-Token');
    $response = $this->postJson('/api/storefront/v1/quotes', [], ['X-Storefront-Cart-Token' => $token])->assertOk();
    $quote = DB::table('web_quotes')->first();
    expect($quote->status)->toBe('indicative');
    expect(strtotime($quote->expires_at))->toBeGreaterThan(strtotime($quote->created_at));
    expect(strtotime($quote->expires_at) - strtotime($quote->created_at))->toBe(900);
    foreach (['token_hash', 'web_cart_id', 'product_variant_id', 'imei', 'unit_cost', 'minimum_selling_price', 'location_id'] as $secret) {
        expect($response->getContent())->not->toContain($secret);
    }
    $response->assertJsonPath('data.delivery_included', false)->assertJsonPath('data.inventory_reserved', false);
});

test('cart routes are rate limited and do not grant access to staff endpoints', function () {
    webCartContext();
    RateLimiter::for('storefront-cart', fn () => Limit::perMinute(1)->by('cart-test'));
    $this->getJson('/api/storefront/v1/cart')->assertOk();
    $this->getJson('/api/storefront/v1/cart')->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
    $this->get('/products')->assertRedirect('/login');
});
