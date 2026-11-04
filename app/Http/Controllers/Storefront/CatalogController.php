<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Resources\Storefront\AvailabilityResource;
use App\Http\Resources\Storefront\BrandResource;
use App\Http\Resources\Storefront\CategoryResource;
use App\Http\Resources\Storefront\ProductResource;
use App\Models\Brand;
use App\Models\Business;
use App\Models\Category;
use App\Services\StorefrontCatalogService;
use App\Services\StorefrontDeviceSelectionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    public function categories(StorefrontCatalogService $catalog): AnonymousResourceCollection
    {
        $business = $this->business();
        $rows = Category::query()->where('business_id', $business->id)->where('status', 'active')
            ->whereHas('products', fn ($query) => $catalog->published($query, $business))
            ->orderBy('name')->orderBy('id')->get();

        return CategoryResource::collection($rows);
    }

    public function brands(StorefrontCatalogService $catalog): AnonymousResourceCollection
    {
        $business = $this->business();
        $rows = Brand::query()->where('business_id', $business->id)->where('status', 'active')
            ->whereHas('products', fn ($query) => $catalog->published($query, $business))
            ->orderBy('name')->orderBy('id')->get();

        return BrandResource::collection($rows);
    }

    public function products(Request $request, StorefrontCatalogService $catalog): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['sometimes', 'string', 'max:100'],
            'category' => ['sometimes', 'integer', 'min:1'],
            'brand' => ['sometimes', 'integer', 'min:1'],
            'condition' => ['sometimes', Rule::in(['new', 'used', 'refurbished'])],
            'sort' => ['sometimes', Rule::in(['newest', 'price_asc', 'price_desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,24'],
        ]);
        $business = $this->business();
        $query = $catalog->products($business);

        if (isset($filters['q']) && trim($filters['q']) !== '') {
            $search = '%'.trim($filters['q']).'%';
            $query->where(function ($query) use ($search): void {
                $query->where('name', 'like', $search)
                    ->orWhereHas('webListing', fn ($listing) => $listing->where('public_title', 'like', $search))
                    ->orWhereHas('variants', fn ($variants) => $variants->where('status', 'active')
                        ->where(fn ($variant) => $variant->where('name', 'like', $search)
                            ->orWhere('model_number', 'like', $search)
                            ->orWhere('storage_capacity', 'like', $search)
                            ->orWhere('color', 'like', $search)));
            });
        }
        if (isset($filters['category'])) {
            $query->where('category_id', $filters['category']);
        }
        if (isset($filters['brand'])) {
            $query->where('brand_id', $filters['brand']);
        }
        if (isset($filters['condition'])) {
            $query->whereHas('variants', fn ($variant) => $variant->where('status', 'active')->where('condition', $filters['condition']));
        }

        $sort = $filters['sort'] ?? 'newest';
        if ($sort === 'newest') {
            $query->orderByDesc('products.created_at');
        } else {
            $query->select('products.*')->selectRaw($catalog->priceSortExpression().' as sort_price')
                ->orderByRaw('sort_price IS NULL')
                ->orderBy('sort_price', $sort === 'price_asc' ? 'asc' : 'desc');
        }
        $query->orderBy('products.id');

        return ProductResource::collection($query->paginate($filters['per_page'] ?? 12)->withQueryString());
    }

    public function product(string $slug, StorefrontCatalogService $catalog): ProductResource
    {
        $business = $this->business();
        $product = $catalog->products($business)
            ->whereHas('webListing', fn ($listing) => $listing->where('slug', $slug))
            ->firstOrFail();

        return new ProductResource($product);
    }

    public function availability(string $slug, StorefrontCatalogService $catalog): AvailabilityResource
    {
        $business = $this->business();
        $product = $catalog->products($business)
            ->whereHas('webListing', fn ($listing) => $listing->where('slug', $slug))
            ->firstOrFail();
        $variants = $product->variants->map(fn ($variant): array => [
            'variant_id' => $variant->id,
            'status' => $catalog->availability($variant, $business),
        ])->values()->all();

        return new AvailabilityResource(['slug' => $product->webListing->slug, 'advisory' => true, 'variants' => array_values($variants)]);
    }

    public function deviceOptions(Request $request, string $slug, StorefrontDeviceSelectionService $selections): JsonResponse
    {
        $input = $request->validate(['variant' => ['required', 'integer', 'min:1']]);
        $result = $selections->options($this->business(), $slug, (int) $input['variant']);

        return response()->json(['data' => $result['options']]);
    }

    public function createDeviceSelection(Request $request, StorefrontDeviceSelectionService $selections): JsonResponse
    {
        $input = $request->validate(['option_key' => ['required', 'string', 'max:4096']]);

        return response()->json(['data' => $selections->createSelection($this->business(), $input['option_key'])], 201);
    }

    private function business(): Business
    {
        return Business::query()->where('setup_key', 'initial')->sole();
    }
}
