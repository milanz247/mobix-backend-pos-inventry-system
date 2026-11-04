<?php

use App\Models\Business;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SerializedDevice;
use App\Models\StorefrontDeviceSelection;
use App\Models\User;
use App\Models\WebCart;
use App\Models\WebOrder;
use App\Models\WebProductListing;
use App\ProductCondition;
use App\VariantTrackingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function selectedDeviceCartContext(): array
{
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner, 'owner')->create();
    $category = Category::factory()->for($business)->create();
    $location = Location::factory()->for($business)->create();

    return compact('owner', 'business', 'category', 'location');
}

function selectedDeviceCartProduct(array $ctx, string $slug = 'selected-used-phone'): array
{
    $product = Product::factory()->for($ctx['business'])->for($ctx['category'])->serialized()->create();
    $variant = ProductVariant::factory()->for($ctx['business'])->for($product)->serialized()->create([
        'condition' => ProductCondition::Used,
        'selling_price' => '120000.00',
    ]);
    WebProductListing::query()->create([
        'business_id' => $ctx['business']->id,
        'product_id' => $product->id,
        'slug' => $slug,
        'status' => 'published',
    ]);
    $device = SerializedDevice::factory()->for($ctx['location'])->for($variant, 'variant')->create([
        'business_id' => $ctx['business']->id,
        'condition' => ProductCondition::Used,
        'status' => 'in_stock',
        'unit_selling_price' => '115000.00',
        'imei' => '359888888888888',
        'imei2' => '359999999999999',
        'serial_number' => 'PRIVATE-CART-DEVICE',
    ]);

    return compact('product', 'variant', 'device');
}

