<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\SerializedDeviceStatus;
use App\Services\LocationAccessService;
use App\Support\InventoryPageData;
use App\VariantTrackingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LocationInventoryController extends Controller
{
    public function __invoke(Request $request, LocationAccessService $locationAccess, InventoryPageData $pageData): Response
    {
        $user = $request->user();
        $business = $locationAccess->business();
        $locations = $pageData->locations($user);
        abort_if($locations === [], 403, __('No active locations are assigned to your account. Contact an administrator.'));
        $locationId = $request->integer('location_id') ?: ($locationAccess->selectedLocationId($request) ?? $locations[0]['id']);
        $location = $locationAccess->accessibleQuery($user)->where('status', 'active')->findOrFail($locationId);
        $search = $request->string('search')->trim()->toString();
        $tracking = VariantTrackingMode::tryFrom($request->string('tracking_mode')->toString());
        $categoryId = $request->integer('category_id') ?: null;
        $brandId = $request->integer('brand_id') ?: null;

        $variants = $business->productVariants()->with(['product.category:id,name', 'product.brand:id,name'])
            ->withSum(['inventoryStocks as quantity_on_hand' => fn (Builder $query) => $query->where('location_id', $location->id)], 'quantity_on_hand')
            ->withCount(['serializedDevices as serialized_in_stock' => fn (Builder $query) => $query->where('location_id', $location->id)->where('status', SerializedDeviceStatus::InStock->value)])
            ->where(function (Builder $query) use ($location, $search): void {
                $query->whereHas('inventoryStocks', fn (Builder $query) => $query->where('location_id', $location->id))
                    ->orWhereHas('serializedDevices', fn (Builder $query) => $query->where('location_id', $location->id));
                if ($search !== '') {
                    $query->orWhere('sku', $search)->orWhere('barcode', $search);
                }
            })
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                $query->where('sku', 'like', "%{$search}%")->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhereHas('product', fn (Builder $query) => $query->where('name', 'like', "%{$search}%"));
            }))
            ->when($tracking !== null, fn (Builder $query) => $query->where('tracking_mode', $tracking->value))
            ->when($categoryId !== null, fn (Builder $query) => $query->whereHas('product', fn (Builder $query) => $query->where('category_id', $categoryId)))
            ->when($brandId !== null, fn (Builder $query) => $query->whereHas('product', fn (Builder $query) => $query->where('brand_id', $brandId)))
            ->orderBy('sku')->paginate(20)->withQueryString()->through(fn (ProductVariant $variant): array => [
                'id' => $variant->id, 'product' => $variant->product->name, 'variant' => $variant->name,
                'sku' => $variant->sku, 'barcode' => $variant->barcode, 'product_type' => $variant->product->product_type->value,
                'category' => $variant->product->category->name,
                'brand' => $variant->product->brand?->name, 'tracking_mode' => $variant->tracking_mode->value,
                'selling_price' => $variant->selling_price,
                'quantity_on_hand' => $variant->quantity_on_hand ?? '0.000', 'serialized_in_stock' => $variant->serialized_in_stock,
            ]);

        return Inertia::render('inventory/StockByLocation', [
            'stocks' => $variants, 'locations' => $locations,
            'selectedLocation' => ['id' => $location->id, 'name' => $location->name],
            'filters' => ['location_id' => $location->id, 'search' => $search, 'tracking_mode' => $tracking === null ? '' : $tracking->value, 'category_id' => $categoryId, 'brand_id' => $brandId],
            'categories' => $business->categories()->orderBy('name')->get(['id', 'name']),
            'brands' => $business->brands()->orderBy('name')->get(['id', 'name']),
            'currency' => $business->currency,
        ]);
    }
}
