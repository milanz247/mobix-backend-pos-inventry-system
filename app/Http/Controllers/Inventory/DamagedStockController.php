<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\DamagedStockEntry;
use App\Models\ProductVariant;
use App\Models\SerializedDevice;
use App\Services\DamagedStockListService;
use App\Services\InventoryService;
use App\Services\LocationAccessService;
use App\Support\InventoryPageData;
use App\VariantTrackingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DamagedStockController extends Controller
{
    public function index(Request $request, LocationAccessService $access, InventoryPageData $pageData, DamagedStockListService $listing): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'location_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'], 'brand_id' => ['nullable', 'integer'], 'model_number' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'resolved', 'all'])],
        ]);
        $business = $access->business();
        $locationIds = $access->accessibleQuery($request->user())->select('locations.id');
        $search = trim((string) ($filters['search'] ?? ''));
        $entries = DamagedStockEntry::query()->where('business_id', $business->id)->whereIn('location_id', $locationIds)
            ->with(['variant.product.category', 'variant.product.brand', 'location', 'returnLine.saleReturn.sale'])
            ->when(($filters['status'] ?? 'active') === 'active', fn (Builder $q) => $q->where('remaining_quantity', '>', 0))
            ->when(($filters['status'] ?? 'active') === 'resolved', fn (Builder $q) => $q->where('remaining_quantity', 0))
            ->whereHas('variant', fn (Builder $q) => $this->catalogFilters($q, $filters))
            ->when(! empty($filters['location_id']), fn (Builder $query) => $query->where('location_id', $filters['location_id']))
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                $query->whereHas('variant', fn (Builder $variant) => $variant->where('sku', 'like', "%{$search}%")->orWhere('model_number', 'like', "%{$search}%")->orWhere('barcode', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")
                    ->orWhereHas('product', fn (Builder $product) => $product->where('name', 'like', "%{$search}%")))
                    ->orWhereHas('returnLine.saleReturn.sale', fn (Builder $sale) => $sale->where('sale_number', 'like', "%{$search}%")->orWhere('customer_name', 'like', "%{$search}%"));
            }))
            ->latest('id')->paginate(20)->withQueryString()->through(fn (DamagedStockEntry $entry): array => [
                'id' => $entry->id, 'product' => $entry->variant->product->name, 'variant' => $entry->variant->name,
                'sku' => $entry->variant->sku, 'barcode' => $entry->variant->barcode,
                'model_number' => $entry->variant->model_number, 'category' => $entry->variant->product->category->name, 'brand' => $entry->variant->product->brand?->name, 'location' => $entry->location->name,
                'quantity' => $entry->quantity, 'remaining' => $entry->remaining_quantity,
                'reason' => $entry->reason, 'notes' => $entry->notes, 'date' => $entry->created_at->format('Y-m-d H:i'),
                'sale_id' => $request->user()->can('sales.view') ? $entry->returnLine?->saleReturn->sale_id : null,
                'invoice' => $request->user()->can('sales.view') ? $entry->returnLine?->saleReturn->sale->sale_number : null,
                'customer' => $request->user()->can('sales.view') ? $entry->returnLine?->saleReturn->sale->customer_name : null,
            ]);
        $devices = SerializedDevice::query()->where('business_id', $business->id)->whereIn('location_id', $locationIds)
            ->where('status', 'damaged')->with(['variant.product.category', 'variant.product.brand', 'location'])
            ->whereHas('variant', fn (Builder $q) => $this->catalogFilters($q, $filters))
            ->when(! empty($filters['location_id']), fn (Builder $query) => $query->where('location_id', $filters['location_id']))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('imei', 'like', "%{$search}%")->orWhere('imei2', 'like', "%{$search}%")
                ->orWhereHas('variant', fn (Builder $q) => $q->where('sku', 'like', "%{$search}%")->orWhere('model_number', 'like', "%{$search}%")->orWhere('barcode', 'like', "%{$search}%")->orWhereHas('product', fn (Builder $product) => $product->where('name', 'like', "%{$search}%")))))
            ->latest('updated_at')->paginate(20, ['*'], 'devices_page')->withQueryString()->through(fn (SerializedDevice $device): array => [
                'id' => $device->id, 'product' => $device->variant->product->name, 'variant' => $device->variant->name,
                'sku' => $device->variant->sku, 'model_number' => $device->variant->model_number, 'category' => $device->variant->product->category->name, 'brand' => $device->variant->product->brand?->name, 'imei' => $device->imei, 'location' => $device->location->name,
            ]);

        return Inertia::render('inventory/Damaged', [
            'entries' => $entries, 'devices' => $devices,
            'locations' => $pageData->locations($request->user()),
            'variants' => $request->user()->can('damaged-stock.create') ? $pageData->variants($business, VariantTrackingMode::QuantityBased) : [],
            'availableDevices' => fn () => ! $request->user()->can('damaged-stock.create') || ! $request->user()->can('serialized-devices.manage') ? [] : SerializedDevice::query()->where('business_id', $business->id)->whereIn('location_id', $locationIds)
                ->where('status', 'in_stock')->with('variant.product')->orderBy('imei')->get()->map(fn (SerializedDevice $device): array => [
                    'id' => $device->id, 'location_id' => $device->location_id, 'label' => $device->variant->product->name.' · '.$device->imei,
                ]),
            'filters' => [...$filters, 'search' => $search, 'location_id' => $filters['location_id'] ?? '', 'status' => $filters['status'] ?? 'active'],
            'categories' => $business->categories()->orderBy('name')->get(['id', 'name']),
            'brands' => $business->brands()->orderBy('name')->get(['id', 'name']),
            'summary' => $listing->summary($listing->query($request->user(), $business, array_diff_key($filters, ['search' => true])), false),
            'canViewDevices' => $request->user()->can('serialized-devices.view'),
            'canAdjust' => $request->user()->canAny(['damaged-stock.repair', 'damaged-stock.write-off']),
            'canCreate' => $request->user()->can('damaged-stock.create'),
            'canRepair' => $request->user()->can('damaged-stock.repair'),
            'canWriteOff' => $request->user()->can('damaged-stock.write-off'),
            'canManageDevices' => $request->user()->can('serialized-devices.manage'),
            'canViewSales' => $request->user()->can('sales.view'),
        ]);
    }

    /**
     * @param  Builder<ProductVariant>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<ProductVariant>
     */
    private function catalogFilters(Builder $query, array $filters): Builder
    {
        foreach (['category_id', 'brand_id'] as $field) {
            if (! empty($filters[$field])) {
                $query->whereHas('product', fn (Builder $product) => $product->where($field, $filters[$field]));
            }
        }
        if (filled($filters['model_number'] ?? null)) {
            $query->where('model_number', $filters['model_number']);
        }

        return $query;
    }

    public function store(Request $request, LocationAccessService $access, InventoryService $inventory): RedirectResponse
    {
        $data = $request->validate([
            'operation_key' => ['required', 'uuid'], 'location_id' => ['required', 'integer'],
            'mode' => ['required', Rule::in(['quantity', 'serialized'])],
            'product_variant_id' => ['required_if:mode,quantity', 'nullable', 'integer'],
            'quantity' => ['required_if:mode,quantity', 'nullable', 'numeric', 'gt:0', 'decimal:0,3'],
            'device_id' => ['required_if:mode,serialized', 'nullable', 'integer'],
            'notes' => ['required', 'string', 'max:2000'],
        ]);
        if ($data['mode'] === 'serialized') {
            abort_unless($request->user()->can('serialized-devices.manage'), 403);
            $device = SerializedDevice::query()->where('business_id', $access->business()->id)->where('location_id', $data['location_id'])->findOrFail((int) $data['device_id']);
            $inventory->changeDeviceStatus($request->user(), $device, [
                'operation_key' => $data['operation_key'], 'status' => 'damaged', 'reason' => 'Stock damaged', 'notes' => $data['notes'],
            ]);
        } else {
            $inventory->adjust($request->user(), [
                'operation_key' => $data['operation_key'], 'location_id' => $data['location_id'],
                'product_variant_id' => $data['product_variant_id'], 'quantity' => $data['quantity'],
                'direction' => 'decrease', 'reason' => 'damaged', 'notes' => $data['notes'],
            ]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Items moved to damaged stock.')]);

        return to_route('inventory.damaged.index');
    }

    public function resolve(Request $request, DamagedStockEntry $entry, InventoryService $inventory): RedirectResponse
    {
        $data = $request->validate([
            'operation_key' => ['required', 'uuid'], 'action' => ['required', Rule::in(['repair', 'write_off'])],
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'], 'notes' => ['required', 'string', 'max:2000'],
        ]);
        $inventory->resolveDamage($request->user(), $entry, $data);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Damaged stock updated.')]);

        return to_route('inventory.damaged.index');
    }
}
