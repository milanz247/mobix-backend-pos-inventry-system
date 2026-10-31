<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreTransferRequest;
use App\Models\SerializedDevice;
use App\SerializedDeviceStatus;
use App\Services\InventoryService;
use App\Services\LocationAccessService;
use App\Support\InventoryPageData;
use App\VariantTrackingMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StockTransferController extends Controller
{
    public function create(Request $request, InventoryPageData $pageData, LocationAccessService $locationAccess): Response
    {
        $locationIds = $locationAccess->accessibleQuery($request->user())->pluck('id');
        $devices = SerializedDevice::query()->with(['variant:id,product_id,name,sku', 'variant.product:id,name', 'location:id,name'])
            ->whereIn('location_id', $locationIds)->where('status', SerializedDeviceStatus::InStock->value)
            ->orderBy('imei')->limit(500)->get()->map(fn (SerializedDevice $device): array => [
                'id' => $device->id, 'location_id' => $device->location_id,
                'label' => $device->variant->product->name.' — '.$device->variant->name.' · '.$device->imei,
            ]);

        return Inertia::render('inventory/Transfers', [
            'locations' => $pageData->locations($request->user()),
            'variants' => $pageData->variants($locationAccess->business(), VariantTrackingMode::QuantityBased, 25),
            'devices' => $devices,
        ]);
    }

    public function store(StoreTransferRequest $request, InventoryService $inventory): RedirectResponse
    {
        $inventory->transfer($request->user(), $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Stock transfer completed atomically.')]);

        return to_route('inventory.transfers.create');
    }
}
