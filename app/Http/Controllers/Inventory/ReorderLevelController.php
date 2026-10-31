<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Services\LocationAccessService;
use App\Services\StockAvailabilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ReorderLevelController extends Controller
{
    public function index(Request $request, LocationAccessService $access, StockAvailabilityService $availability): Response
    {
        $filters = $request->validate(['location_id' => ['nullable', 'integer'], 'search' => ['nullable', 'string', 'max:100'], 'alerts' => ['nullable', 'boolean']]);
        $business = $access->business();
        $locations = $access->accessibleQuery($request->user())->where('status', 'active')->orderBy('name')->get(['id', 'name']);
        abort_if($locations->isEmpty(), 403);
        $location = $access->accessibleQuery($request->user())->where('status', 'active')->findOrFail($request->integer('location_id') ?: ($access->selectedLocationId($request) ?? $locations->first()->id));
        $query = $availability->query($business, $location)->with('product')
            ->where('status', 'active')->whereHas('product', fn ($q) => $q->where('status', 'active'))
            ->when($request->boolean('alerts'), fn ($q) => $q->whereNotNull('reorder_quantity')->whereColumn('available_quantity', '<=', 'reorder_quantity'));
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $query->where(fn ($q) => $q->where('sku', 'like', "%{$search}%")->orWhereHas('product', fn ($q) => $q->where('name', 'like', "%{$search}%")));
        }

        return Inertia::render('inventory/Reorder', [
            'rows' => $query->orderBy('sku')->paginate(25)->withQueryString()->through(fn (ProductVariant $variant): array => [
                'id' => $variant->id, 'product' => $variant->product->name, 'variant' => $variant->name, 'sku' => $variant->sku,
                'quantity' => (string) $variant->getAttribute('available_quantity'), 'threshold' => $variant->getAttribute('reorder_quantity'),
            ]),
            'locations' => $locations, 'locationId' => $location->id, 'search' => $search, 'alerts' => $request->boolean('alerts'),
            'canUpdate' => $request->user()->can('inventory.adjust'),
        ]);
    }

    public function update(Request $request, LocationAccessService $access): RedirectResponse
    {
        $data = $request->validate(['location_id' => ['required', 'integer'], 'product_variant_id' => ['required', 'integer'], 'quantity' => ['nullable', 'numeric', 'min:0', 'max:99999999999.999', 'decimal:0,3']]);
        $business = $access->business();
        $location = $access->accessibleQuery($request->user())->findOrFail((int) $data['location_id']);
        $variant = $business->productVariants()->findOrFail((int) $data['product_variant_id']);
        $key = ['business_id' => $business->id, 'location_id' => $location->id, 'product_variant_id' => $variant->id];
        DB::transaction(function () use ($data, $business, $variant, $key): void {
            $business->productVariants()->whereKey($variant->id)->lockForUpdate()->firstOrFail();
            if ($data['quantity'] === null) {
                DB::table('reorder_levels')->where($key)->delete();
            } else {
                DB::table('reorder_levels')->updateOrInsert($key, ['quantity' => $data['quantity'], 'updated_at' => now(), 'created_at' => now()]);
            }
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Reorder level saved.']);

        return back();
    }
}
