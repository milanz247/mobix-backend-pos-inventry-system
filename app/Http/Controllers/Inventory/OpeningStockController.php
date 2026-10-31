<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreOpeningStockRequest;
use App\Services\InventoryService;
use App\Services\LocationAccessService;
use App\Support\InventoryPageData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OpeningStockController extends Controller
{
    public function create(Request $request, InventoryPageData $pageData, LocationAccessService $locationAccess): Response
    {
        return Inertia::render('inventory/Receive', ['locations' => $pageData->locations($request->user()), 'variants' => $pageData->variants($locationAccess->business(), limit: 25), 'opening' => true]);
    }

    public function store(StoreOpeningStockRequest $request, InventoryService $inventory): RedirectResponse
    {
        $inventory->receive($request->user(), $request->validated(), opening: true);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Opening stock posted.')]);

        return to_route('inventory.opening.create');
    }
}
