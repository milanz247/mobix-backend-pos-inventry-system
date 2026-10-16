<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreAdjustmentRequest;
use App\Services\InventoryService;
use App\Services\LocationAccessService;
use App\Support\InventoryPageData;
use App\VariantTrackingMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StockAdjustmentController extends Controller
{
    public function create(Request $request, InventoryPageData $pageData, LocationAccessService $locationAccess): Response
    {
        return Inertia::render('inventory/Adjustments', [
            'locations' => $pageData->locations($request->user()),
            'variants' => $pageData->variants($locationAccess->business(), VariantTrackingMode::QuantityBased),
        ]);
    }

    public function store(StoreAdjustmentRequest $request, InventoryService $inventory): RedirectResponse
    {
        $inventory->adjust($request->user(), $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Stock adjustment posted.')]);

        return to_route('inventory.adjustments.create');
    }
}
