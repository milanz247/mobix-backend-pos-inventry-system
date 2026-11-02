<?php

namespace App\Services;

use App\CatalogStatus;
use App\Models\Business;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\ProductCondition;
use App\ProductType;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StorefrontCatalogService
{
    /** @param Builder<Product> $query
     * @return Builder<Product>
     */
    public function published(Builder $query, Business $business): Builder
    {
        return $this->eligible($query, $business)
            ->whereHas('webListing', fn (Builder $listing) => $listing->where('status', 'published'));
    }

    /** @param Builder<Product> $query
     * @return Builder<Product>
     */
    public function eligible(Builder $query, Business $business): Builder
    {
        return $query->where('products.business_id', $business->id)
            ->where('products.status', CatalogStatus::Active->value)
            ->where('products.product_type', '!=', ProductType::Service->value)
            ->whereHas('category', fn (Builder $category) => $category->where('status', CatalogStatus::Active->value))
            ->where(fn (Builder $product) => $product->whereNull('brand_id')
                ->orWhereHas('brand', fn (Builder $brand) => $brand->where('status', CatalogStatus::Active->value)))
            ->whereHas('variants', fn (Builder $variant) => $variant->where('status', CatalogStatus::Active->value));
    }

    /** @return list<string> */
    public function publicationReasons(Product $product): array
    {
        $reasons = [];
        if ($product->status !== CatalogStatus::Active) {
            $reasons[] = 'Product is inactive.';
        }
        if ($product->product_type === ProductType::Service) {
            $reasons[] = 'Service products are excluded from the storefront.';
        }
        if ($product->category?->status !== CatalogStatus::Active) {
            $reasons[] = 'Category is inactive or unavailable.';
        }
        if ($product->brand_id !== null && $product->brand?->status !== CatalogStatus::Active) {
            $reasons[] = 'Brand is inactive or unavailable.';
        }
        if (! $product->variants()->where('status', CatalogStatus::Active->value)->exists()) {
            $reasons[] = 'No active variant exists.';
        }

        return $reasons;
    }

    /** @return Builder<Product> */
    public function products(Business $business): Builder
    {
        return $this->published(Product::query(), $business)->with([
            'webListing', 'category', 'brand', 'business',
            'variants' => fn ($query) => $query->where('status', CatalogStatus::Active->value)->orderBy('id'),
        ]);
    }

    public function startingPrice(ProductVariant $variant): ?string
    {
        if ($variant->tracking_mode->value === 'serialized' && $variant->condition === ProductCondition::Used) {
            $amount = DB::table('serialized_devices as devices')
                ->join('locations as locations', function ($join): void {
                    $join->on('locations.id', '=', 'devices.location_id')
                        ->on('locations.business_id', '=', 'devices.business_id');
                })
                ->where('devices.business_id', $variant->business_id)
                ->where('devices.product_variant_id', $variant->id)
                ->where('devices.status', 'in_stock')
                ->where('devices.condition', ProductCondition::Used->value)
                ->where('locations.status', 'active')
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')->from('web_order_reservations as reserved')
                        ->whereColumn('reserved.active_device_id', 'devices.id')
                        ->whereColumn('reserved.business_id', 'devices.business_id')
                        ->where('reserved.status', 'active');
                })
                ->selectRaw('MIN(COALESCE(devices.unit_selling_price, ?)) as price', [$variant->selling_price])
                ->value('price');

            return $amount === null ? null : BigDecimal::of((string) $amount)->toScale(2)->__toString();
        }

        return (string) $variant->selling_price;
    }

    public function availability(ProductVariant $variant, Business $business): string
    {
        if ($variant->condition === ProductCondition::Used && $variant->tracking_mode->value === 'serialized' && $this->startingPrice($variant) === null) {
            return 'check_at_checkout';
        }

        $stock = app(StockAvailabilityService::class);
        $uncertain = false;
        foreach (Location::query()->where('business_id', $business->id)->where('status', 'active')->pluck('id') as $locationId) {
            $location = new Location;
            $location->id = $locationId;
            $result = $stock->query($business, $location)->with('product')->where('product_variants.id', $variant->id)->first();
            if ($result === null) {
                continue;
            }
            $description = $stock->describe($result);
            if ($description['can_sell']) {
                return 'in_stock';
            }
            $uncertain = $uncertain || $description['stock_status'] === 'inconsistent';
        }

        return $uncertain ? 'check_at_checkout' : 'out_of_stock';
    }

    public function imageUrl(?string $path): ?string
    {
        if ($path === null || ! preg_match('~^product-images/[A-Za-z0-9/_-]+\.(?:jpg|jpeg|png|webp)$~i', $path)) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    /** @return literal-string */
    public function priceSortExpression(): string
    {
        return "(SELECT MIN(CASE WHEN pv.tracking_mode = 'serialized' AND pv.condition = 'used' THEN
                    (SELECT COALESCE(sd.unit_selling_price, pv.selling_price) FROM serialized_devices sd
                     JOIN locations loc ON loc.id = sd.location_id AND loc.business_id = sd.business_id
                     WHERE sd.business_id = products.business_id AND sd.product_variant_id = pv.id
                       AND sd.status = 'in_stock' AND sd.condition = 'used' AND loc.status = 'active'
                       AND NOT EXISTS (SELECT 1 FROM web_order_reservations reserved
                           WHERE reserved.active_device_id = sd.id AND reserved.business_id = sd.business_id AND reserved.status = 'active')
                     ORDER BY COALESCE(sd.unit_selling_price, pv.selling_price) ASC LIMIT 1)
                    ELSE pv.selling_price END)
                FROM product_variants pv WHERE pv.product_id = products.id AND pv.business_id = products.business_id AND pv.status = 'active')";
    }
}
