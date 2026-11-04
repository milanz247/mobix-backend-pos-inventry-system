<?php

use App\Models\Brand;
use App\Models\Business;
use App\Models\Category;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SerializedDevice;
use App\Models\StorefrontDeviceSelection;
use App\Models\User;
use App\Models\WebOrder;
use App\Models\WebProductListing;
use App\ProductCondition;
use App\VariantTrackingMode;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

function storefrontContext(): array
{
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner, 'owner')->create();
    $category = Category::factory()->for($business)->create(['name' => 'Phones']);
    $brand = Brand::factory()->for($business)->create(['name' => 'Test Brand']);
    $location = Location::factory()->for($business)->create();

    return compact('owner', 'business', 'category', 'brand', 'location');
}

function storefrontProduct(array $context, string $name = 'Test Phone', string $slug = 'test-phone', array $variant = []): array
{
    $product = Product::factory()->for($context['business'])->for($context['category'])->create([
        'name' => $name, 'brand_id' => $context['brand']->id,
    ]);
    $option = ProductVariant::factory()->for($context['business'])->for($product)->create(array_replace([
        'name' => '128 GB', 'cost_price' => '500.00', 'selling_price' => '1000.00',
        'minimum_selling_price' => '800.00',
    ], $variant));
    $listing = WebProductListing::query()->create([
        'business_id' => $context['business']->id, 'product_id' => $product->id,
        'slug' => $slug, 'status' => 'published',
    ]);

    return compact('product', 'option', 'listing');
}

