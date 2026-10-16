<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreReceivingRequest;
use App\Services\InventoryService;
use App\Services\LocationAccessService;
use App\Support\InventoryPageData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StockReceivingController extends Controller
{
    public function create(Request $request, InventoryPageData $pageData, LocationAccessService $locationAccess): Response
    {
        $locations = $pageData->locations($request->user());
        $requestedLocation = collect($locations)->firstWhere('id', $request->integer('location_id'));

        return Inertia::render('inventory/Receive', [
            'locations' => $locations,
            'variants' => $pageData->variants($locationAccess->business()),
            'opening' => false,
            'selectedLocationId' => $requestedLocation['id'] ?? $locations[0]['id'] ?? null,
        ]);
    }

    public function store(StoreReceivingRequest $request, InventoryService $inventory): RedirectResponse
    {
        $inventory->receive($request->user(), $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Stock received and posted.')]);

        return to_route('inventory.receiving.create');
    }
}
