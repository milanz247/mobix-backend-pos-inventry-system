<?php

namespace App\Services;

use App\CatalogStatus;
use App\Models\Business;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\ProductCondition;
use App\ProductType;
use App\VariantTrackingMode;
use Brick\Math\BigDecimal;

/** Advisory, aggregate cart demand. Transactional order allocation must recheck under stock/device locks. */
class StorefrontCartDemandService
{
    public function __construct(private readonly StockAvailabilityService $stock) {}

    /** @param list<array{product: Product, variant: ProductVariant, quantity: int}> $lines
     * @phpstan-impure
     */
    public function canFulfill(Business $business, Location $location, array $lines): bool
    {
        /** @var array<int, BigDecimal> $demand */
        $demand = [];
        /** @var array<int, ProductVariant> $variants */
        $variants = [];
        foreach ($lines as $line) {
            $product = $line['product'];
            $variant = $line['variant'];
            if ($product->business_id !== $business->id || $variant->business_id !== $business->id ||
                $variant->product_id !== $product->id || $line['quantity'] < 1) {
                return false;
            }
            if ($product->product_type === ProductType::Combo) {
                $components = $variant->bundleComponents()->with('componentVariant.product')->get();
                if ($components->isEmpty()) {
                    return false;
                }
                foreach ($components as $component) {
                    $part = $component->componentVariant;
                    if ($part === null || $part->business_id !== $business->id ||
                        $part->status !== CatalogStatus::Active || $part->product->status !== CatalogStatus::Active ||
                        in_array($part->product->product_type, [ProductType::Combo, ProductType::Service], true) ||
                        BigDecimal::of($component->quantity)->isLessThanOrEqualTo('0')) {
                        return false;
                    }
                    $demand[$part->id] = ($demand[$part->id] ?? BigDecimal::zero())
                        ->plus(BigDecimal::of($component->quantity)->multipliedBy($line['quantity']));
                    $variants[$part->id] = $part;
                }
            } else {
                $demand[$variant->id] = ($demand[$variant->id] ?? BigDecimal::zero())->plus($line['quantity']);
                $variants[$variant->id] = $variant;
            }
        }
        foreach ($demand as $id => $quantity) {
            $variant = $variants[$id];
            $row = $this->stock->query($business, $location)->with('product')->where('product_variants.id', $id)->first();
            if ($row === null) {
                return false;
            }
            $available = $this->stock->describe($row);
            if (! $available['can_sell'] || BigDecimal::of($available['available_quantity'])->isLessThan($quantity)) {
                return false;
            }
            if ($variant->tracking_mode === VariantTrackingMode::Serialized) {
                if ($quantity->remainder('1')->isGreaterThan('0')) {
                    return false;
                }
                $sellable = $variant->serializedDevices()->where('business_id', $business->id)
                    ->where('location_id', $location->id)->where('status', 'in_stock')
                    ->when($variant->condition === ProductCondition::Used, fn ($query) => $query->where('condition', 'used'))
                    ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('web_order_reservations as reserved')
                        ->whereColumn('reserved.active_device_id', 'serialized_devices.id')
                        ->where('reserved.business_id', $business->id)->where('reserved.status', 'active'))
                    ->count();
                if (BigDecimal::of($sellable)->isLessThan($quantity)) {
                    return false;
                }
            }
        }

        return true;
    }
}
