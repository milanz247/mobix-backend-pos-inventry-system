<?php

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Database\Seeders\DemoCatalogSeeder;

test('demo catalogue seeder creates every supported product type with LKR prices and bundle components', function () {
    $business = Business::factory()->create(['currency' => 'LKR']);

    $this->seed(DemoCatalogSeeder::class);

    expect($business->categories()->count())->toBe(4)
        ->and($business->brands()->count())->toBe(6)
        ->and($business->products()->count())->toBe(10)
        ->and($business->productVariants()->count())->toBe(13)
        ->and($business->products()->where('product_type', 'simple')->count())->toBe(6)
        ->and($business->products()->where('product_type', 'variable')->count())->toBe(2)
        ->and($business->products()->where('product_type', 'serialized')->count())->toBe(1)
        ->and($business->products()->where('product_type', 'combo')->count())->toBe(1);

    $serializedVariant = $business->productVariants()
        ->where('sku', 'DEMO-PHN-IP13-128-USED')
        ->sole();
    $bundleVariant = $business->productVariants()
        ->where('sku', 'DEMO-BUNDLE-STARTER')
        ->with('bundleComponents')
        ->sole();

    expect($serializedVariant->tracking_mode->value)->toBe('serialized')
        ->and($serializedVariant->condition?->value)->toBe('used')
        ->and($serializedVariant->cost_price)->toBe('112000.00')
        ->and($serializedVariant->selling_price)->toBe('139000.00')
        ->and($bundleVariant->selling_price)->toBe('8500.00')
        ->and($bundleVariant->bundleComponents)->toHaveCount(4);
});

test('demo catalogue seeder is idempotent and preserves customized records', function () {
    $business = Business::factory()->create();

    $this->seed(DemoCatalogSeeder::class);

    $variant = ProductVariant::query()->where('business_id', $business->id)
        ->where('sku', 'DEMO-ACC-CHG-25W')
        ->sole();
    $variant->update(['selling_price' => '4999.00']);
    $category = Category::query()->where('business_id', $business->id)
        ->where('name', 'Accessories')
        ->sole();
    $category->update(['description' => 'Customized accessories description.']);

    $this->seed(DemoCatalogSeeder::class);

    expect(Product::query()->where('business_id', $business->id)->count())->toBe(10)
        ->and(ProductVariant::query()->where('business_id', $business->id)->count())->toBe(13)
        ->and($variant->refresh()->selling_price)->toBe('4999.00')
        ->and($category->refresh()->description)->toBe('Customized accessories description.');
});
