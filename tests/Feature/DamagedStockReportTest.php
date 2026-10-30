<?php

use App\Models\Brand;
use App\Models\Business;
use App\Models\Category;
use App\Models\DamagedStockEntry;
use App\Models\InventoryOperation;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SerializedDevice;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

function damageReportContext(): array
{
    $business = Business::factory()->create();
    $location = Location::factory()->for($business)->create();
    $user = User::factory()->create();
    $location->users()->attach($user);
    $user->givePermissionTo(['inventory.view', 'damaged-reports.view', 'damaged-reports.print']);
    $category = Category::factory()->for($business)->create(['name' => 'Accessories']);
    $brand = Brand::factory()->for($business)->create(['name' => 'Report Brand']);
    $product = Product::factory()->for($business)->for($category)->for($brand)->create(['name' => 'Report Cable']);
    $variant = ProductVariant::factory()->for($business)->for($product)->create(['sku' => 'DAMAGE-001', 'model_number' => 'MODEL-X']);
    $operation = InventoryOperation::factory()->create(['business_id' => $business->id]);

    return compact('business', 'location', 'user', 'category', 'brand', 'product', 'variant', 'operation');
}

function damageReportEntry(array $context, string $remaining, ?ProductVariant $variant = null, ?Location $location = null): DamagedStockEntry
{
    return DamagedStockEntry::query()->create([
        'business_id' => $context['business']->id, 'location_id' => ($location ?? $context['location'])->id,
        'product_variant_id' => ($variant ?? $context['variant'])->id, 'inventory_operation_id' => $context['operation']->id,
        'quantity' => $remaining, 'remaining_quantity' => $remaining, 'unit_cost' => '20.00', 'reason' => 'Stock damaged', 'recorded_by' => $context['user']->id,
    ]);
}

test('damaged report combines matching SKU lots and filters by brand category model and authorized location', function () {
    $context = damageReportContext();
    damageReportEntry($context, '2.000');
    damageReportEntry($context, '3.000');
    $restricted = Location::factory()->for($context['business'])->create();
    damageReportEntry($context, '90.000', location: $restricted);
    $resolved = damageReportEntry($context, '1.000');
    $resolved->update(['remaining_quantity' => '0.000']);
    $this->actingAs($context['user'])->get(route('reports.damaged', [
        'category_id' => $context['category']->id, 'brand_id' => $context['brand']->id, 'model_number' => 'MODEL-X',
    ]))->assertOk()->assertInertia(fn (Assert $page) => $page->component('reports/Damaged')
        ->has('rows.data', 1)->where('rows.data.0.sku', 'DAMAGE-001')->where('rows.data.0.model_number', 'MODEL-X')
        ->where('rows.data.0.quantity', '5.000')->where('summary.quantity', '5.000')->where('summary.skus', 1)
        ->where('summary.value', null)->where('rows.data.0.value', null)->has('groups', 1));
    $this->get(route('reports.damaged', ['model_number' => 'OTHER']))->assertInertia(fn (Assert $page) => $page->has('rows.data', 0));
    $context['user']->givePermissionTo(Permission::findOrCreate('sales.costs.view', 'web'));
    $this->get(route('reports.damaged', ['group_by' => 'brand']))->assertInertia(fn (Assert $page) => $page
        ->where('groups.0.name', 'Report Brand')->where('summary.value', '100.00')->where('rows.data.0.value', '100.00'));
    $this->get(route('reports.damaged', ['location_id' => $restricted->id]))->assertNotFound();
});

test('damaged report includes only current damaged IMEIs and hides the cost without permission', function () {
    $context = damageReportContext();
    $product = Product::factory()->serialized()->for($context['business'])->for($context['category'])->for($context['brand'])->create();
    $variant = ProductVariant::factory()->serialized()->for($context['business'])->for($product)->create(['sku' => 'PHONE-DAMAGE', 'model_number' => 'PHONE-MODEL']);
    foreach (['damaged', 'in_stock', 'in_repair', 'sold'] as $status) {
        SerializedDevice::factory()->create(['business_id' => $context['business']->id, 'location_id' => $context['location']->id, 'product_variant_id' => $variant->id, 'status' => $status, 'unit_cost' => '250.00']);
    }
    $this->actingAs($context['user'])->get(route('reports.damaged'))->assertInertia(fn (Assert $page) => $page
        ->has('rows.data', 1)->where('rows.data.0.devices', 1)->where('summary.devices', 1)->where('summary.quantity', '0.000')->where('summary.value', null));
    $this->get(route('reports.damaged.print'))->assertInertia(fn (Assert $page) => $page->component('reports/DamagedPrint')->has('rows', 1)->where('rows.0.value', null));
});

test('print damaged report includes the complete filtered list beyond the on-screen page', function () {
    $context = damageReportContext();
    for ($i = 0; $i < 27; $i++) {
        $variant = ProductVariant::factory()->for($context['business'])->for($context['product'])->create(['sku' => sprintf('PRINT-%03d', $i)]);
        damageReportEntry($context, '1.000', $variant);
    }
    $this->actingAs($context['user'])->get(route('reports.damaged'))->assertInertia(fn (Assert $page) => $page->has('rows.data', 25)->where('rows.total', 27));
    $this->get(route('reports.damaged.print', ['search' => 'PRINT-']))->assertInertia(fn (Assert $page) => $page
        ->component('reports/DamagedPrint')->has('rows', 27)->where('summary.skus', 27)->where('summary.quantity', '27.000'));
    $other = User::factory()->create();
    $this->actingAs($other)->get(route('reports.damaged.print'))->assertForbidden();
});
