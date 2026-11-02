<?php

use App\Models\Brand;
use App\Models\Business;
use App\Models\Category;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductActivityLog;
use App\Models\ProductVariant;
use App\Models\SerializedDevice;
use App\Models\User;
use App\Models\WebProductListing;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

function publicationManagementFixture(): array
{
    $staff = User::factory()->create();
    $business = Business::factory()->for($staff, 'owner')->create(['setup_key' => 'initial']);
    $category = Category::factory()->for($business)->create();
    $brand = Brand::factory()->for($business)->create();
    $product = Product::factory()->for($business)->for($category)->for($brand)->create(['name' => 'Managed Phone', 'product_type' => 'variable']);
    $variant = ProductVariant::factory()->for($business)->for($product)->create(['sku' => 'MANAGED-SKU', 'barcode' => '987654321', 'selling_price' => '1234.50']);
    $staff->givePermissionTo(Permission::findOrCreate('products.view', 'web'), Permission::findOrCreate('storefront.publish', 'web'));

    return compact('staff', 'business', 'category', 'brand', 'product', 'variant');
}

test('publication page is authorized paginated searchable and business scoped', function () {
    $f = publicationManagementFixture();
    $this->actingAs($f['staff'])->get('/storefront/products?search=MANAGED-SKU')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('storefront/Products')->has('products.data', 1)
            ->where('products.data.0.name', 'Managed Phone')->where('products.data.0.published', false)
            ->where('products.data.0.reasons', [])->where('products.per_page', 15));
    $this->get('/storefront/products?product_type=variable&category='.$f['category']->id.'&brand='.$f['brand']->id)->assertInertia(fn (Assert $page) => $page->has('products.data', 1));
    $this->get('/storefront/products?product_type=simple')->assertInertia(fn (Assert $page) => $page->has('products.data', 0));
    $this->get('/storefront/products?search=987654321')->assertInertia(fn (Assert $page) => $page->has('products.data', 1));
    $otherBusiness = Business::factory()->create(['setup_key' => 'other']);
    $otherCategory = Category::factory()->for($otherBusiness)->create();
    $other = Product::factory()->for($otherBusiness)->for($otherCategory)->create();
    $this->get('/storefront/products')->assertInertia(fn (Assert $page) => $page->has('products.data', 1));
    $this->put("/storefront/products/{$other->id}", ['status' => 'published', 'slug' => 'foreign'])->assertNotFound();
});

test('staff without either required permission cannot view or change publication', function (array $permissions) {
    $f = publicationManagementFixture();
    $staff = User::factory()->create();
    foreach ($permissions as $permission) {
        $staff->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($staff)->get('/storefront/products')->assertForbidden();
    $this->put("/storefront/products/{$f['product']->id}", ['status' => 'published', 'slug' => 'blocked'])->assertForbidden();
    expect(WebProductListing::count())->toBe(0);
})->with([[[]], [['products.view']], [['storefront.publish']]]);

test('individual publication is idempotent reversible and preserves catalog inventory and devices', function () {
    $f = publicationManagementFixture();
    $location = Location::factory()->for($f['business'])->create();
    $stock = InventoryStock::factory()->for($f['business'])->for($location)->for($f['variant'], 'variant')->create(['quantity_on_hand' => '7.000']);
    $device = SerializedDevice::factory()->for($location)->for($f['variant'], 'variant')->create(['business_id' => $f['business']->id]);
    $other = Product::factory()->for($f['business'])->for($f['category'])->create();
    $before = [$f['product']->fresh()->getAttributes(), $f['variant']->fresh()->getAttributes(), $stock->fresh()->getAttributes(), $device->fresh()->getAttributes()];
    $url = "/storefront/products/{$f['product']->id}";
    $this->actingAs($f['staff'])->put($url, ['status' => 'published', 'slug' => 'managed-phone'])->assertRedirect('/storefront/products');
    $this->put($url, ['status' => 'published', 'slug' => 'managed-phone'])->assertRedirect();
    expect(WebProductListing::count())->toBe(1)->and($other->webListing()->exists())->toBeFalse();
    expect(ProductActivityLog::where('subject_type', 'publication')->count())->toBe(1);
    $this->getJson('/api/storefront/v1/products')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Managed Phone')->assertJsonPath('data.0.starting_price', '1234.50');
    $this->getJson('/api/storefront/v1/products/managed-phone')->assertOk()->assertJsonPath('data.variants.0.starting_price', '1234.50');
    $this->put($url, ['status' => 'draft', 'slug' => 'managed-phone'])->assertRedirect();
    $this->getJson('/api/storefront/v1/products')->assertJsonCount(0, 'data');
    $this->getJson('/api/storefront/v1/products/managed-phone')->assertNotFound();
    expect([$f['product']->fresh()->getAttributes(), $f['variant']->fresh()->getAttributes(), $stock->fresh()->getAttributes(), $device->fresh()->getAttributes()])->toBe($before);
    expect(ProductActivityLog::where('subject_type', 'publication')->count())->toBe(2);
});

test('actual catalog ineligibility is explained and rejected', function (string $reason) {
    $f = publicationManagementFixture();
    match ($reason) {
        'Product is inactive.' => $f['product']->update(['status' => 'inactive']),
        'Service products are excluded from the storefront.' => $f['product']->update(['product_type' => 'service']),
        'Category is inactive or unavailable.' => $f['category']->update(['status' => 'inactive']),
        'Brand is inactive or unavailable.' => $f['brand']->update(['status' => 'inactive']),
        'No active variant exists.' => $f['variant']->update(['status' => 'inactive']),
    };
    $this->actingAs($f['staff'])->get('/storefront/products?publication=ineligible')->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->where('products.data.0.reasons', [$reason]));
    $this->put("/storefront/products/{$f['product']->id}", ['status' => 'published', 'slug' => 'ineligible'])->assertSessionHasErrors(['status' => $reason]);
    expect(WebProductListing::count())->toBe(0);
})->with(['Product is inactive.', 'Service products are excluded from the storefront.', 'Category is inactive or unavailable.', 'Brand is inactive or unavailable.', 'No active variant exists.']);

test('parent publication includes active variants only and preserves existing public copy', function () {
    $f = publicationManagementFixture();
    $second = ProductVariant::factory()->for($f['business'])->for($f['product'])->create(['selling_price' => '2345.00']);
    ProductVariant::factory()->for($f['business'])->for($f['product'])->create(['status' => 'inactive']);
    $url = "/storefront/products/{$f['product']->id}";
    $this->actingAs($f['staff'])->put($url, ['status' => 'published', 'slug' => 'variable-phone', 'public_title' => 'Public Phone'])->assertRedirect();
    $this->put($url, ['status' => 'draft', 'slug' => 'variable-phone'])->assertRedirect();
    $this->put($url, ['status' => 'published', 'slug' => 'variable-phone'])->assertRedirect();
    $this->getJson('/api/storefront/v1/products/variable-phone')->assertJsonCount(2, 'data.variants')->assertJsonPath('data.name', 'Public Phone')->assertJsonPath('data.starting_price', '1234.50');
    $this->get('/storefront/products?publication=published')->assertInertia(fn (Assert $page) => $page->has('products.data', 1));
    $this->get('/storefront/products?publication=unpublished')->assertInertia(fn (Assert $page) => $page->has('products.data', 0));
    $f['variant']->update(['status' => 'inactive']);
    $second->update(['status' => 'inactive']);
    $this->getJson('/api/storefront/v1/products/variable-phone')->assertNotFound();
    $this->put($url, ['status' => 'draft', 'slug' => 'variable-phone'])->assertRedirect();
});
