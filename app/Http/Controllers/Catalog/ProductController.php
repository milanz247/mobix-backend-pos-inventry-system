<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\DeleteProduct;
use App\Actions\Catalog\SaveProduct;
use App\CatalogStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductRequest;
use App\Http\Requests\Catalog\UpdateProductRequest;
use App\Http\Requests\Catalog\UpdateProductStatusRequest;
use App\Models\Brand;
use App\Models\BundleComponent;
use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductActivityLog;
use App\Models\ProductVariant;
use App\ProductCondition;
use App\ProductType;
use App\Services\ProductActivityLogger;
use App\VariantTrackingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $business = Business::query()->where('setup_key', 'initial')->sole();
        $search = $request->string('search')->trim()->toString();
        $variantSearch = $request->string('variant')->trim()->toString();
        $status = CatalogStatus::tryFrom($request->string('status')->toString());
        $productType = ProductType::tryFrom($request->string('product_type')->toString());
        $condition = ProductCondition::tryFrom($request->string('condition')->toString());
        $categoryId = $request->integer('category_id');
        $brandId = $request->integer('brand_id');
        $categoryId = $categoryId > 0 ? $categoryId : null;
        $brandId = $brandId > 0 ? $brandId : null;

        $products = $business->products()
            ->with(['category:id,name', 'brand:id,name'])
            ->withCount(['variants as active_variants_count' => fn (Builder $query) => $query->where('status', CatalogStatus::Active->value)])
            ->withMin(['variants as lowest_variant_price' => fn (Builder $query) => $query->where('status', CatalogStatus::Active->value)], 'selling_price')
            ->withMin('variants as primary_sku', 'sku')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhereHas('variants', fn (Builder $variantQuery) => $variantQuery
                            ->where(function (Builder $variantQuery) use ($search): void {
                                $variantQuery->where('name', 'like', "%{$search}%")
                                    ->orWhere('sku', 'like', "%{$search}%")
                                    ->orWhere('barcode', 'like', "%{$search}%")
                                    ->orWhere('model_number', 'like', "%{$search}%")
                                    ->orWhere('storage_capacity', 'like', "%{$search}%")
                                    ->orWhere('color', 'like', "%{$search}%");
                            }));
                });
            })
            ->when($variantSearch !== '', fn (Builder $query) => $query->whereHas('variants', fn (Builder $variantQuery) => $variantQuery
                ->where(function (Builder $variantQuery) use ($variantSearch): void {
                    $variantQuery->where('name', 'like', "%{$variantSearch}%")
                        ->orWhere('model_number', 'like', "%{$variantSearch}%")
                        ->orWhere('storage_capacity', 'like', "%{$variantSearch}%")
                        ->orWhere('color', 'like', "%{$variantSearch}%");
                })))
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status->value))
            ->when($productType !== null, fn (Builder $query) => $query->where('product_type', $productType->value))
            ->when($condition !== null, fn (Builder $query) => $query->whereHas('variants', fn (Builder $variantQuery) => $variantQuery
                ->where('condition', $condition->value)))
            ->when($categoryId !== null, fn (Builder $query) => $query->where('category_id', $categoryId))
            ->when($brandId !== null, fn (Builder $query) => $query->where('brand_id', $brandId))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Product $product): array => [
                'id' => $product->id,
                'name' => $product->name,
                'product_type' => $product->product_type->value,
                'status' => $product->status->value,
                'category' => $product->category->name,
                'brand' => $product->brand?->name,
                'variants_count' => $product->active_variants_count,
                'primary_sku' => $product->primary_sku,
                'lowest_variant_price' => $product->lowest_variant_price,
                'image_url' => $product->image_path === null ? null : Storage::disk('public')->url($product->image_path),
            ]);

        return Inertia::render('catalog/products/Index', [
            'products' => $products,
            'filters' => [
                'search' => $search,
                'variant' => $variantSearch,
                'category_id' => $categoryId,
                'brand_id' => $brandId,
                'product_type' => $productType->value ?? '',
                'condition' => $condition->value ?? '',
                'status' => $status->value ?? '',
            ],
            'suggestions' => $this->suggestions($business, $search),
            'categories' => $this->categoryOptions($business, includeInactive: true),
            'brands' => $this->brandOptions($business, includeInactive: true),
            'productTypes' => $this->options(ProductType::cases()),
            'conditions' => $this->options(ProductCondition::cases()),
            'statuses' => $this->options(CatalogStatus::cases()),
            'currency' => $business->currency,
        ]);
    }

    public function create(): Response
    {
        $business = Business::query()->where('setup_key', 'initial')->sole();

        return Inertia::render('catalog/products/Create', $this->formOptions($business));
    }

    public function show(Request $request, Product $product): Response
    {
        $business = Business::query()->where('setup_key', 'initial')->sole();
        $product = $business->products()
            ->with([
                'category:id,name',
                'brand:id,name',
                'variants' => fn (HasMany $query) => $query
                    ->with(['bundleComponents.componentVariant.product:id,name,status'])
                    ->orderBy('name')
                    ->orderBy('id'),
            ])
            ->findOrFail($product->id);

        $canViewActivity = $request->user()->can('product-activity.view');
        $canViewCosts = $request->user()->can('sales.costs.view');

        return Inertia::render('catalog/products/Show', [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'product_type' => $product->product_type->value,
                'description' => $product->description,
                'image_url' => $product->image_path === null ? null : Storage::disk('public')->url($product->image_path),
                'status' => $product->status->value,
                'category' => $product->category->name,
                'brand' => $product->brand?->name,
                'variants' => $product->variants->map(fn (ProductVariant $variant): array => [
                    'id' => $variant->id,
                    'name' => $variant->name,
                    'sku' => $variant->sku,
                    'barcode' => $variant->barcode,
                    'model_number' => $variant->model_number,
                    'storage_capacity' => $variant->storage_capacity,
                    'color' => $variant->color,
                    'other_attributes' => $variant->variant_attributes['details'] ?? null,
                    'tracking_mode' => $variant->tracking_mode->value,
                    'unit' => $variant->unit,
                    'condition' => $variant->condition?->value,
                    'cost_price' => $canViewCosts ? $variant->cost_price : null,
                    'selling_price' => $variant->selling_price,
                    'minimum_selling_price' => $variant->minimum_selling_price,
                    'status' => $variant->status->value,
                    'estimated_component_cost' => $canViewCosts ? $this->estimatedComponentCost($variant) : null,
                    'components' => $variant->bundleComponents->map(fn (BundleComponent $component): array => [
                        'id' => $component->component_variant_id,
                        'product' => $component->componentVariant->product->name,
                        'variant' => $component->componentVariant->name,
                        'sku' => $component->componentVariant->sku,
                        'quantity' => $component->quantity,
                        'cost_price' => $canViewCosts ? $component->componentVariant->cost_price : null,
                        'status' => $component->componentVariant->status->value,
                        'product_status' => $component->componentVariant->product->status->value,
                    ])->values()->all(),
                ])->values()->all(),
            ],
            'activity' => $canViewActivity
                ? ProductActivityLog::query()->where('business_id', $business->id)->where('product_id', $product->id)
                    ->with(['actor:id,name', 'location:id,name'])->latest('created_at')->latest('id')->paginate(20, ['*'], 'activity_page')->withQueryString()
                    ->through(fn (ProductActivityLog $log): array => [
                        'id' => $log->id, 'action' => $log->action, 'subject_type' => $log->subject_type,
                        'subject_id' => $log->subject_id, 'actor' => $log->actor_id === null ? $log->actor_name : $log->actor->name,
                        'location' => $log->location_id === null ? 'Business-wide' : $log->location->name, 'created_at' => $log->created_at->toIso8601String(),
                        'changes' => collect($log->changes ?? [])->map(fn (array $change, string $field): array => [
                            'field' => $field,
                            'before' => ! $canViewCosts && $field === 'cost_price' ? 'Hidden' : $change['before'],
                            'after' => ! $canViewCosts && $field === 'cost_price' ? 'Hidden' : $change['after'],
                        ])->values()->all(),
                    ])
                : null,
            'canViewActivity' => $canViewActivity,
            'canViewCosts' => $canViewCosts,
            'productTypes' => $this->options(ProductType::cases()),
            'trackingModes' => $this->options(VariantTrackingMode::cases()),
            'conditions' => $this->options(ProductCondition::cases()),
            'currency' => $business->currency,
        ]);
    }

    public function store(StoreProductRequest $request, SaveProduct $saveProduct): RedirectResponse
    {
        $saveProduct->handle(
            Business::query()->where('setup_key', 'initial')->sole(),
            $request->safe()->all(),
            image: $request->file('image'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product and variants created.')]);

        return to_route('products.index');
    }

    public function edit(Product $product): Response
    {
        $business = Business::query()->where('setup_key', 'initial')->sole();
        $product = $business->products()
            ->with(['variants.bundleComponents.componentVariant.product'])
            ->findOrFail($product->id);
        $existingComponentIds = $product->variants
            ->flatMap(fn (ProductVariant $variant) => $variant->bundleComponents->pluck('component_variant_id'))
            ->map(fn (int|string $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $existingComponentIds = array_values($existingComponentIds);

        return Inertia::render('catalog/products/Edit', [
            ...$this->formOptions(
                $business,
                $existingComponentIds,
                $product->category_id,
                $product->brand_id,
            ),
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'category_id' => $product->category_id,
                'brand_id' => $product->brand_id,
                'product_type' => $product->product_type->value,
                'description' => $product->description,
                'image_url' => $product->image_path === null ? null : Storage::disk('public')->url($product->image_path),
                'status' => $product->status->value,
                'variants' => $product->variants->map(fn (ProductVariant $variant): array => [
                    'id' => $variant->id,
                    'name' => $variant->name,
                    'sku' => $variant->sku,
                    'barcode' => $variant->barcode,
                    'model_number' => $variant->model_number,
                    'storage_capacity' => $variant->storage_capacity,
                    'color' => $variant->color,
                    'other_attributes' => $variant->variant_attributes['details'] ?? null,
                    'tracking_mode' => $variant->tracking_mode->value,
                    'unit' => $variant->unit,
                    'condition' => $variant->condition?->value,
                    'cost_price' => $variant->cost_price,
                    'selling_price' => $variant->selling_price,
                    'minimum_selling_price' => $variant->minimum_selling_price,
                    'status' => $variant->status->value,
                    'components' => $variant->bundleComponents->map(fn ($component): array => [
                        'component_variant_id' => $component->component_variant_id,
                        'quantity' => $component->quantity,
                    ])->values()->all(),
                ])->values()->all(),
            ],
            'currency' => $business->currency,
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product, SaveProduct $saveProduct): RedirectResponse
    {
        $product = Business::query()->where('setup_key', 'initial')->sole()->products()->findOrFail($product->id);

        $saveProduct->handle(
            $product->business,
            $request->safe()->all(),
            $product,
            $request->file('image'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product and variants updated.')]);

        return to_route('products.index');
    }

    public function updateStatus(UpdateProductStatusRequest $request, Product $product, ProductActivityLogger $activity): RedirectResponse
    {
        $product = Business::query()->where('setup_key', 'initial')->sole()->products()->findOrFail($product->id);
        DB::transaction(function () use ($product, $request, $activity): void {
            $before = $product->getAttributes();
            $product->update($request->validated());
            $activity->product($product, 'updated', $before, $product->getAttributes());
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product status updated.')]);

        return back();
    }

    public function destroy(Product $product, DeleteProduct $deleteProduct, ProductActivityLogger $activity): RedirectResponse
    {
        $business = Business::query()->where('setup_key', 'initial')->sole();
        $imagePath = $deleteProduct->handle($business, $product, $activity);

        if ($imagePath !== null && ! Product::query()->where('image_path', $imagePath)->exists()) {
            Storage::disk('public')->delete($imagePath);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product deleted.')]);

        return to_route('products.index');
    }

    /**
     * @param  list<int>  $existingComponentIds
     * @return array<string, mixed>
     */
    private function formOptions(
        Business $business,
        array $existingComponentIds = [],
        ?int $retainedCategoryId = null,
        ?int $retainedBrandId = null,
    ): array {
        return [
            'categories' => $this->categoryOptions($business, $retainedCategoryId),
            'brands' => $this->brandOptions($business, $retainedBrandId),
            'productTypes' => $this->options(ProductType::cases()),
            'conditions' => $this->options(ProductCondition::cases()),
            'trackingModes' => $this->options(VariantTrackingMode::cases()),
            'statuses' => $this->options(CatalogStatus::cases()),
            'componentVariants' => $this->componentVariantOptions($business, $existingComponentIds),
            'currency' => $business->currency,
        ];
    }

    /** @return list<array{id: int, name: string}> */
    private function categoryOptions(Business $business, ?int $retainedId = null, bool $includeInactive = false): array
    {
        return array_values($business->categories()
            ->when(! $includeInactive, fn (Builder $query) => $query->where(function (Builder $query) use ($retainedId): void {
                $query->where('status', CatalogStatus::Active->value);

                if ($retainedId !== null) {
                    $query->orWhereKey($retainedId);
                }
            }))
            ->orderBy('name')->get(['id', 'name'])
            ->map(fn (Category $category): array => ['id' => $category->id, 'name' => $category->name])->all());
    }

    /** @return list<array{id: int, name: string}> */
    private function brandOptions(Business $business, ?int $retainedId = null, bool $includeInactive = false): array
    {
        return array_values($business->brands()
            ->when(! $includeInactive, fn (Builder $query) => $query->where(function (Builder $query) use ($retainedId): void {
                $query->where('status', CatalogStatus::Active->value);

                if ($retainedId !== null) {
                    $query->orWhereKey($retainedId);
                }
            }))
            ->orderBy('name')->get(['id', 'name'])
            ->map(fn (Brand $brand): array => ['id' => $brand->id, 'name' => $brand->name])->all());
    }

    /**
     * @param  list<int>  $existingComponentIds
     * @return list<array{id: int, name: string, sku: string, cost_price: string, product: string}>
     */
    private function componentVariantOptions(Business $business, array $existingComponentIds = []): array
    {
        return array_values($business->productVariants()
            ->where(function (Builder $query) use ($existingComponentIds): void {
                $query->where(function (Builder $query): void {
                    $query->where('status', CatalogStatus::Active->value)
                        ->whereHas('product', fn (Builder $productQuery) => $productQuery->where('status', CatalogStatus::Active->value));
                });

                if ($existingComponentIds !== []) {
                    $query->orWhereIn('id', $existingComponentIds);
                }
            })
            ->whereHas('product', fn (Builder $query) => $query
                ->where('product_type', '!=', ProductType::Combo->value))
            ->with(['product' => fn (BelongsTo $relation) => $relation->select(['id', 'name'])])
            ->orderBy('sku')
            ->get(['id', 'business_id', 'product_id', 'name', 'sku', 'cost_price'])
            ->map(fn (ProductVariant $variant): array => [
                'id' => $variant->id,
                'name' => $variant->name,
                'sku' => $variant->sku,
                'cost_price' => $variant->cost_price,
                'product' => $variant->product->name,
            ])->all());
    }

    /** @return list<array{id: int, name: string, variant: string, sku: string}> */
    private function suggestions(Business $business, string $search): array
    {
        if (mb_strlen($search) < 2) {
            return [];
        }

        return array_values($business->products()
            ->where('status', CatalogStatus::Active->value)
            ->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhereHas('variants', fn (Builder $variantQuery) => $variantQuery
                        ->where('status', CatalogStatus::Active->value)
                        ->where(function (Builder $variantQuery) use ($search): void {
                            $variantQuery->where('sku', 'like', "%{$search}%")
                                ->orWhere('barcode', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%")
                                ->orWhere('model_number', 'like', "%{$search}%")
                                ->orWhere('storage_capacity', 'like', "%{$search}%")
                                ->orWhere('color', 'like', "%{$search}%");
                        }));
            })
            ->with(['variants' => fn (HasMany $query) => $query
                ->where('status', CatalogStatus::Active->value)
                ->where(function (Builder $query) use ($search): void {
                    $query->where('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('model_number', 'like', "%{$search}%")
                        ->orWhere('storage_capacity', 'like', "%{$search}%")
                        ->orWhere('color', 'like', "%{$search}%");
                })
                ->limit(1)])
            ->orderBy('name')
            ->limit(6)
            ->get(['id', 'name'])
            ->map(function (Product $product): array {
                $variant = $product->variants->first();

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'variant' => $variant instanceof ProductVariant ? $variant->name : '',
                    'sku' => $variant instanceof ProductVariant ? $variant->sku : '',
                ];
            })->all());
    }

    /** @param list<ProductType|ProductCondition|VariantTrackingMode|CatalogStatus> $values
     * @return list<array{value: string, label: string}>
     */
    private function options(array $values): array
    {
        return array_map(fn (ProductType|ProductCondition|VariantTrackingMode|CatalogStatus $value): array => [
            'value' => $value->value,
            'label' => $value->label(),
        ], $values);
    }

    private function estimatedComponentCost(ProductVariant $variant): string
    {
        $estimate = '0';

        foreach ($variant->bundleComponents as $component) {
            $lineCost = bcmul($component->componentVariant->cost_price, $component->quantity, 5);
            $estimate = bcadd($estimate, $lineCost, 5);
        }

        return $estimate;
    }
}
