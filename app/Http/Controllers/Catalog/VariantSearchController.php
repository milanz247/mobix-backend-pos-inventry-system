<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Services\LocationAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VariantSearchController extends Controller
{
    public function __invoke(Request $request, LocationAccessService $access): JsonResponse
    {
        $user = $request->user();
        $purchase = $request->boolean('purchase');
        abort_unless($purchase ? ($user->can('purchases.create') || $user->can('purchases.update')) : ($user->can('inventory.adjust') || $user->can('inventory.transfer') || $user->can('inventory.receive') || $user->can('inventory.opening')), 403);
        $data = $request->validate(['search' => 'nullable|string|max:100', 'location_id' => 'nullable|integer', 'destination_id' => 'nullable|integer']);
        $ids = $access->accessibleQuery($user)->pluck('id')->all();
        $query = $access->business()->productVariants()->with(['product', 'inventoryStocks' => fn ($q) => $q->whereIn('location_id', $ids)])->where('status', 'active')->whereHas('product', fn ($q) => $q->where('status', 'active')->whereNotIn('product_type', ['service', 'combo']));
        if (! $purchase && ! ($request->boolean('all_tracking') && ($user->can('inventory.receive') || $user->can('inventory.opening')))) {
            $query->where('tracking_mode', 'quantity_based');
        }
        $search = $data['search'] ?? '';
        $query->where(fn ($q) => $q->where('sku', 'like', '%'.$search.'%')->orWhere('barcode', 'like', '%'.$search.'%')->orWhere('name', 'like', '%'.$search.'%')->orWhereHas('product', fn ($q) => $q->where('name', 'like', '%'.$search.'%')));

        return response()->json($query->orderBy('sku')->limit(25)->get()->map(fn ($v) => [
            'id' => $v->id, 'label' => $v->product->name.' — '.$v->name, 'sku' => $v->sku, 'barcode' => $v->barcode, 'tracking_mode' => $v->tracking_mode->value,
            'cost_price' => $purchase ? $v->cost_price : null, 'selling_price' => $v->selling_price,
            'stock' => $v->inventoryStocks->mapWithKeys(fn ($s) => [$s->location_id => (string) $s->quantity_on_hand]),
        ]));
    }
}
