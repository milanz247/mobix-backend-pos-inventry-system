<?php

namespace App\Http\Controllers\Sales;

use App\CatalogStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\CheckoutRequest;
use App\Models\ProductVariant;
use App\Models\SerializedDeviceIdentifier;
use App\ProductType;
use App\SaleSource;
use App\Services\LocationAccessService;
use App\Services\SaleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class PosController extends Controller
{
    public function index(Request $request, LocationAccessService $locationAccess): Response
    {
        $user = $request->user();
        $business = $locationAccess->business();
        $locations = $locationAccess->accessibleQuery($user)->where('status', 'active')->orderByDesc('is_main')->orderBy('name')->get(['id', 'name', 'code']);
        abort_if($locations->isEmpty(), 403);
        $locationId = $request->integer('location_id') ?: (int) $locations->first()->id;
        $location = $locationAccess->accessibleQuery($user)->where('status', 'active')->findOrFail($locationId);
        $search = mb_substr($request->string('search')->trim()->toString(), 0, 100);
        $categoryId = $request->integer('category_id') ?: null;
        $brandId = $request->integer('brand_id') ?: null;
        $matchingDevice = null;

        if ($search !== '') {
            $normalizedSearch = mb_strtoupper(preg_replace('/\s+/', '', $search) ?? $search);
            $identifier = SerializedDeviceIdentifier::query()->where('business_id', $business->id)
                ->where('identifier', $normalizedSearch)->with(['device.variant.product'])->first();
            $device = $identifier?->device;
            if ($device !== null && $device->location_id === $location->id && $device->status->value === 'in_stock'
                && $device->variant->status === CatalogStatus::Active && $device->variant->product->status === CatalogStatus::Active) {
                $matchingDevice = [
                    'product_variant_id' => $device->product_variant_id,
                    'identifier' => $normalizedSearch,
                    'imei' => $device->imei,
                    'product_name' => $device->variant->product->name,
                    'variant_name' => $device->variant->name,
                    'sku' => $device->variant->sku,
                    'condition' => $device->condition->value,
                    'price' => $device->unit_selling_price ?? $device->variant->selling_price,
                ];
            }
        }

        $variants = $business->productVariants()
            ->with(['product:id,name,product_type,image_path,category_id,brand_id,status', 'product.category:id,name', 'product.brand:id,name'])
            ->withSum(['inventoryStocks as quantity_on_hand' => fn (Builder $query): Builder => $query->where('location_id', $location->id)], 'quantity_on_hand')
            ->withCount(['serializedDevices as devices_in_stock' => fn (Builder $query): Builder => $query->where('location_id', $location->id)->where('status', 'in_stock')])
            ->where('status', CatalogStatus::Active->value)
            ->whereHas('product', fn (Builder $query): Builder => $query->where('status', CatalogStatus::Active->value))
            ->when($search !== '', fn (Builder $query): Builder => $query->where(function (Builder $query) use ($search): void {
                $query->where('sku', 'like', "%{$search}%")->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhereHas('product', fn (Builder $query): Builder => $query->where('name', 'like', "%{$search}%"));
            }))
            ->when($categoryId !== null, fn (Builder $query): Builder => $query->whereHas('product', fn (Builder $query): Builder => $query->where('category_id', $categoryId)))
            ->when($brandId !== null, fn (Builder $query): Builder => $query->whereHas('product', fn (Builder $query): Builder => $query->where('brand_id', $brandId)))
            ->orderBy('sku')->paginate(24)->withQueryString()->through(fn (ProductVariant $variant): array => [
                'id' => $variant->id,
                'name' => $variant->product->name,
                'variant' => $variant->name,
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
                'category' => $variant->product->category->name,
                'brand' => $variant->product->brand?->name,
                'product_type' => $variant->product->product_type->value,
                'tracking_mode' => $variant->tracking_mode->value,
                'price' => $variant->selling_price,
                'minimum_selling_price' => $variant->minimum_selling_price,
                'image_url' => $variant->product->image_path === null ? null : Storage::disk('public')->url($variant->product->image_path),
                'quantity_on_hand' => $variant->quantity_on_hand ?? '0.000',
                'devices_in_stock' => (int) $variant->getAttribute('devices_in_stock'),
                'is_virtual_combo' => $variant->product->product_type === ProductType::Combo,
            ]);

        return Inertia::render('sales/Pos', [
            'variants' => Inertia::scroll($variants),
            'matchingDevice' => $matchingDevice,
            'locations' => $locations->map(fn ($item): array => ['id' => $item->id, 'name' => $item->name, 'code' => $item->code])->values(),
            'selectedLocation' => ['id' => $location->id, 'name' => $location->name, 'code' => $location->code],
            'categories' => $business->categories()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'brands' => $business->brands()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'filters' => ['search' => $search, 'category_id' => $categoryId, 'brand_id' => $brandId, 'location_id' => $location->id],
            'currency' => $business->currency,
            'cashierId' => $user->id,
            'saleSources' => array_map(fn (SaleSource $source): array => [
                'value' => $source->value,
                'label' => $source->label(),
            ], SaleSource::cases()),
            'canOverridePrice' => $user->can('sales.price-override'),
            'canDiscount' => $user->can('sales.discount'),
            'canCredit' => $user->can('sales.credit'),
            'canReceiveStock' => $user->can('inventory.receive'),
            'canOpenStock' => $user->can('inventory.opening'),
        ]);
    }

    public function checkout(CheckoutRequest $request, SaleService $sales): RedirectResponse
    {
        $sale = $sales->checkout($request->user(), $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sale :number completed.', ['number' => $sale->sale_number])]);

        return to_route('sales.show', $sale);
    }
}