function reserveSelectedDeviceForCartTest(array $ctx, Product $product, ProductVariant $variant, SerializedDevice $device): void
{
    $order = WebOrder::query()->create([
        'business_id' => $ctx['business']->id,
        'location_id' => $ctx['location']->id,
        'reference' => (string) Str::ulid(),
        'submission_key' => (string) Str::uuid(),
        'payload_hash' => hash('sha256', Str::random()),
        'status' => 'pending_confirmation',
        'currency' => $ctx['business']->currency,
        'subtotal' => '115000.00',
        'total' => '115000.00',
    ]);
    $itemId = DB::table('web_order_items')->insertGetId([
        'business_id' => $ctx['business']->id, 'web_order_id' => $order->id,
        'product_id' => $product->id, 'product_variant_id' => $variant->id,
        'line_number' => 1, 'product_name_snapshot' => $product->name,
        'product_slug_snapshot' => 'selected-used-phone', 'variant_name_snapshot' => $variant->name,
        'quantity' => '1.000', 'unit_price' => '115000.00', 'line_total' => '115000.00',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('web_order_reservations')->insert([
        'business_id' => $ctx['business']->id, 'web_order_id' => $order->id, 'web_order_item_id' => $itemId,
        'location_id' => $ctx['location']->id, 'component_variant_id' => $variant->id,
        'serialized_device_id' => $device->id, 'active_device_id' => $device->id,
        'reservation_line_key' => (string) Str::ulid(), 'quantity' => '1.000', 'status' => 'active',
        'expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('used serialized cart lines require one valid device selection token', function () {
    $ctx = selectedDeviceCartContext();
    ['product' => $product, 'variant' => $variant] = selectedDeviceCartProduct($ctx);

    $this->postJson('/api/storefront/v1/cart/items', [
        'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1,
    ])->assertUnprocessable()->assertJsonPath('error.code', 'DEVICE_SELECTION_REQUIRED');
    $this->postJson('/api/storefront/v1/cart/items', [
        'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 2,
    ])->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_QUANTITY');
    expect(WebCart::query()->count())->toBe(0);
});

test('valid selection creates a private device cart line and consumes the token after insertion', function () {
    $ctx = selectedDeviceCartContext();
    ['product' => $product, 'variant' => $variant, 'device' => $device] = selectedDeviceCartProduct($ctx);
    $options = $this->getJson('/api/storefront/v1/products/selected-used-phone/device-options?variant='.$variant->id)
        ->assertOk()->json('data');
    $selection = $this->postJson('/api/storefront/v1/device-selections', ['option_key' => $options[0]['option_key']])
        ->assertCreated()->json('data');

    $response = $this->actingAs($ctx['owner'])->postJson('/api/storefront/v1/cart/items', [
        'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1,
        'device_selection_token' => $selection['token'],
    ])->assertCreated()->assertJsonPath('data.items.0.quantity', 1)
        ->assertJsonPath('data.items.0.unit_price', '115000.00')
        ->assertJsonPath('data.items.0.selected_device.condition', 'Used')
        ->assertJsonPath('data.items.0.selected_device.unit_price', '115000.00')
        ->assertJsonPath('data.items.0.selected_device.availability', 'in_stock');

    $cartToken = $response->headers->get('X-Storefront-Cart-Token');
    expect($cartToken)->toMatch('/^[a-f0-9]{64}$/')
        ->and(DB::table('web_cart_items')->where('web_cart_id', WebCart::query()->firstOrFail()->id)->value('serialized_device_id'))->toBe($device->id)
        ->and(StorefrontDeviceSelection::query()->firstOrFail()->consumed_at)->not->toBeNull()
        ->and(DB::table('web_order_reservations')->count())->toBe(0);
    foreach ([$selection['token'], 'token_hash', 'serialized_device_id', (string) $device->id, $device->imei, $device->imei2, $device->serial_number, 'unit_cost', 'location_id'] as $private) {
        expect($response->getContent())->not->toContain($private);
    }

    $this->postJson('/api/storefront/v1/cart/items', [
        'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1,
        'device_selection_token' => $selection['token'],
    ], ['X-Storefront-Cart-Token' => $cartToken])->assertStatus(409)->assertJsonPath('error.code', 'DEVICE_ALREADY_IN_CART');
});

test('selection tokens cannot be applied to another product or variant', function () {
    $ctx = selectedDeviceCartContext();
    ['product' => $product, 'variant' => $variant] = selectedDeviceCartProduct($ctx, 'used-phone-a');
    $key = $this->getJson('/api/storefront/v1/products/used-phone-a/device-options?variant='.$variant->id)
        ->assertOk()->json('data.0.option_key');
    $selectionToken = $this->postJson('/api/storefront/v1/device-selections', ['option_key' => $key])
        ->assertCreated()->json('data.token');
    $other = selectedDeviceCartProduct($ctx, 'used-phone-b');

    $this->postJson('/api/storefront/v1/cart/items', [
        'product_id' => $other['product']->id, 'variant_id' => $other['variant']->id,
        'quantity' => 1, 'device_selection_token' => $selectionToken,
    ])->assertUnprocessable()->assertJsonPath('error.code', 'DEVICE_SELECTION_UNAVAILABLE');
    expect(StorefrontDeviceSelection::query()->where('token_hash', hash('sha256', $selectionToken))->value('consumed_at'))->toBeNull();
});

test('a selection scoped to another business cannot be used in this business cart', function () {
    $ctx = selectedDeviceCartContext();
    ['product' => $product, 'variant' => $variant] = selectedDeviceCartProduct($ctx);
    $owner = User::factory()->create();
    $otherBusiness = Business::factory()->for($owner, 'owner')->create(['setup_key' => 'secondary']);
    $otherCategory = Category::factory()->for($otherBusiness)->create();
    $otherLocation = Location::factory()->for($otherBusiness)->create();
    $otherProduct = Product::factory()->for($otherBusiness)->for($otherCategory)->serialized()->create();
    $otherVariant = ProductVariant::factory()->for($otherBusiness)->for($otherProduct)->serialized(ProductCondition::Used)->create();
    $otherDevice = SerializedDevice::factory()->for($otherLocation)->for($otherVariant, 'variant')->create([
        'business_id' => $otherBusiness->id, 'condition' => ProductCondition::Used, 'status' => 'in_stock',
    ]);
    $foreignToken = bin2hex(random_bytes(32));
    StorefrontDeviceSelection::query()->create([
        'business_id' => $otherBusiness->id, 'product_id' => $otherProduct->id,
        'product_variant_id' => $otherVariant->id, 'serialized_device_id' => $otherDevice->id,
        'token_hash' => hash('sha256', $foreignToken), 'selected_price' => $otherVariant->selling_price,
        'expires_at' => now()->addMinutes(10),
    ]);

    $this->postJson('/api/storefront/v1/cart/items', [
        'product_id' => $product->id, 'variant_id' => $variant->id,
        'quantity' => 1, 'device_selection_token' => $foreignToken,
    ])->assertUnprocessable()->assertJsonPath('error.code', 'DEVICE_SELECTION_UNAVAILABLE');
    expect(WebCart::query()->count())->toBe(0)
        ->and(StorefrontDeviceSelection::query()->where('token_hash', hash('sha256', $foreignToken))->value('consumed_at'))->toBeNull();
});

test('different devices of one variant occupy separate cart lines and duplicate device insertion fails without consuming its token', function () {
    $ctx = selectedDeviceCartContext();
    ['product' => $product, 'variant' => $variant, 'device' => $firstDevice] = selectedDeviceCartProduct($ctx);
    $secondDevice = SerializedDevice::factory()->for($ctx['location'])->for($variant, 'variant')->create([
        'business_id' => $ctx['business']->id, 'condition' => ProductCondition::Used,
        'status' => 'in_stock', 'unit_selling_price' => '119000.00',
    ]);
    $options = $this->getJson('/api/storefront/v1/products/selected-used-phone/device-options?variant='.$variant->id)->assertOk()->json('data');
    $firstSelection = $this->postJson('/api/storefront/v1/device-selections', ['option_key' => $options[0]['option_key']])->assertCreated()->json('data.token');
    $secondSelection = $this->postJson('/api/storefront/v1/device-selections', ['option_key' => $options[1]['option_key']])->assertCreated()->json('data.token');
    $added = $this->postJson('/api/storefront/v1/cart/items', [
        'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1,
        'device_selection_token' => $firstSelection,
    ])->assertCreated();
    $cartToken = $added->headers->get('X-Storefront-Cart-Token');
    $this->postJson('/api/storefront/v1/cart/items', [
        'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1,
        'device_selection_token' => $secondSelection,
    ], ['X-Storefront-Cart-Token' => $cartToken])->assertCreated();
    expect(DB::table('web_cart_items')->count())->toBe(2);

    $thirdSelection = $this->postJson('/api/storefront/v1/device-selections', ['option_key' => $options[0]['option_key']])->assertCreated()->json('data.token');
    $this->postJson('/api/storefront/v1/cart/items', [
        'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1,
        'device_selection_token' => $thirdSelection,
    ], ['X-Storefront-Cart-Token' => $cartToken])->assertStatus(409)->assertJsonPath('error.code', 'DEVICE_ALREADY_IN_CART');
    expect(StorefrontDeviceSelection::query()->where('token_hash', hash('sha256', $thirdSelection))->value('consumed_at'))->toBeNull()
        ->and(DB::table('web_cart_items')->where('serialized_device_id', $firstDevice->id)->count())->toBe(1)
        ->and(DB::table('web_cart_items')->where('serialized_device_id', $secondDevice->id)->count())->toBe(1);
});

test('expired selections and stale price status condition location or reservation are rejected', function () {
    $ctx = selectedDeviceCartContext();
    ['product' => $product, 'variant' => $variant, 'device' => $device] = selectedDeviceCartProduct($ctx);
    $getKey = fn (): string => $this->getJson('/api/storefront/v1/products/selected-used-phone/device-options?variant='.$variant->id)->assertOk()->json('data.0.option_key');
    $select = fn (string $key): string => $this->postJson('/api/storefront/v1/device-selections', ['option_key' => $key])->assertCreated()->json('data.token');
    $add = fn (string $token) => $this->postJson('/api/storefront/v1/cart/items', [
        'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1, 'device_selection_token' => $token,
    ]);

    $expired = $select($getKey());
    $this->travel(11)->minutes();
    $add($expired)->assertUnprocessable();
    $this->travelBack();

    foreach ([
        fn () => $device->update(['unit_selling_price' => '116000.00']),
        fn () => $device->update(['status' => 'sold']),
        fn () => $device->update(['status' => 'in_stock', 'condition' => ProductCondition::Refurbished]),
        fn () => $device->update(['location_id' => Location::factory()->for($ctx['business'])->create(['status' => 'inactive'])->id]),
    ] as $change) {
        $device->update(['status' => 'in_stock', 'condition' => ProductCondition::Used, 'location_id' => $ctx['location']->id, 'unit_selling_price' => '115000.00']);
        $ctx['location']->update(['status' => 'active']);
        $token = $select($getKey());
        $change();
        $add($token)->assertUnprocessable();
    }

    $device->update(['status' => 'in_stock', 'condition' => ProductCondition::Used, 'location_id' => $ctx['location']->id, 'unit_selling_price' => '115000.00']);
    $ctx['location']->update(['status' => 'active']);
    $reservedToken = $select($getKey());
    reserveSelectedDeviceForCartTest($ctx, $product, $variant, $device);
    $add($reservedToken)->assertUnprocessable();
    expect(StorefrontDeviceSelection::query()->whereNull('consumed_at')->count())->toBe(6);
});

test('selected device cart lines retain public keys, cannot change quantity, and can be removed', function () {
    $ctx = selectedDeviceCartContext();
    ['product' => $product, 'variant' => $variant] = selectedDeviceCartProduct($ctx);
    $key = $this->getJson('/api/storefront/v1/products/selected-used-phone/device-options?variant='.$variant->id)->assertOk()->json('data.0.option_key');
    $selection = $this->postJson('/api/storefront/v1/device-selections', ['option_key' => $key])->assertCreated()->json('data.token');
    $added = $this->postJson('/api/storefront/v1/cart/items', [
        'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1, 'device_selection_token' => $selection,
    ])->assertCreated();
    $cartToken = $added->headers->get('X-Storefront-Cart-Token');
    $lineKey = $added->json('data.items.0.key');
    $this->patchJson('/api/storefront/v1/cart/items/'.$lineKey, ['quantity' => 2], ['X-Storefront-Cart-Token' => $cartToken])
        ->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_QUANTITY');
    $this->getJson('/api/storefront/v1/cart', ['X-Storefront-Cart-Token' => $cartToken])->assertJsonPath('data.items.0.key', $lineKey);
    $this->deleteJson('/api/storefront/v1/cart/items/'.$lineKey, [], ['X-Storefront-Cart-Token' => $cartToken])
        ->assertOk()->assertJsonPath('data.item_count', 0);
});

