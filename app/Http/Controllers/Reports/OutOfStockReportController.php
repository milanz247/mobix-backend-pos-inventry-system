<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\ProductType;
use App\Services\LocationAccessService;
use App\Services\StockAvailabilityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OutOfStockReportController extends Controller
{
    public function __invoke(Request $request, LocationAccessService $locations, StockAvailabilityService $availability): Response
    {
        $filters = $request->validate([
            'location_id' => ['nullable', 'integer'], 'category_id' => ['nullable', 'integer'],
            'brand_id' => ['nullable', 'integer'], 'product_type' => ['nullable', Rule::enum(ProductType::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'stock_status' => ['nullable', Rule::in(['out_of_stock', 'inconsistent'])],
            'sort' => ['nullable', Rule::in(['sku', 'quantity', 'last_movement'])],
        ]);
        $user = $request->user();
        $business = $locations->business();
        $options = $locations->accessibleQuery($user)->orderBy('name')->get(['id', 'name']);
        abort_if($options->isEmpty(), 403, __('No locations are assigned to your account. Contact an administrator.'));
        $locationId = $request->integer('location_id') ?: ($locations->selectedLocationId($request) ?? $options->first()->id);
        $location = $locations->accessibleQuery($user)->whereKey($locationId)->firstOrFail();

        return DB::transaction(function () use ($filters, $user, $business, $options, $location, $availability): Response {
            $query = $availability->query($business, $location)->with(['product.category:id,name', 'product.brand:id,name'])
                ->where(fn (Builder $query) => $query->where('available_quantity', '<=', 0)->orWhere('stock_inconsistent', 1));
            foreach (['category_id', 'brand_id', 'product_type'] as $field) {
                if (filled($filters[$field] ?? null)) {
                    $query->whereHas('product', fn (Builder $product) => $product->where($field, $filters[$field]));
                }
            }
            if (filled($filters['search'] ?? null)) {
                $search = $filters['search'];
                $query->where(fn (Builder $searchQuery) => $searchQuery->where('sku', 'like', "%{$search}%")->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhereHas('product', fn (Builder $product) => $product->where('name', 'like', "%{$search}%")));
            }
            if (($filters['stock_status'] ?? '') === 'out_of_stock') {
                $query->where('available_quantity', 0)->where('stock_inconsistent', 0);
            } elseif (($filters['stock_status'] ?? '') === 'inconsistent') {
                $query->where(fn (Builder $query) => $query->where('stock_inconsistent', 1)->orWhere('available_quantity', '<', 0));
            }
            $summary = [
                'zero_stock' => (clone $query)->where('available_quantity', 0)->where('stock_inconsistent', 0)->count(),
                'inconsistent' => (clone $query)->where(fn (Builder $query) => $query->where('stock_inconsistent', 1)->orWhere('available_quantity', '<', 0))->count(),
            ];
            $sort = match ($filters['sort'] ?? 'sku') {
                'quantity' => 'available_quantity', 'last_movement' => 'last_movement_at', default => 'sku'
            };
            $rows = $query->orderBy($sort)->orderBy('id')->paginate(25)->withQueryString()->through(function (ProductVariant $variant) use ($availability, $location, $business, $user): array {
                return [
                    ...$availability->describe($variant),
                    'id' => $variant->id, 'product' => $variant->product->name, 'variant' => $variant->name,
                    'sku' => $variant->sku, 'barcode' => $variant->barcode,
                    'product_type' => $variant->product->product_type->value,
                    'category' => $variant->product->category->name, 'brand' => $variant->product->brand?->name,
                    'location' => $location->name, 'catalogue_status' => $variant->status->value,
                    'last_movement_at' => $variant->getAttribute('last_movement_at') === null ? null : Carbon::parse($variant->getAttribute('last_movement_at'), $business->timezone)->toIso8601String(),
                    'url' => $user->can('products.view') ? route('products.show', $variant->product_id) : route('inventory.locations.index', ['location_id' => $location->id, 'search' => $variant->sku]),
                ];
            });

            return Inertia::render('reports/OutOfStock', [
                'rows' => $rows, 'summary' => $summary, 'locations' => $options,
                'filters' => [...$filters, 'location_id' => $location->id],
                'categories' => $business->categories()->orderBy('name')->get(['id', 'name']),
                'brands' => $business->brands()->orderBy('name')->get(['id', 'name']),
                'productTypes' => collect(ProductType::cases())->map(fn (ProductType $type): array => ['value' => $type->value, 'label' => $type->label()]),
                'timezone' => $business->timezone, 'generatedAt' => now()->toIso8601String(),
            ]);
        });
    }
}
