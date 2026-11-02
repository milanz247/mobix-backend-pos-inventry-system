<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\WebProductListing;
use App\ProductType;
use App\Services\ProductActivityLogger;
use App\Services\StorefrontCatalogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WebProductListingController extends Controller
{
    public function index(Request $request, StorefrontCatalogService $catalog): Response
    {
        $business = Business::query()->where('setup_key', 'initial')->sole();
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'integer', 'min:1'],
            'brand' => ['nullable', 'integer', 'min:1'],
            'product_type' => ['nullable', Rule::enum(ProductType::class)],
            'publication' => ['nullable', Rule::in(['published', 'unpublished', 'ineligible'])],
        ]);
        $eligible = $catalog->eligible(Product::query(), $business)->select('products.id');
        $query = $business->products()->with(['category', 'brand', 'webListing'])
            ->withMin('variants as primary_sku', 'sku');
        if (! empty($filters['search'])) {
            $search = '%'.trim($filters['search']).'%';
            $query->where(fn (Builder $q) => $q->where('name', 'like', $search)
                ->orWhereHas('variants', fn (Builder $v) => $v->where('sku', 'like', $search)->orWhere('barcode', 'like', $search)));
        }
        foreach (['category', 'brand'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field.'_id', $filters[$field]);
            }
        }
        if (! empty($filters['product_type'])) {
            $query->where('product_type', $filters['product_type']);
        }
        if (($filters['publication'] ?? null) === 'published') {
            $query->whereIn('products.id', $eligible)->whereHas('webListing', fn (Builder $q) => $q->where('status', 'published'));
        } elseif (($filters['publication'] ?? null) === 'unpublished') {
            $query->whereIn('products.id', $eligible)->whereDoesntHave('webListing', fn (Builder $q) => $q->where('status', 'published'));
        } elseif (($filters['publication'] ?? null) === 'ineligible') {
            $query->whereNotIn('products.id', $eligible);
        }

        return Inertia::render('storefront/Products', [
            'products' => $query->orderBy('name')->orderBy('id')->paginate(15)->withQueryString()
                ->through(function (Product $product) use ($catalog): array {
                    $reasons = $catalog->publicationReasons($product);

                    return [
                        'id' => $product->id, 'name' => $product->name, 'sku' => $product->primary_sku,
                        'category' => $product->category?->name, 'brand' => $product->brand?->name,
                        'product_type' => $product->product_type->value, 'pos_status' => $product->status->value,
                        'image_url' => $catalog->imageUrl($product->image_path),
                        'published' => $product->webListing?->status === 'published', 'reasons' => $reasons,
                        'slug' => $product->webListing->slug ?? Str::limit(Str::slug($product->name) ?: 'product', 150, '').'-'.$product->id,
                        'updated_at' => $product->webListing?->updated_at?->toIso8601String(),
                    ];
                }),
            'filters' => $filters,
            'categories' => Category::query()->where('business_id', $business->id)->orderBy('name')->get(['id', 'name']),
            'brands' => Brand::query()->where('business_id', $business->id)->orderBy('name')->get(['id', 'name']),
            'productTypes' => array_map(fn (ProductType $type) => $type->value, ProductType::cases()),
        ]);
    }

    public function update(Request $request, Product $product, StorefrontCatalogService $catalog): RedirectResponse
    {
        $business = Business::query()->where('setup_key', 'initial')->sole();
        $product = $business->products()->with(['category', 'brand', 'webListing'])->findOrFail($product->id);

        $data = $request->validate([
            'status' => ['required', Rule::in(['draft', 'published'])],
            'slug' => ['required', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('web_product_listings', 'slug')->where('business_id', $business->id)->ignore($product->webListing?->id)],
            'public_title' => ['nullable', 'string', 'max:180'],
            'public_description' => ['nullable', 'string', 'max:10000'],
            'seo_title' => ['nullable', 'string', 'max:180'],
            'seo_description' => ['nullable', 'string', 'max:320'],
        ]);

        if ($data['status'] === 'published' && ($reasons = $catalog->publicationReasons($product)) !== []) {
            throw ValidationException::withMessages(['status' => implode(' ', $reasons)]);
        }

        DB::transaction(function () use ($business, $product, $data): void {
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $listing = WebProductListing::query()->where('business_id', $business->id)->where('product_id', $product->id)->first();
            $before = $listing?->getAttributes() ?? [];
            $listing = WebProductListing::query()->updateOrCreate(
                ['business_id' => $business->id, 'product_id' => $product->id],
                $data,
            );
            app(ProductActivityLogger::class)->publication($product, $before, $listing->getAttributes());
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Storefront publication saved.')]);

        return $request->routeIs('storefront.products.update')
            ? to_route('storefront.products.index', $request->only(['search', 'category', 'brand', 'product_type', 'publication', 'page']))
            : to_route('products.show', $product);
    }
}
