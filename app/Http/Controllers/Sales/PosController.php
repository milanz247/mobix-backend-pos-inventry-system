<?php

namespace App\Http\Controllers\Sales;

use App\CatalogStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\CheckoutRequest;
use App\Models\Customer;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SerializedDeviceIdentifier;
use App\ProductType;
use App\SaleSource;
use App\Services\CashierShiftService;
use App\Services\LocationAccessService;
use App\Services\SaleService;
use App\Services\StockAvailabilityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class PosController extends Controller
{
    public function index(Request $request, LocationAccessService $locationAccess, CashierShiftService $shifts, StockAvailabilityService $availability): Response
    {
        $user = $request->user();
        $business = $locationAccess->business();
        $locations = $locationAccess->accessibleQuery($user)->where('status', 'active')->orderByDesc('is_main')->orderBy('name')->get(['id', 'name', 'code']);
        abort_if($locations->isEmpty(), 403, __('No active locations are assigned to your account. Contact an administrator.'));
        $locationId = $request->integer('location_id')
            ?: ($locationAccess->selectedLocationId($request) ?? $shifts->activeQuery($user)->whereIn('location_id', $locations->modelKeys())->value('location_id') ?? (int) $locations->first()->id);
        $location = $locationAccess->accessibleQuery($user)->where('status', 'active')->whereKey($locationId)->firstOrFail();
        $request->session()->put('active_location_id', $location->id);
        $creditSale = null;
        if ($request->integer('credit_sale_id')) {
            abort_unless($user->can('sales.credit') && $user->can('sales.view') && $user->can('credit-sales.add-items') && $user->can('credit-sales.view'), 403);
            $existingSale = app(SaleService::class)->scoped($user, Sale::query()->findOrFail($request->integer('credit_sale_id')));
            abort_unless($existingSale->is_credit && $existingSale->customer_id !== null && $existingSale->location_id === $location->id, 422);
            $creditSale = [
                'id' => $existingSale->id, 'sale_number' => $existingSale->sale_number,
                'customer_id' => $existingSale->customer_id, 'customer_name' => $existingSale->customer_name,
                'outstanding' => app(SaleService::class)->outstanding($existingSale)->__toString(),
            ];
        }
        $search = mb_substr($request->string('search')->trim()->toString(), 0, 100);
        $categoryId = $business->pos_show_category_filter ? ($request->integer('category_id') ?: null) : null;
        $brandId = $business->pos_show_brand_filter ? ($request->integer('brand_id') ?: null) : null;
        $matchingDevice = null;

        if ($search !== '') {
            $normalizedSearch = mb_strtoupper(preg_replace('/\s+/', '', $search) ?? $search);
            $identifier = SerializedDeviceIdentifier::query()->where('business_id', $business->id)
                ->whereHas('device', fn (Builder $query): Builder => $query->where('location_id', $location->id)->where('business_id', $business->id))
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

        $variants = fn () => $availability->query($business, $location)
            ->with(['product:id,name,product_type,image_path,category_id,brand_id,status', 'product.category:id,name', 'product.brand:id,name'])
            ->where('status', CatalogStatus::Active->value)
            ->whereHas('product', fn (Builder $query): Builder => $query->where('status', CatalogStatus::Active->value))
            ->when($search !== '', fn (Builder $query): Builder => $query->where(function (Builder $query) use ($search): void {
                $query->where('sku', 'like', "%{$search}%")->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhereHas('product', fn (Builder $query): Builder => $query->where('name', 'like', "%{$search}%"));
            }))
            ->when($categoryId !== null, fn (Builder $query): Builder => $query->whereHas('product', fn (Builder $query): Builder => $query->where('category_id', $categoryId)))
            ->when($brandId !== null, fn (Builder $query): Builder => $query->whereHas('product', fn (Builder $query): Builder => $query->where('brand_id', $brandId)))
            ->orderBy('sku')->paginate($business->pos_products_per_page)->withQueryString()->through(fn (ProductVariant $variant): array => [
                ...$availability->describe($variant),
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
            'creditSale' => $creditSale,
            'matchingDevice' => $matchingDevice,
            'locations' => $locations->map(fn ($item): array => ['id' => $item->id, 'name' => $item->name, 'code' => $item->code])->values(),
            'selectedLocation' => ['id' => $location->id, 'name' => $location->name, 'code' => $location->code],
            'categories' => $business->categories()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'brands' => $business->brands()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'filters' => ['search' => $search, 'category_id' => $categoryId, 'brand_id' => $brandId, 'location_id' => $location->id],
            'taxRate' => $business->tax_rate,
            'canViewCustomers' => $user->can('customers.view'),
            'currency' => $business->currency,
            'cashierId' => $user->id,
            'drawer' => fn (): array => $shifts->posState($user, $location->id),
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
                'auto_print_receipt' => $business->pos_auto_print_receipt && $user->can('sales-receipts.print'),
                'products_per_page' => $business->pos_products_per_page,
            ],
            'canOverridePrice' => $business->pos_allow_price_override && $user->can('sales.price-override'),
            'canDiscount' => $user->can('sales.discount'),
            'customers' => fn () => ! $user->can('customers.view') ? [] : Customer::query()->where('business_id', $business->id)->orderBy('name')->limit(20)->get(['id', 'name', 'phone', 'email', 'credit_limit', 'credit_days']),
            'canCredit' => $user->can('sales.credit') && $user->can('credit-sales.view') && $user->can('customers.view'),
            'canCreateCustomer' => $user->can('customers.create'),
            'canPrintReceipt' => $user->can('sales-receipts.print'),
        ]);
    }

    public function checkout(CheckoutRequest $request, SaleService $sales, LocationAccessService $locationAccess): RedirectResponse
    {
        $sale = $sales->checkout($request->user(), $request->validated(), requireActiveShift: true);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sale :number completed.', ['number' => $sale->sale_number])]);

        $business = $locationAccess->business();

        if ($business->pos_show_receipt_after_sale || $business->pos_auto_print_receipt) {
            $cashPayment = $sale->payments->firstWhere('method', 'cash');

            Inertia::flash('completedSale', [
                'id' => $sale->id,
                'sale_number' => $sale->sale_number,
                'customer' => $sale->customer_name ?: __('Walk-in customer'),
                'total' => $sale->total,
                'paid_total' => $sale->paid_total,
                'outstanding' => $sales->outstanding($sale)->__toString(),
                'cash_tendered' => $cashPayment === null ? null : $cashPayment->cash_tendered,
                'change_given' => $cashPayment === null ? '0.00' : $cashPayment->change_given,
            ]);
        }

        if ($request->integer('credit_sale_id')) {
            return to_route('sales.show', $sale);
        }

        return to_route('pos.index', ['location_id' => $sale->location_id]);
    }
}
