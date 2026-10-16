<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\SerializedDevice;
use App\Models\StockMovement;
use App\SerializedDeviceStatus;
use App\Services\LocationAccessService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryOverviewController extends Controller
{
    public function __invoke(Request $request, LocationAccessService $locationAccess): Response
    {
        $user = $request->user();
        $business = $locationAccess->business();
        $locationIds = $locationAccess->accessibleQuery($user)->pluck('id');
        $locationSummary = $locationAccess->accessibleQuery($user)
            ->withSum('inventoryStocks as quantity_on_hand', 'quantity_on_hand')
            ->withCount(['serializedDevices as serialized_in_stock' => fn ($query) => $query->where('status', SerializedDeviceStatus::InStock->value)])
            ->orderByDesc('is_main')->orderBy('name')->get()->map(fn ($location): array => [
                'id' => $location->id,
                'name' => $location->name,
                'quantity_on_hand' => $location->quantity_on_hand ?? '0.000',
                'serialized_in_stock' => $location->serialized_in_stock,
            ]);
        $recent = StockMovement::query()->with(['location:id,name', 'variant:id,product_id,name,sku', 'variant.product:id,name'])
            ->where('business_id', $business->id)->whereIn('location_id', $locationIds)
            ->latest('created_at')->limit(8)->get()->map(fn (StockMovement $movement): array => [
                'id' => $movement->id,
                'location' => $movement->location->name,
                'product' => $movement->variant->product->name,
                'variant' => $movement->variant->name,
                'sku' => $movement->variant->sku,
                'type' => $movement->movement_type->value,
                'delta' => $movement->quantity_delta,
                'balance_after' => $movement->balance_after,
                'created_at' => $movement->created_at->toIso8601String(),
            ]);

        return Inertia::render('inventory/Overview', [
            'metrics' => [
                'quantity_on_hand' => InventoryStock::query()->where('business_id', $business->id)->whereIn('location_id', $locationIds)->sum('quantity_on_hand'),
                'serialized_in_stock' => SerializedDevice::query()->where('business_id', $business->id)->whereIn('location_id', $locationIds)->where('status', SerializedDeviceStatus::InStock->value)->count(),
                'tracked_variants' => ProductVariant::query()->where('business_id', $business->id)
                    ->where(function ($query) use ($locationIds): void {
                        $query->whereHas('inventoryStocks', fn ($query) => $query->whereIn('location_id', $locationIds))
                            ->orWhereHas('serializedDevices', fn ($query) => $query->whereIn('location_id', $locationIds));
                    })->count(),
                'locations' => $locationIds->count(),
            ],
            'recentMovements' => $recent,
            'locationSummary' => $locationSummary,
            'lowStockConfigured' => false,
        ]);
    }
}
