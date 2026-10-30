<?php

use App\Actions\Catalog\SaveProduct;
use App\Models\Brand;
use App\Models\BundleComponent;
use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\User;
use App\Support\PermissionCatalog;
use Database\Seeders\CatalogDefaultsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

function catalogManager(string ...$permissions): User
{
    $owner = User::factory()->create();
    $user = User::factory()->create();
    Business::factory()->for($owner, 'owner')->create();

    foreach (PermissionCatalog::legacyGrants() as $old => $new) {
        if (in_array($old, $permissions, true)) {
            $permissions = [...$permissions, ...$new];
        }
    }
    foreach ($permissions as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $user->refresh();
}

/** @return array<string, mixed> */
function validProductPayload(Category $category, array $overrides = []): array
{
    return array_replace([
        'name' => 'USB-C Charger',
        'category_id' => $category->id,
        'brand_id' => null,
        'product_type' => 'simple',
        'description' => 'Fast charger',
        'status' => 'active',
        'variants' => [[
            'name' => 'Default',
            'sku' => 'CHG-USBC-001',
            'barcode' => null,
            'model_number' => null,
            'storage_capacity' => null,
            'color' => null,
            'other_attributes' => null,
            'tracking_mode' => 'quantity_based',
            'unit' => 'piece',
            'condition' => null,
            'cost_price' => '2500.00',
            'selling_price' => '4000.00',
            'minimum_selling_price' => null,
            'status' => 'active',
        ]],
    ], $overrides);
}

test('creating a simple product creates one default sellable variant with separate prices', function () {
    $user = catalogManager('products.create', 'variants.manage');
    $category = Category::factory()->for(Business::query()->sole())->create();
    $brand = Brand::factory()->for(Business::query()->sole())->create();

    $this->actingAs($user)
        ->post(route('products.store'), validProductPayload($category, ['brand_id' => $brand->id]))
        ->assertRedirect(route('products.index'))
        ->assertSessionHasNoErrors();

    $product = Product::query()->sole();
    $variant = ProductVariant::query()->sole();

    expect($product->product_type->value)->toBe('simple')
        ->and($product->category_id)->toBe($category->id)
        ->and($product->brand_id)->toBe($brand->id)
        ->and($variant->product_id)->toBe($product->id)
        ->and($variant->sku)->toMatch('/^NM-[A-Z0-9]{12}$/')
        ->and($variant->sku)->not->toBe('CHG-USBC-001')
        ->and($variant->cost_price)->toBe('2500.00')
        ->and($variant->selling_price)->toBe('4000.00');
});

test('creating a variable product saves distinct variant identifiers and prices', function () {
    $user = catalogManager('products.create', 'variants.manage');
    $category = Category::factory()->for(Business::query()->sole())->create();
    $payload = validProductPayload($category, [
        'name' => 'Galaxy S25',
        'product_type' => 'variable',
        'variants' => [
            ['name' => '128 GB · Black', 'sku' => 'S25-128-BLK', 'barcode' => '100000000001', 'tracking_mode' => 'quantity_based', 'unit' => 'piece', 'condition' => null, 'cost_price' => '250000.00', 'selling_price' => '300000.00', 'minimum_selling_price' => '280000.00', 'status' => 'active'],
            ['name' => '256 GB · Blue', 'sku' => 'S25-256-BLU', 'barcode' => '100000000002', 'tracking_mode' => 'quantity_based', 'unit' => 'piece', 'condition' => null, 'cost_price' => '275000.00', 'selling_price' => '330000.00', 'minimum_selling_price' => null, 'status' => 'active'],
        ],
    ]);

    $this->actingAs($user)->post(route('products.store'), $payload)
        ->assertRedirect(route('products.index'))
        ->assertSessionHasNoErrors();

    $variants = Product::query()->sole()->variants()->orderBy('barcode')->get();

    expect($variants)->toHaveCount(2)
        ->and($variants->pluck('sku')->unique())->toHaveCount(2)
        ->and($variants->every(fn (ProductVariant $variant): bool => preg_match('/^NM-[A-Z0-9]{12}$/', $variant->sku) === 1))->toBeTrue()
        ->and($variants->first()?->selling_price)->toBe('300000.00')
        ->and($variants->last()?->cost_price)->toBe('275000.00');
});

test('creating a serialized product configures its model variants without creating device or stock records', function () {
    $user = catalogManager('products.create', 'variants.manage');
    $category = Category::factory()->for(Business::query()->sole())->create();
    $payload = validProductPayload($category, [
        'name' => 'iPhone 16 Pro',
        'product_type' => 'serialized',
        'variants' => [[
            'name' => '256 GB · Natural Titanium',
            'sku' => 'IP16P-256-NT',
            'barcode' => '194949000001',
            'model_number' => 'A3294',
            'storage_capacity' => '256 GB',
            'color' => 'Natural Titanium',
            'tracking_mode' => 'serialized',
            'unit' => 'piece',
            'condition' => 'new',
            'cost_price' => '300000.00',
            'selling_price' => '350000.00',
            'minimum_selling_price' => '330000.00',
            'status' => 'active',
        ]],
    ]);

    $this->actingAs($user)->post(route('products.store'), $payload)
        ->assertRedirect(route('products.index'))
        ->assertSessionHasNoErrors();

    $variant = ProductVariant::query()->sole();

    expect($variant->tracking_mode->value)->toBe('serialized')
        ->and($variant->condition?->value)->toBe('new')
        ->and($variant->storage_capacity)->toBe('256 GB')
        ->and($variant->getAttributes())->not->toHaveKeys(['imei', 'quantity', 'stock_quantity']);
});

test('variant validation rejects duplicate barcode and a minimum above selling price', function () {
    $user = catalogManager('products.create', 'variants.manage');
    $business = Business::query()->sole();
    $category = Category::factory()->for($business)->create();
    $payload = validProductPayload($category, [
        'product_type' => 'variable',
        'variants' => [
            ['name' => '128 GB', 'sku' => 'DUPLICATE', 'barcode' => '900000000001', 'tracking_mode' => 'quantity_based', 'unit' => 'piece', 'cost_price' => '10.00', 'selling_price' => '20.00', 'minimum_selling_price' => '21.00', 'status' => 'active'],
            ['name' => '256 GB', 'sku' => 'DUPLICATE', 'barcode' => '900000000001', 'tracking_mode' => 'quantity_based', 'unit' => 'piece', 'cost_price' => '11.00', 'selling_price' => '22.00', 'status' => 'active'],
        ],
    ]);

    $this->actingAs($user)
        ->from(route('products.create'))
        ->post(route('products.store'), $payload)
        ->assertSessionHasErrors([
            'variants.1.barcode',
            'variants.0.minimum_selling_price',
        ]);

    expect(Product::query()->count())->toBe(0);
});

test('variant validation rejects an existing barcode and negative prices', function () {
    $user = catalogManager('products.create', 'variants.manage');
    $business = Business::query()->sole();
    $category = Category::factory()->for($business)->create();
    $existingProduct = Product::factory()->for($business)->for($category)->create();
    ProductVariant::factory()->for($business)->for($existingProduct)->create([
        'sku' => 'EXISTING-SKU',
        'barcode' => '200000000001',
    ]);
    $payload = validProductPayload($category);
    $payload['variants'][0]['sku'] = 'existing-sku';
    $payload['variants'][0]['barcode'] = '200000000001';
    $payload['variants'][0]['cost_price'] = '-0.01';

    $this->actingAs($user)
        ->post(route('products.store'), $payload)
        ->assertSessionHasErrors([
            'variants.0.barcode',
            'variants.0.cost_price',
        ]);

    expect(Product::query()->count())->toBe(1);
});

test('product search and filters match variant sku, barcode and model details', function () {
    $user = catalogManager('products.view');
    $business = Business::query()->sole();
    $category = Category::factory()->for($business)->create();
    $product = Product::factory()->for($business)->for($category)->variable()->create(['name' => 'Galaxy S25']);
    ProductVariant::factory()->for($business)->for($product)->create([
        'name' => '256 GB Blue', 'sku' => 'GAL-S25-256', 'barcode' => '9988776655', 'storage_capacity' => '256 GB',
    ]);

    $this->actingAs($user)
        ->get(route('products.index', ['search' => 'GAL-S25-256', 'category_id' => $category->id, 'product_type' => 'variable']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('catalog/products/Index')
            ->has('products.data', 1)
            ->where('products.data.0.id', $product->id)
            ->where('suggestions.0.sku', 'GAL-S25-256'));
});

test('catalogue search includes inactive variants when managing inactive products', function () {
    $user = catalogManager('products.view');
    $business = Business::query()->sole();
    $category = Category::factory()->for($business)->create(['status' => 'inactive']);
    $product = Product::factory()->for($business)->for($category)->create([
        'name' => 'Archived handset',
        'status' => 'inactive',
    ]);
    ProductVariant::factory()->for($business)->for($product)->create([
        'sku' => 'ARCHIVED-SKU-001',
        'status' => 'inactive',
    ]);

    $this->actingAs($user)
        ->get(route('products.index', ['search' => 'ARCHIVED-SKU-001', 'status' => 'inactive']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.id', $product->id)
            ->where('products.data.0.variants_count', 0)
            ->has('categories', 1)
            ->where('categories.0.id', $category->id));
});

test('product pagination contains fifteen entries and preserves search filters', function () {
    $user = catalogManager('products.view');
    $business = Business::query()->sole();
    $category = Category::factory()->for($business)->create();

    Product::factory()->count(16)->for($business)->for($category)->create();

    $this->actingAs($user)
        ->get(route('products.index', ['category_id' => $category->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 15)
            ->where('products.total', 16)
            ->where('products.per_page', 15));
});

test('authorized users can view product details and unauthorized users cannot use the direct route', function () {
    $user = catalogManager('products.view');
    $business = Business::query()->sole();
    $category = Category::factory()->for($business)->create();
    $product = Product::factory()->for($business)->for($category)->create();
    ProductVariant::factory()->for($business)->for($product)->create(['sku' => 'DETAIL-001']);

    $this->actingAs($user)->get(route('products.show', $product))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('catalog/products/Show')
            ->where('product.id', $product->id)
            ->where('product.variants.0.sku', 'DETAIL-001'));

    $user->revokePermissionTo('products.view');
    $this->actingAs($user)->get(route('products.show', $product))->assertForbidden();
});

test('database constraints reject variant links across business boundaries', function () {
    $business = Business::factory()->create();
    $otherBusiness = Business::factory()->for(User::factory(), 'owner')->make();
    $otherBusiness->forceFill(['setup_key' => 'test-business'])->save();
    $category = Category::factory()->for($business)->create();
    $product = Product::factory()->for($business)->for($category)->create();

    expect(fn () => DB::table('product_variants')->insert([
        'business_id' => $otherBusiness->id,
        'product_id' => $product->id,
        'name' => 'Default',
        'sku' => 'CROSS-001',
        'tracking_mode' => 'quantity_based',
        'unit' => 'piece',
        'cost_price' => '1.00',
        'selling_price' => '2.00',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('authorized users can create categories and brands and duplicate names are rejected', function () {
    $user = catalogManager('categories.create', 'brands.create');

    $this->actingAs($user)->post(route('categories.store'), [
        'name' => 'Accessories', 'description' => 'Phone accessories', 'status' => 'active',
    ])->assertRedirect(route('categories.index'))->assertSessionHasNoErrors();

    $this->actingAs($user)->post(route('categories.store'), [
        'name' => 'Accessories', 'description' => null, 'status' => 'active',
    ])->assertSessionHasErrors('name');

    $this->actingAs($user)->post(route('brands.store'), [
        'name' => 'Samsung', 'description' => null, 'status' => 'active',
    ])->assertRedirect(route('brands.index'))->assertSessionHasNoErrors();

    expect(Category::query()->where('name', 'Accessories')->count())->toBe(1)
        ->and(Brand::query()->where('name', 'Samsung')->count())->toBe(1);
});

test('combo products save component quantities without creating their own stock', function () {
    $user = catalogManager('products.view', 'products.create', 'variants.manage', 'bundles.manage');
    $business = Business::query()->sole();
    $category = Category::factory()->for($business)->create();
    $componentProduct = Product::factory()->for($business)->for($category)->create(['name' => 'Protective case']);
    $componentVariant = ProductVariant::factory()->for($business)->for($componentProduct)->create([
        'name' => 'Black', 'sku' => 'CASE-BLK', 'cost_price' => '1500.00', 'selling_price' => '3000.00',
    ]);
    $bundlePayload = validProductPayload($category, [
        'name' => 'Phone starter bundle',
        'product_type' => 'combo',
        'variants' => [[
            'name' => 'Standard bundle', 'sku' => 'BUNDLE-001', 'tracking_mode' => 'quantity_based',
            'unit' => 'piece',
            'condition' => null, 'cost_price' => '0.00', 'selling_price' => '5000.00',
            'minimum_selling_price' => null, 'status' => 'active',
            'components' => [['component_variant_id' => $componentVariant->id, 'quantity' => '2']],
        ]],
    ]);

    $this->actingAs($user)->post(route('products.store'), $bundlePayload)
        ->assertRedirect(route('products.index'))
        ->assertSessionHasNoErrors();

    $bundle = Product::query()->where('product_type', 'combo')->sole();
    $bundleVariant = $bundle->variants()->sole();
    $this->assertDatabaseHas('bundle_components', [
        'bundle_variant_id' => $bundleVariant->id,
        'component_variant_id' => $componentVariant->id,
        'quantity' => '2.000',
    ]);
    expect($bundle->getAttributes())->not->toHaveKey('stock_quantity');

    $this->actingAs($user)->get(route('products.show', $bundle))
        ->assertInertia(fn (Assert $page) => $page
            ->where('product.variants.0.estimated_component_cost', '3000.00000'));
});

test('combo validation refuses nested bundles and bundle permission is required', function () {
    $user = catalogManager('products.create', 'variants.manage', 'bundles.manage');
    $business = Business::query()->sole();
    $category = Category::factory()->for($business)->create();
    $comboProduct = Product::factory()->for($business)->for($category)->combo()->create();
    $comboVariant = ProductVariant::factory()->for($business)->for($comboProduct)->create(['sku' => 'NESTED-COMPONENT']);
    $payload = validProductPayload($category, [
        'product_type' => 'combo',
        'variants' => [[
            'name' => 'Bundle', 'sku' => 'BUNDLE-2', 'tracking_mode' => 'quantity_based',
            'unit' => 'piece',
            'condition' => null, 'cost_price' => '0.00', 'selling_price' => '100.00', 'status' => 'active',
            'components' => [['component_variant_id' => $comboVariant->id, 'quantity' => '1']],
        ]],
    ]);

    $this->actingAs($user)
        ->from(route('products.create'))
        ->post(route('products.store'), $payload)
        ->assertSessionHasErrors('variants.0.components');

    $componentProduct = Product::factory()->for($business)->for($category)->create(['name' => 'Valid Component']);
    $componentVariant = ProductVariant::factory()->for($business)->for($componentProduct)->create(['sku' => 'VALID-COMPONENT']);
    $payload['variants'][0]['components'][0]['component_variant_id'] = $componentVariant->id;
    $user->revokePermissionTo('bundles.manage');

    $this->actingAs($user)->post(route('products.store'), $payload)->assertForbidden();
});

test('active combos reject inactive component variants', function () {
    $user = catalogManager('products.create', 'variants.manage', 'bundles.manage');
    $business = Business::query()->sole();
    $category = Category::factory()->for($business)->create();
    $componentProduct = Product::factory()->for($business)->for($category)->create();
    $componentVariant = ProductVariant::factory()->for($business)->for($componentProduct)->create([
        'sku' => 'INACTIVE-COMPONENT',
        'status' => 'inactive',
    ]);
    $payload = validProductPayload($category, [
        'product_type' => 'combo',
        'variants' => [[
            'name' => 'Bundle', 'sku' => 'BUNDLE-INACTIVE', 'tracking_mode' => 'quantity_based',
            'unit' => 'piece',
            'condition' => null, 'cost_price' => '0.00', 'selling_price' => '100.00', 'status' => 'active',
            'components' => [['component_variant_id' => $componentVariant->id, 'quantity' => '1']],
        ]],
    ]);

    $this->actingAs($user)
        ->from(route('products.create'))
        ->post(route('products.store'), $payload)
        ->assertSessionHasErrors('variants.0.components');

    expect(Product::query()->where('product_type', 'combo')->count())->toBe(0);
});

test('product update keeps variant identity and safely replaces its image', function () {
    Storage::fake('public');
    $user = catalogManager('products.create', 'products.update', 'variants.manage');
    $business = Business::query()->sole();
    $category = Category::factory()->for($business)->create();
    $payload = validProductPayload($category, [
        'image' => UploadedFile::fake()->image('charger.jpg', 600, 600)->size(120),
    ]);

    $this->actingAs($user)->post(route('products.store'), $payload)->assertSessionHasNoErrors();
    $product = Product::query()->sole();
    $variant = $product->variants()->sole();
    $originalSku = $variant->sku;
    $oldImagePath = $product->image_path;
    Storage::disk('public')->assertExists($oldImagePath);

    $updatedPayload = validProductPayload($category, [
        'name' => 'Updated USB-C Charger',
        'image' => UploadedFile::fake()->image('replacement.webp', 600, 600)->size(120),
        'variants' => [[
            'id' => $variant->id,
            'name' => 'Default',
            'sku' => 'CHG-USBC-001',
            'barcode' => null,
            'tracking_mode' => 'quantity_based',
            'unit' => 'piece',
            'condition' => null,
            'cost_price' => '2600.00',
            'selling_price' => '4200.00',
            'minimum_selling_price' => null,
            'status' => 'active',
        ]],
    ]);

    $this->actingAs($user)
        ->post(route('products.update', $product), ['_method' => 'PUT', ...$updatedPayload])
        ->assertRedirect(route('products.index'))
        ->assertSessionHasNoErrors();

    $product->refresh();
    expect($product->name)->toBe('Updated USB-C Charger')
        ->and($product->variants()->sole()->cost_price)->toBe('2600.00')
        ->and($product->variants()->sole()->sku)->toBe($originalSku)
        ->and($product->image_path)->not->toBe($oldImagePath);
    Storage::disk('public')->assertMissing($oldImagePath);
    Storage::disk('public')->assertExists($product->image_path);
});

test('product image validation checks image content extension and size', function () {
    $user = catalogManager('products.create', 'variants.manage');
    $category = Category::factory()->for(Business::query()->sole())->create();

    $this->actingAs($user)->post(route('products.store'), validProductPayload($category, [
        'image' => UploadedFile::fake()->image('disguised.txt'),
    ]))->assertSessionHasErrors('image');

    $this->actingAs($user)->post(route('products.store'), validProductPayload($category, [
        'image' => UploadedFile::fake()->image('oversized.jpg')->size(5121),
    ]))->assertSessionHasErrors('image');

    expect(Product::query()->count())->toBe(0);
});

test('product and variants roll back together when a database write fails', function () {
    $business = Business::factory()->create();
    $category = Category::factory()->for($business)->create();
    $attributes = validProductPayload($category, [
        'product_type' => 'combo',
        'variants' => [[
            'name' => 'Invalid bundle',
            'tracking_mode' => 'quantity_based',
            'unit' => 'pack',
            'condition' => null,
            'cost_price' => '10.00',
            'selling_price' => '20.00',
            'status' => 'active',
            'components' => [['component_variant_id' => PHP_INT_MAX, 'quantity' => '1']],
        ]],
    ]);

    expect(fn () => app(SaveProduct::class)->handle($business, $attributes))
        ->toThrow(QueryException::class);

    expect(Product::query()->count())->toBe(0)
        ->and(ProductVariant::query()->count())->toBe(0);
});

test('catalog management routes reject users without the matching permissions', function () {
    $owner = User::factory()->create();
    $user = User::factory()->create();
    $business = Business::factory()->for($owner, 'owner')->create();
    $category = Category::factory()->for($business)->create();
    $brand = Brand::factory()->for($business)->create();
    $product = Product::factory()->for($business)->for($category)->create(['status' => 'inactive']);

    $this->actingAs($user)->get(route('products.index'))->assertForbidden();
    $this->actingAs($user)->post(route('categories.store'), ['name' => 'Unauthorized'])->assertForbidden();
    $this->actingAs($user)->delete(route('categories.destroy', $category))->assertForbidden();
    $this->actingAs($user)->delete(route('brands.destroy', $brand))->assertForbidden();
    $this->actingAs($user)->delete(route('products.destroy', $product))->assertForbidden();
});

test('unused inactive products are deleted with their variants bundle definitions and image', function () {
    Storage::fake('public');
    $user = catalogManager('products.deactivate');
    $business = Business::query()->sole();
    $category = Category::factory()->for($business)->create();
    $componentProduct = Product::factory()->for($business)->for($category)->create();
    $componentVariant = ProductVariant::factory()->for($business)->for($componentProduct)->create();
    $imagePath = 'product-images/delete-me.png';
    Storage::disk('public')->put($imagePath, 'image');
    $product = Product::factory()->for($business)->for($category)->combo()->create([
        'image_path' => $imagePath,
        'status' => 'inactive',
    ]);
    $variant = ProductVariant::factory()->for($business)->for($product)->create();
    BundleComponent::query()->create([
        'business_id' => $business->id,
        'bundle_variant_id' => $variant->id,
        'component_variant_id' => $componentVariant->id,
        'quantity' => '1.000',
    ]);

    $this->actingAs($user)->delete(route('products.destroy', $product))
        ->assertRedirect(route('products.index'))
        ->assertSessionHasNoErrors();

    $this->assertModelMissing($product);
    $this->assertModelMissing($variant);
    $this->assertModelExists($componentProduct);
    $this->assertModelExists($componentVariant);
    $this->assertDatabaseMissing('bundle_components', ['bundle_variant_id' => $variant->id]);
    Storage::disk('public')->assertMissing($imagePath);
});

test('active products must be deactivated before deletion', function () {
    $user = catalogManager('products.deactivate');
    $business = Business::query()->sole();
    $category = Category::factory()->for($business)->create();
    $product = Product::factory()->for($business)->for($category)->create();
    $variant = ProductVariant::factory()->for($business)->for($product)->create();

    $this->actingAs($user)->delete(route('products.destroy', $product))
        ->assertSessionHasErrors([
            'product' => 'Deactivate the product before permanently deleting it.',
        ]);

    $this->assertModelExists($product);
    $this->assertModelExists($variant);
});

test('products with transaction history cannot be deleted', function () {
    $user = catalogManager('products.deactivate');
    $business = Business::query()->sole();
    $category = Category::factory()->for($business)->create();
    $product = Product::factory()->for($business)->for($category)->create(['status' => 'inactive']);
    $variant = ProductVariant::factory()->for($business)->for($product)->create();
    $sale = Sale::factory()->for($business)->create();
    $saleLine = SaleLine::factory()->for($sale)->create([
        'business_id' => $business->id,
        'product_variant_id' => $variant->id,
    ]);

    $this->actingAs($user)->delete(route('products.destroy', $product))
        ->assertSessionHasErrors([
            'product' => 'This product cannot be deleted because it has transaction or inventory history. Keep it inactive instead.',
        ]);

    $this->assertModelExists($product);
    $this->assertModelExists($variant);
    $this->assertModelExists($saleLine);
});

test('products used by another bundle cannot be deleted', function () {
    $user = catalogManager('products.deactivate');
    $business = Business::query()->sole();
    $category = Category::factory()->for($business)->create();
    $product = Product::factory()->for($business)->for($category)->create(['status' => 'inactive']);
    $variant = ProductVariant::factory()->for($business)->for($product)->create();
    $bundleProduct = Product::factory()->for($business)->for($category)->combo()->create();
    $bundleVariant = ProductVariant::factory()->for($business)->for($bundleProduct)->create();
    BundleComponent::query()->create([
        'business_id' => $business->id,
        'bundle_variant_id' => $bundleVariant->id,
        'component_variant_id' => $variant->id,
        'quantity' => '1.000',
    ]);

    $this->actingAs($user)->delete(route('products.destroy', $product))
        ->assertSessionHasErrors([
            'product' => 'This product cannot be deleted because another bundle uses one of its variants.',
        ]);

    $this->assertModelExists($product);
    $this->assertModelExists($variant);
});

test('unused categories and brands can be deleted', function () {
    Storage::fake('public');
    $user = catalogManager('categories.deactivate', 'brands.deactivate');
    $business = Business::query()->sole();
    $category = Category::factory()->for($business)->create();
    $logoPath = 'brand-logos/delete-me.png';
    Storage::disk('public')->put($logoPath, 'logo');
    $brand = Brand::factory()->for($business)->create(['logo_path' => $logoPath]);

    $this->actingAs($user)->delete(route('categories.destroy', $category))
        ->assertRedirect(route('categories.index'))
        ->assertSessionHasNoErrors();
    $this->actingAs($user)->delete(route('brands.destroy', $brand))
        ->assertRedirect(route('brands.index'))
        ->assertSessionHasNoErrors();

    $this->assertModelMissing($category);
    $this->assertModelMissing($brand);
    Storage::disk('public')->assertMissing($logoPath);
});

test('categories and brands assigned to products cannot be deleted', function () {
    $user = catalogManager('categories.deactivate', 'brands.deactivate');
    $business = Business::query()->sole();
    $category = Category::factory()->for($business)->create();
    $brand = Brand::factory()->for($business)->create();
    Product::factory()->for($business)->for($category)->withBrand($brand)->create();

    $this->actingAs($user)->delete(route('categories.destroy', $category))
        ->assertSessionHasErrors([
            'category' => 'This category cannot be deleted because it is assigned to products.',
        ]);
    $this->actingAs($user)->delete(route('brands.destroy', $brand))
        ->assertSessionHasErrors([
            'brand' => 'This brand cannot be deleted because it is assigned to products.',
        ]);

    $this->assertModelExists($category);
    $this->assertModelExists($brand);
});

test('category and brand deactivation is blocked while active products use them', function () {
    $user = catalogManager('categories.deactivate', 'brands.deactivate');
    $business = Business::query()->sole();
    $category = Category::factory()->for($business)->create();
    $brand = Brand::factory()->for($business)->create();
    $product = Product::factory()->for($business)->for($category)->withBrand($brand)->create();

    $this->actingAs($user)->patch(route('categories.status.update', $category), ['status' => 'inactive'])
        ->assertSessionHasErrors('status');
    $this->actingAs($user)->patch(route('brands.status.update', $brand), ['status' => 'inactive'])
        ->assertSessionHasErrors('status');

    expect($category->refresh()->status->value)->toBe('active')
        ->and($brand->refresh()->status->value)->toBe('active');

    $product->update(['status' => 'inactive']);
    $this->actingAs($user)->patch(route('categories.status.update', $category), ['status' => 'inactive'])
        ->assertSessionHasNoErrors();
    $this->actingAs($user)->patch(route('brands.status.update', $brand), ['status' => 'inactive'])
        ->assertSessionHasNoErrors();

    expect($category->refresh()->status->value)->toBe('inactive')
        ->and($brand->refresh()->status->value)->toBe('inactive');
});

test('inactive products may retain inactive classifications but cannot be activated with them', function () {
    $user = catalogManager('products.update', 'products.deactivate', 'variants.manage');
    $business = Business::query()->sole();
    $category = Category::factory()->for($business)->create(['status' => 'inactive']);
    $brand = Brand::factory()->for($business)->create(['status' => 'inactive']);
    $product = Product::factory()->for($business)->for($category)->withBrand($brand)->create([
        'status' => 'inactive',
    ]);
    $variant = ProductVariant::factory()->for($business)->for($product)->create([
        'sku' => 'RETAINED-CLASSIFICATION',
    ]);
    $payload = validProductPayload($category, [
        'brand_id' => $brand->id,
        'status' => 'inactive',
        'variants' => [[
            'id' => $variant->id,
            'name' => $variant->name,
            'sku' => $variant->sku,
            'barcode' => null,
            'tracking_mode' => 'quantity_based',
            'unit' => 'piece',
            'condition' => null,
            'cost_price' => '10.00',
            'selling_price' => '20.00',
            'minimum_selling_price' => null,
            'status' => 'active',
        ]],
    ]);

    $this->actingAs($user)
        ->put(route('products.update', $product), $payload)
        ->assertRedirect(route('products.index'))
        ->assertSessionHasNoErrors();

    $this->actingAs($user)
        ->patch(route('products.status.update', $product), ['status' => 'active'])
        ->assertSessionHasErrors('status');

    $category->update(['status' => 'active']);
    $brand->update(['status' => 'active']);

    $this->actingAs($user)
        ->patch(route('products.status.update', $product), ['status' => 'active'])
        ->assertSessionHasNoErrors();

    expect($product->refresh()->status->value)->toBe('active');
});

test('catalog defaults can be seeded repeatedly without replacing customized values', function () {
    $business = Business::factory()->create();
    $category = Category::factory()->for($business)->create([
        'name' => 'Accessories', 'description' => 'Customized', 'status' => 'inactive',
    ]);

    $this->seed(CatalogDefaultsSeeder::class);
    $this->seed(CatalogDefaultsSeeder::class);

    expect(Category::query()->where('business_id', $business->id)->where('name', 'Accessories')->count())->toBe(1)
        ->and($category->refresh()->description)->toBe('Customized')
        ->and($category->status->value)->toBe('inactive')
        ->and(Brand::query()->where('business_id', $business->id)->where('name', 'Apple')->count())->toBe(1);
});
