<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\StockMovement;
use App\Services\LocationAccessService;
use App\StockMovementType;
use App\Support\InventoryPageData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StockMovementController extends Controller
{
    public function __invoke(Request $request, LocationAccessService $locationAccess, InventoryPageData $pageData): Response
    {
        $business = $locationAccess->business();
        $locationIds = $locationAccess->accessibleQuery($request->user())->pluck('id');
        $search = $request->string('search')->trim()->toString();
        $locationId = $request->integer('location_id') ?: null;
        $type = StockMovementType::tryFrom($request->string('movement_type')->toString());
        $movements = StockMovement::query()->with(['location:id,name', 'variant:id,product_id,name,sku', 'variant.product:id,name', 'performer:id,name', 'operation:id,reference'])
            ->where('business_id', $business->id)->whereIn('location_id', $locationIds)
            ->when($locationId !== null, fn (Builder $query) => $query->where('location_id', $locationId))
            ->when($type !== null, fn (Builder $query) => $query->where('movement_type', $type->value))
            ->when($search !== '', fn (Builder $query) => $query->whereHas('variant', fn (Builder $query) => $query
                ->where('sku', 'like', "%{$search}%")->orWhereHas('product', fn (Builder $query) => $query->where('name', 'like', "%{$search}%"))))
            ->latest('created_at')->paginate(25)->withQueryString()->through(fn (StockMovement $movement): array => [
                'id' => $movement->id, 'location' => $movement->location->name, 'product' => $movement->variant->product->name,
                'variant' => $movement->variant->name, 'sku' => $movement->variant->sku, 'type' => $movement->movement_type->value,
                'delta' => $movement->quantity_delta, 'before' => $movement->balance_before, 'after' => $movement->balance_after,
                'reference' => $movement->operation->reference, 'actor' => $movement->performer->name,
                'created_at' => $movement->created_at->toIso8601String(),
            ]);

        return Inertia::render('inventory/Movements', [
            'movements' => $movements, 'locations' => $pageData->locations($request->user()),
            'movementTypes' => collect(StockMovementType::cases())->map(fn ($type): array => ['value' => $type->value, 'label' => str($type->value)->replace('_', ' ')->title()->toString()]),
            'filters' => ['search' => $search, 'location_id' => $locationId, 'movement_type' => $type === null ? '' : $type->value],
        ]);
    }
}
