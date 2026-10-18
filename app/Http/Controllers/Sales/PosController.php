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
        $locationId = $business->pos_show_location_filter
            ? ($request->integer('location_id') ?: (int) $locations->first()->id)
            : (int) $locations->first()->id;
        $location = $locationAccess->accessibleQuery($user)->where('status', 'active')->findOrFail($locationId);
        $search = mb_substr($request->string('search')->trim()->toString(), 0, 100);
        $categoryId = $business->pos_show_category_filter ? ($request->integer('category_id') ?: null) : null;
        $brandId = $business->pos_show_brand_filter ? ($request->integer('brand_id') ?: null) : null;
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
            ->orderBy('sku')->paginate($business->pos_products_per_page)->withQueryString()->through(fn (ProductVariant $variant): array => [
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
            'posSettings' => [
                'show_sale_source' => $business->pos_show_sale_source,
                'show_customer_name' => $business->pos_show_customer_name,
                'show_customer_phone' => $business->pos_show_customer_phone,
                'show_customer_email' => $business->pos_show_customer_email,
                'show_sale_notes' => $business->pos_show_sale_notes,
                'show_pay_exact' => $business->pos_show_pay_exact,
                'require_imei_on_sale' => $business->pos_require_imei_on_sale,
                'show_date_time' => $business->pos_show_date_time,
                'show_sales_history' => $business->pos_show_sales_history,
                'show_category_filter' => $business->pos_show_category_filter,
                'show_brand_filter' => $business->pos_show_brand_filter,
                'show_location_filter' => $business->pos_show_location_filter,
                'show_receipt_after_sale' => $business->pos_show_receipt_after_sale,
                'auto_print_receipt' => $business->pos_auto_print_receipt,
                'products_per_page' => $business->pos_products_per_page,
            ],
            'canOverridePrice' => $business->pos_allow_price_override && $user->can('sales.price-override'),
            'canDiscount' => $user->can('sales.discount'),
            'canCredit' => $user->can('sales.credit'),
            'canReceiveStock' => $user->can('inventory.receive'),
            'canOpenStock' => $user->can('inventory.opening'),
        ]);
    }

    public function checkout(CheckoutRequest $request, SaleService $sales, LocationAccessService $locationAccess): RedirectResponse
    {
        $sale = $sales->checkout($request->user(), $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sale :number completed.', ['number' => $sale->sale_number])]);

        $business = $locationAccess->business();

        if ($business->pos_show_receipt_after_sale || $business->pos_auto_print_receipt) {
            $cashPayment = $sale->payments->firstWhere('method', 'cash');

            Inertia::flash('completedSale', [
                'id' => $sale->id,
                'sale_number' => $sale->sale_number,
                'customer' => $sale->customer_name ?: __('Walk-in customer'),
                'total' => $sale->total,
                'cash_tendered' => $cashPayment === null ? null : $cashPayment->cash_tendered,
                'change_given' => $cashPayment === null ? '0.00' : $cashPayment->change_given,
            ]);
        }

        return to_route('pos.index', ['location_id' => $sale->location_id]);
    }
}
