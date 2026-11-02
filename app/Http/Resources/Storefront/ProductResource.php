<?php

namespace App\Http\Resources\Storefront;

use App\Models\Product;
use App\Services\StorefrontCatalogService;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $product = $this->resource;
        if (! $product instanceof Product) {
            throw new \LogicException('ProductResource requires a product.');
        }

        $catalog = app(StorefrontCatalogService::class);
        $business = $product->business;
        $variants = $product->variants->map(function ($variant) use ($catalog, $business): array {
            $price = $catalog->startingPrice($variant);

            return [
                'id' => $variant->id,
                'name' => $variant->name,
                'model_number' => $variant->model_number,
                'storage_capacity' => $variant->storage_capacity,
                'color' => $variant->color,
                'condition' => $variant->condition?->value,
                'unit' => $variant->unit,
                'specifications' => array_filter([
                    'model_number' => $variant->model_number,
                    'storage_capacity' => $variant->storage_capacity,
                    'color' => $variant->color,
                ], fn ($value) => $value !== null && $value !== ''),
                'starting_price' => $price,
                'availability' => $catalog->availability($variant, $business),
            ];
        })->values();
        $prices = $variants->pluck('starting_price')->filter(fn ($price) => $price !== null);
        $startingPrice = $prices->isEmpty() ? null : $prices->reduce(fn (?string $min, string $price): string => $min === null || BigDecimal::of($price)->isLessThan($min) ? $price : $min);

        return [
            'id' => $product->id,
            'slug' => $product->webListing->slug,
            'name' => $product->webListing->public_title ?: $product->name,
            'description' => $product->webListing->public_description ?: $product->description,
            'category' => new CategoryResource($product->category),
            'brand' => $product->brand === null ? null : new BrandResource($product->brand),
            'product_type' => $product->product_type->value,
            'image_url' => $catalog->imageUrl($product->image_path),
            'images' => array_values(array_filter(array_map(fn ($path) => $catalog->imageUrl($path), $product->gallery_paths ?? []))),
            'starting_price' => $startingPrice,
            'currency' => $business->currency,
            'availability' => $variants->contains(fn (array $variant) => $variant['availability'] === 'in_stock') ? 'in_stock'
                : ($variants->contains(fn (array $variant) => $variant['availability'] === 'check_at_checkout') ? 'check_at_checkout' : 'out_of_stock'),
            'availability_is_advisory' => true,
            'variants' => $variants,
            'seo' => ['title' => $product->webListing->seo_title, 'description' => $product->webListing->seo_description],
        ];
    }
}