function storefrontReserveDevice(array $context, Product $product, ProductVariant $variant, SerializedDevice $device, string $status = 'active'): void
{
    $order = WebOrder::query()->create([
        'business_id' => $context['business']->id,
        'location_id' => $context['location']->id,
        'reference' => (string) Str::ulid(),
        'submission_key' => (string) Str::uuid(),
        'payload_hash' => hash('sha256', Str::random()),
        'status' => 'pending_confirmation',
        'currency' => $context['business']->currency,
        'subtotal' => '1000.00',
        'total' => '1000.00',
    ]);
    $itemId = DB::table('web_order_items')->insertGetId([
        'business_id' => $context['business']->id,
        'web_order_id' => $order->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'line_number' => 1,
        'product_name_snapshot' => $product->name,
        'product_slug_snapshot' => 'reserved-used-phone',
        'variant_name_snapshot' => $variant->name,
        'quantity' => '1.000',
        'unit_price' => '1000.00',
        'line_total' => '1000.00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('web_order_reservations')->insert([
        'business_id' => $context['business']->id,
        'web_order_id' => $order->id,
        'web_order_item_id' => $itemId,
        'location_id' => $context['location']->id,
        'component_variant_id' => $variant->id,
        'serialized_device_id' => $device->id,
        'active_device_id' => $status === 'active' ? $device->id : null,
        'reservation_line_key' => (string) Str::ulid(),
        'quantity' => '1.000',
        'status' => $status,
        'expires_at' => now()->addMinutes(10),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('existing products are unpublished and direct access returns not found', function () {
    $ctx = storefrontContext();
    Product::factory()->for($ctx['business'])->for($ctx['category'])->create();
    $this->getJson('/api/storefront/v1/products')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/storefront/v1/products/unlisted')->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
});

test('staff publication requires its dedicated permission and validates eligibility', function () {
    $ctx = storefrontContext();
    ['product' => $product] = storefrontProduct($ctx);
    $product->webListing()->delete();
    $staff = User::factory()->create();
    $staff->givePermissionTo(Permission::findOrCreate('products.view', 'web'));
    $payload = [
        'status' => 'published', 'slug' => 'public-phone',
        'public_title' => 'Online Phone', 'public_description' => 'Customer description',
        'seo_title' => 'Online Phone SEO', 'seo_description' => 'Search description',
    ];
    $this->actingAs($staff)->put("/products/{$product->id}/web-listing", $payload)->assertForbidden();
    $staff->givePermissionTo(Permission::findOrCreate('storefront.publish', 'web'));
    $this->actingAs($staff)->put("/products/{$product->id}/web-listing", $payload)->assertRedirect();
    expect($product->webListing()->first()->status)->toBe('published');
    $this->getJson('/api/storefront/v1/products/public-phone')->assertJsonPath('data.name', 'Online Phone')
        ->assertJsonPath('data.description', 'Customer description')
        ->assertJsonPath('data.seo.title', 'Online Phone SEO');
    $this->actingAs($staff)->put("/products/{$product->id}/web-listing", ['status' => 'draft', 'slug' => 'public-phone'])->assertRedirect();
    $this->getJson('/api/storefront/v1/products/public-phone')->assertNotFound();
    $ctx['category']->update(['status' => 'inactive']);
    $this->actingAs($staff)->put("/products/{$product->id}/web-listing", $payload)->assertSessionHasErrors('status');
});

test('published products respect product category brand and variant status', function () {
    $ctx = storefrontContext();
    ['product' => $product, 'option' => $option] = storefrontProduct($ctx);
    ProductVariant::factory()->for($ctx['business'])->for($product)->create(['status' => 'inactive']);
    $this->getJson('/api/storefront/v1/products')->assertJsonCount(1, 'data');
    $this->getJson('/api/storefront/v1/products/test-phone')->assertJsonCount(1, 'data.variants');
    $option->update(['status' => 'inactive']);
    $this->getJson('/api/storefront/v1/products')->assertJsonCount(0, 'data');
    $option->update(['status' => 'active']);
    $ctx['brand']->update(['status' => 'inactive']);
    $this->getJson('/api/storefront/v1/products/test-phone')->assertNotFound();
    $ctx['brand']->update(['status' => 'active']);
    $ctx['category']->update(['status' => 'inactive']);
    $this->getJson('/api/storefront/v1/products')->assertJsonCount(0, 'data');
    $ctx['category']->update(['status' => 'active']);
    $product->update(['status' => 'inactive']);
    $this->getJson('/api/storefront/v1/products/test-phone')->assertNotFound();
});

test('slugs are unique per business and public menus only include published products', function () {
    $ctx = storefrontContext();
    storefrontProduct($ctx);
    $other = Product::factory()->for($ctx['business'])->for($ctx['category'])->create();
    $second = ProductVariant::factory()->for($ctx['business'])->for($other)->create();
    expect($second)->not->toBeNull();
    $owner = $ctx['owner'];
    $this->actingAs($owner)->put("/products/{$other->id}/web-listing", ['status' => 'published', 'slug' => 'test-phone'])->assertSessionHasErrors('slug');
    $this->getJson('/api/storefront/v1/categories')->assertJsonCount(1, 'data');
    $this->getJson('/api/storefront/v1/brands')->assertJsonCount(1, 'data');
    $ctx['category']->update(['status' => 'inactive']);
    $this->getJson('/api/storefront/v1/categories')->assertJsonCount(0, 'data');
    $this->getJson('/api/storefront/v1/brands')->assertJsonCount(0, 'data');
});

test('published brand list exposes only the configured public logo URL', function () {
    Storage::fake('public');
    $ctx = storefrontContext();
    storefrontProduct($ctx);
    $ctx['brand']->update(['logo_path' => 'brand-logos/example.webp']);

    $response = $this->getJson('/api/storefront/v1/brands')->assertOk()
        ->assertJsonPath('data.0.name', 'Test Brand')
        ->assertJsonPath('data.0.logo_url', Storage::disk('public')->url('brand-logos/example.webp'));
    expect(array_keys($response->json('data.0')))->toBe(['id', 'name', 'show_on_homepage', 'homepage_order', 'logo_url']);

    $ctx['brand']->update(['show_on_homepage' => false, 'homepage_order' => 12]);
    $this->getJson('/api/storefront/v1/brands')->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.show_on_homepage', false)
        ->assertJsonPath('data.0.homepage_order', 12);
    $ctx['brand']->update(['status' => 'inactive']);
    $this->getJson('/api/storefront/v1/brands')->assertJsonCount(0, 'data');
});

test('catalog filters sorts paginates and rejects invalid inputs', function () {
    $ctx = storefrontContext();
    storefrontProduct($ctx, 'Alpha Phone', 'alpha-phone', ['selling_price' => '100.00', 'condition' => ProductCondition::New]);
    storefrontProduct($ctx, 'Beta Phone', 'beta-phone', ['selling_price' => '200.00', 'condition' => ProductCondition::Used]);
    $this->getJson('/api/storefront/v1/products?q=Beta')->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'beta-phone');
    $this->getJson('/api/storefront/v1/products?category='.$ctx['category']->id.'&brand='.$ctx['brand']->id.'&condition=new')->assertJsonCount(1, 'data');
    $this->getJson('/api/storefront/v1/products?sort=price_desc')->assertOk()->assertJsonPath('data.0.slug', 'beta-phone');
    $this->getJson('/api/storefront/v1/products?sort=price_asc')->assertOk()->assertJsonPath('data.0.slug', 'alpha-phone');
    $this->getJson('/api/storefront/v1/products?per_page=1&page=2')->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
    $this->getJson('/api/storefront/v1/products?per_page=100')->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_FILTER');
    $this->getJson('/api/storefront/v1/products?condition=broken')->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_FILTER');
});

test('public product response uses decimal prices and excludes internal fields', function () {
    $ctx = storefrontContext();
    ['product' => $product] = storefrontProduct($ctx);
    $product->update(['image_path' => 'https://untrusted.example/image.jpg']);
    $response = $this->getJson('/api/storefront/v1/products/test-phone')->assertOk()
        ->assertJsonPath('data.starting_price', '1000.00')
        ->assertJsonPath('data.currency', 'LKR')
        ->assertJsonPath('data.image_url', null);
    $body = $response->getContent();
    foreach (['cost_price', 'minimum_selling_price', 'imei', 'serialized_device_id', 'supplier', 'staff', '500.00', '800.00'] as $secret) {
        expect($body)->not->toContain($secret);
    }
});

test('used serialized starting price reflects eligible devices without exposing identifiers', function () {
    $ctx = storefrontContext();
    ['product' => $product, 'option' => $option] = storefrontProduct($ctx, 'Used Phone', 'used-phone', [
        'condition' => ProductCondition::Used, 'tracking_mode' => 'serialized', 'selling_price' => '1200.00',
    ]);
    $product->update(['product_type' => 'serialized']);
    $this->getJson('/api/storefront/v1/products/used-phone')->assertJsonPath('data.starting_price', null)
        ->assertJsonPath('data.availability', 'check_at_checkout');
    SerializedDevice::factory()->for($ctx['location'])->for($option, 'variant')->create([
        'business_id' => $ctx['business']->id,
        'condition' => ProductCondition::Used, 'unit_selling_price' => '900.00', 'imei' => '123456789012345',
    ]);
    $response = $this->getJson('/api/storefront/v1/products/used-phone')->assertOk()
        ->assertJsonPath('data.starting_price', '900.00')->assertJsonPath('data.availability', 'in_stock');
    expect($response->getContent())->not->toContain('123456789012345');
});

test('used device options return only eligible devices and expose no private inventory fields', function () {
    $ctx = storefrontContext();
    ['product' => $product, 'option' => $variant] = storefrontProduct($ctx, 'Used Phone', 'used-options', [
        'condition' => ProductCondition::Used, 'tracking_mode' => VariantTrackingMode::Serialized,
    ]);
    $product->update(['product_type' => 'serialized']);
    $eligible = SerializedDevice::factory()->for($ctx['location'])->for($variant, 'variant')->create([
        'business_id' => $ctx['business']->id, 'condition' => ProductCondition::Used,
        'unit_selling_price' => '950.00', 'imei' => '359111111111111', 'imei2' => '359222222222222', 'serial_number' => 'PRIVATE-SERIAL',
    ]);
    foreach ([
        ['condition' => ProductCondition::New, 'status' => 'in_stock'],
        ['condition' => ProductCondition::Refurbished, 'status' => 'in_stock'],
        ['condition' => ProductCondition::Used, 'status' => 'sold'],
        ['condition' => ProductCondition::Used, 'status' => 'damaged'],
        ['condition' => ProductCondition::Used, 'status' => 'in_repair'],
        ['condition' => ProductCondition::Used, 'status' => 'returned_to_supplier'],
    ] as $attributes) {
        SerializedDevice::factory()->for($ctx['location'])->for($variant, 'variant')->create(['business_id' => $ctx['business']->id] + $attributes);
    }
    $inactiveLocation = Location::factory()->for($ctx['business'])->create(['status' => 'inactive']);
    SerializedDevice::factory()->for($inactiveLocation)->for($variant, 'variant')->create([
        'business_id' => $ctx['business']->id, 'condition' => ProductCondition::Used,
    ]);

    $response = $this->getJson('/api/storefront/v1/products/used-options/device-options?variant='.$variant->id)
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.price', '950.00')
        ->assertJsonPath('data.0.condition', 'Used')
        ->assertJsonPath('data.0.availability', 'in_stock')
        ->assertJsonPath('data.0.storage_capacity', $variant->storage_capacity)
        ->assertJsonPath('data.0.color', $variant->color);
    $body = $response->getContent();
    foreach ([(string) $eligible->id, '359111111111111', '359222222222222', 'PRIVATE-SERIAL', 'unit_cost', 'location_id', 'location_name', 'supplier', 'staff', 'reservation'] as $private) {
        expect($body)->not->toContain($private);
    }
});

test('used device options use variant price fallback and reject unpublished products and invalid variants', function () {
    $ctx = storefrontContext();
    ['product' => $product, 'option' => $variant] = storefrontProduct($ctx, 'Used Fallback', 'used-fallback', [
        'condition' => ProductCondition::Used, 'tracking_mode' => VariantTrackingMode::Serialized, 'selling_price' => '1200.00',
    ]);
    $product->update(['product_type' => 'serialized']);
    SerializedDevice::factory()->for($ctx['location'])->for($variant, 'variant')->create([
        'business_id' => $ctx['business']->id, 'condition' => ProductCondition::Used, 'unit_selling_price' => null,
    ]);
    $this->getJson('/api/storefront/v1/products/used-fallback/device-options?variant='.$variant->id)
        ->assertOk()->assertJsonPath('data.0.price', '1200.00');
    $newVariant = ProductVariant::factory()->for($ctx['business'])->for($product)->create(['condition' => ProductCondition::New, 'tracking_mode' => VariantTrackingMode::Serialized]);
    $this->getJson('/api/storefront/v1/products/used-fallback/device-options?variant='.$newVariant->id)->assertUnprocessable();
    ['option' => $usedVariantOnDifferentProduct] = storefrontProduct($ctx, 'Another Used Phone', 'another-used-phone', [
        'condition' => ProductCondition::Used, 'tracking_mode' => VariantTrackingMode::Serialized,
    ]);
    $this->getJson('/api/storefront/v1/products/used-fallback/device-options?variant='.$usedVariantOnDifferentProduct->id)->assertUnprocessable();
    $product->webListing()->update(['status' => 'draft']);
    $this->getJson('/api/storefront/v1/products/used-fallback/device-options?variant='.$variant->id)->assertNotFound();
});

test('device selection keys are opaque scoped and create ten minute hashed tokens without reservations', function () {
    $ctx = storefrontContext();
    ['product' => $product, 'option' => $variant] = storefrontProduct($ctx, 'Used Token Phone', 'used-token-phone', [
        'condition' => ProductCondition::Used, 'tracking_mode' => VariantTrackingMode::Serialized,
    ]);
    $product->update(['product_type' => 'serialized']);
    $device = SerializedDevice::factory()->for($ctx['location'])->for($variant, 'variant')->create([
        'business_id' => $ctx['business']->id, 'condition' => ProductCondition::Used, 'unit_selling_price' => '875.00',
        'imei' => '358333333333333', 'serial_number' => 'OPAQUE-TEST-SERIAL',
    ]);
    $option = $this->getJson('/api/storefront/v1/products/used-token-phone/device-options?variant='.$variant->id)
        ->assertOk()->json('data.0');
    expect($option['option_key'])->not->toContain((string) $device->id, $device->imei, 'OPAQUE-TEST-SERIAL');
    $selection = $this->postJson('/api/storefront/v1/device-selections', ['option_key' => $option['option_key']])
        ->assertCreated()->assertJsonStructure(['data' => ['token', 'expires_at']])->json('data');
    expect(strlen($selection['token']))->toBe(64)
        ->and(StorefrontDeviceSelection::query()->where('business_id', $ctx['business']->id)->count())->toBe(1)
        ->and(StorefrontDeviceSelection::query()->first()->token_hash)->toBe(hash('sha256', $selection['token']))
        ->and(StorefrontDeviceSelection::query()->first()->selected_price)->toBe('875.00')
        ->and(StorefrontDeviceSelection::query()->first()->expires_at->diffInMinutes(now()))->toBeLessThanOrEqual(10)
        ->and(StorefrontDeviceSelection::query()->first()->expires_at->diffInMinutes(now()))->toBeGreaterThanOrEqual(9)
        ->and(DB::table('web_order_reservations')->count())->toBe(0);
    expect($selection['token'])->not->toContain((string) $device->id, $device->imei, 'OPAQUE-TEST-SERIAL', '875.00');
});

test('invalid or stale device option keys fail generically and recheck price status and location', function () {
    $ctx = storefrontContext();
    ['product' => $product, 'option' => $variant] = storefrontProduct($ctx, 'Rechecked Used Phone', 'rechecked-used', [
        'condition' => ProductCondition::Used, 'tracking_mode' => VariantTrackingMode::Serialized,
    ]);
    $product->update(['product_type' => 'serialized']);
    $device = SerializedDevice::factory()->for($ctx['location'])->for($variant, 'variant')->create([
        'business_id' => $ctx['business']->id, 'condition' => ProductCondition::Used, 'unit_selling_price' => '999.00',
    ]);
    $key = $this->getJson('/api/storefront/v1/products/rechecked-used/device-options?variant='.$variant->id)->json('data.0.option_key');
    $this->postJson('/api/storefront/v1/device-selections', ['option_key' => substr($key, 0, -1).'x'])->assertUnprocessable();
    $device->update(['unit_selling_price' => '1000.00']);
    $this->postJson('/api/storefront/v1/device-selections', ['option_key' => $key])->assertUnprocessable()
        ->assertJsonPath('error.message', 'This device option is no longer available. Refresh the options and try again.');

    $key = $this->getJson('/api/storefront/v1/products/rechecked-used/device-options?variant='.$variant->id)->json('data.0.option_key');
    $device->update(['status' => 'sold']);
    $this->postJson('/api/storefront/v1/device-selections', ['option_key' => $key])->assertUnprocessable();

    $device->update(['status' => 'in_stock']);
    $key = $this->getJson('/api/storefront/v1/products/rechecked-used/device-options?variant='.$variant->id)->json('data.0.option_key');
    $ctx['location']->update(['status' => 'inactive']);
    $this->postJson('/api/storefront/v1/device-selections', ['option_key' => $key])->assertUnprocessable();
});

test('device option keys reject expiry and cross-business scope', function () {
    $ctx = storefrontContext();
    ['product' => $product, 'option' => $variant] = storefrontProduct($ctx, 'Scoped Used Phone', 'scoped-used-phone', [
        'condition' => ProductCondition::Used, 'tracking_mode' => VariantTrackingMode::Serialized,
    ]);
    $product->update(['product_type' => 'serialized']);
    $device = SerializedDevice::factory()->for($ctx['location'])->for($variant, 'variant')->create([
        'business_id' => $ctx['business']->id, 'condition' => ProductCondition::Used,
    ]);
    $key = $this->getJson('/api/storefront/v1/products/scoped-used-phone/device-options?variant='.$variant->id)->json('data.0.option_key');
    $expiresAtBoundaryKey = Crypt::encryptString(json_encode([
        'business' => $ctx['business']->id, 'product' => $product->id, 'variant' => $variant->id,
        'device' => $device->id, 'price' => '1000.00', 'expires' => now()->timestamp,
    ], JSON_THROW_ON_ERROR));
    $this->postJson('/api/storefront/v1/device-selections', ['option_key' => $expiresAtBoundaryKey])->assertUnprocessable();
    $this->travel(11)->minutes();
    $this->postJson('/api/storefront/v1/device-selections', ['option_key' => $key])->assertUnprocessable();

    $wrongScopeKey = Crypt::encryptString(json_encode([
        'business' => $ctx['business']->id + 1000, 'product' => $product->id, 'variant' => $variant->id,
        'device' => $device->id, 'price' => '1000.00', 'expires' => now()->addMinute()->timestamp,
    ], JSON_THROW_ON_ERROR));
    $this->postJson('/api/storefront/v1/device-selections', ['option_key' => $wrongScopeKey])->assertUnprocessable()
        ->assertJsonPath('error.message', 'This device option is no longer available. Refresh the options and try again.');
});

test('active device reservations hide options while transitioned expired reservations do not', function () {
    $ctx = storefrontContext();
    ['product' => $product, 'option' => $variant] = storefrontProduct($ctx, 'Reserved Used Phone', 'reserved-used-phone', [
        'condition' => ProductCondition::Used, 'tracking_mode' => VariantTrackingMode::Serialized,
    ]);
    $product->update(['product_type' => 'serialized']);
    $device = SerializedDevice::factory()->for($ctx['location'])->for($variant, 'variant')->create([
        'business_id' => $ctx['business']->id, 'condition' => ProductCondition::Used,
    ]);

    storefrontReserveDevice($ctx, $product, $variant, $device);
    $this->getJson('/api/storefront/v1/products/reserved-used-phone/device-options?variant='.$variant->id)
        ->assertOk()->assertJsonCount(0, 'data');

    DB::table('web_order_reservations')->where('serialized_device_id', $device->id)->update([
        'status' => 'expired', 'active_device_id' => null, 'transitioned_at' => now(), 'updated_at' => now(),
    ]);
    $this->getJson('/api/storefront/v1/products/reserved-used-phone/device-options?variant='.$variant->id)
        ->assertOk()->assertJsonCount(1, 'data');
});

test('selection creation rejects a device reserved after options were loaded', function () {
    $ctx = storefrontContext();
    ['product' => $product, 'option' => $variant] = storefrontProduct($ctx, 'Race Used Phone', 'race-used-phone', [
        'condition' => ProductCondition::Used, 'tracking_mode' => VariantTrackingMode::Serialized,
    ]);
    $product->update(['product_type' => 'serialized']);
    $device = SerializedDevice::factory()->for($ctx['location'])->for($variant, 'variant')->create([
        'business_id' => $ctx['business']->id, 'condition' => ProductCondition::Used,
    ]);
    $key = $this->getJson('/api/storefront/v1/products/race-used-phone/device-options?variant='.$variant->id)
        ->assertOk()->json('data.0.option_key');

    storefrontReserveDevice($ctx, $product, $variant, $device);
    $this->postJson('/api/storefront/v1/device-selections', ['option_key' => $key])
        ->assertUnprocessable()
        ->assertJsonPath('error.message', 'This device option is no longer available. Refresh the options and try again.');
    expect(StorefrontDeviceSelection::query()->count())->toBe(0)
        ->and($device->fresh()->status)->toBe('in_stock');
});

test('selection creation rejects a used device whose condition changes after options were loaded', function () {
    $ctx = storefrontContext();
    ['product' => $product, 'option' => $variant] = storefrontProduct($ctx, 'Condition Changed Phone', 'condition-changed-phone', [
        'condition' => ProductCondition::Used, 'tracking_mode' => VariantTrackingMode::Serialized,
    ]);
    $product->update(['product_type' => 'serialized']);
    $device = SerializedDevice::factory()->for($ctx['location'])->for($variant, 'variant')->create([
        'business_id' => $ctx['business']->id, 'condition' => ProductCondition::Used,
    ]);
    $key = $this->getJson('/api/storefront/v1/products/condition-changed-phone/device-options?variant='.$variant->id)
        ->assertOk()->json('data.0.option_key');
    $device->update(['condition' => ProductCondition::Refurbished]);

    $this->postJson('/api/storefront/v1/device-selections', ['option_key' => $key])->assertUnprocessable();
});

test('catalog is scoped to the initial business', function () {
    $ctx = storefrontContext();
    storefrontProduct($ctx);
    $otherOwner = User::factory()->create();
    $otherBusiness = Business::factory()->for($otherOwner, 'owner')->create(['setup_key' => 'secondary']);
    $otherCategory = Category::factory()->for($otherBusiness)->create();
    $otherProduct = Product::factory()->for($otherBusiness)->for($otherCategory)->create();
    ProductVariant::factory()->for($otherBusiness)->for($otherProduct)->create();
    WebProductListing::query()->create(['business_id' => $otherBusiness->id, 'product_id' => $otherProduct->id, 'slug' => 'other-product', 'status' => 'published']);
    $this->getJson('/api/storefront/v1/products')->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'test-phone');
    $this->getJson('/api/storefront/v1/products/other-product')->assertNotFound();
});

test('public image URLs come only from approved product image paths', function () {
    $ctx = storefrontContext();
    ['product' => $product] = storefrontProduct($ctx);
    Storage::fake('public');
    Storage::disk('public')->put('product-images/example.webp', 'image data');
    $product->update(['image_path' => 'product-images/example.webp']);
    $this->getJson('/api/storefront/v1/products/test-phone')->assertJsonPath('data.image_url', '/storage/product-images/example.webp');
    $product->update(['image_path' => '../private.jpg']);
    $this->getJson('/api/storefront/v1/products/test-phone')->assertJsonPath('data.image_url', null);
});

test('catalog requests are rate limited with a consistent error', function () {
    storefrontContext();
    RateLimiter::for('storefront-catalog', fn () => Limit::perMinute(1)->by('catalog-test'));
    $this->getJson('/api/storefront/v1/categories')->assertOk();
    $this->getJson('/api/storefront/v1/categories')->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
});

test('quantity and serialized availability use location stock without exposing counts', function () {
    $ctx = storefrontContext();
    ['option' => $option] = storefrontProduct($ctx);
    $this->getJson('/api/storefront/v1/products/test-phone/availability')->assertJsonPath('data.variants.0.status', 'out_of_stock');
    InventoryStock::factory()->for($ctx['business'])->for($ctx['location'])->for($option, 'variant')->create(['quantity_on_hand' => '2.000']);
    $response = $this->getJson('/api/storefront/v1/products/test-phone/availability')->assertJsonPath('data.variants.0.status', 'in_stock');
    expect($response->getContent())->not->toContain('quantity_on_hand');
});

test('bundles use component capacity and do not create inventory movements', function () {
    $ctx = storefrontContext();
    ['product' => $product, 'option' => $bundle] = storefrontProduct($ctx);
    $product->update(['product_type' => 'combo']);
    ['option' => $component] = storefrontProduct($ctx, 'Component', 'component');
    DB::table('bundle_components')->insert([
        'business_id' => $ctx['business']->id, 'bundle_variant_id' => $bundle->id,
        'component_variant_id' => $component->id, 'quantity' => '1.000',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->getJson('/api/storefront/v1/products/test-phone/availability')->assertJsonPath('data.variants.0.status', 'out_of_stock');
    InventoryStock::factory()->for($ctx['business'])->for($ctx['location'])->for($component, 'variant')->create(['quantity_on_hand' => '3.000']);
    $this->getJson('/api/storefront/v1/products/test-phone/availability')->assertJsonPath('data.variants.0.status', 'in_stock');
    expect(DB::table('stock_movements')->count())->toBe(0);
});

test('anonymous catalog access does not authorize staff routes', function () {
    storefrontContext();
    $this->getJson('/api/storefront/v1/products')->assertOk();
    $this->get('/products')->assertRedirect('/login');
    $this->get('/pos')->assertRedirect('/login');
    $this->get('/inventory')->assertRedirect('/login');
    $this->get('/customers')->assertRedirect('/login');
    $this->get('/sales')->assertRedirect('/login');
});

test('price sorting matches sellable used device prices including variant fallback', function () {
    $ctx = storefrontContext();
    ['option' => $first] = storefrontProduct($ctx, 'Fallback Phone', 'fallback-phone', [
        'tracking_mode' => VariantTrackingMode::Serialized,
        'condition' => ProductCondition::Used, 'selling_price' => '100.00',
    ]);
    ['option' => $second] = storefrontProduct($ctx, 'Priced Phone', 'priced-phone', [
        'tracking_mode' => VariantTrackingMode::Serialized,
        'condition' => ProductCondition::Used, 'selling_price' => '200.00',
    ]);
    foreach ([[$first, null], [$first, '300.00'], [$second, '150.00']] as [$variant, $price]) {
        SerializedDevice::factory()->for($ctx['location'])->for($variant, 'variant')->create([
            'business_id' => $ctx['business']->id, 'condition' => ProductCondition::Used,
            'unit_selling_price' => $price,
        ]);
    }
    $this->getJson('/api/storefront/v1/products?sort=price_asc')->assertOk()
        ->assertJsonPath('data.0.slug', 'fallback-phone')->assertJsonPath('data.0.starting_price', '100.00');
    $this->getJson('/api/storefront/v1/products?sort=price_desc')->assertOk()
        ->assertJsonPath('data.0.slug', 'priced-phone')->assertJsonPath('data.0.starting_price', '150.00');
});
