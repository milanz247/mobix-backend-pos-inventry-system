<?php

namespace App\Services;

use App\CatalogStatus;
use App\Models\Business;
use App\Models\Location;
use App\Models\ProductVariant;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class StockAvailabilityService
{
    /** @return Builder<ProductVariant> */
    public function query(Business $business, Location $location): Builder
    {
        $balances = DB::table('inventory_stocks')->where('business_id', $business->id)->where('location_id', $location->id)
            ->selectRaw('product_variant_id, SUM(quantity_on_hand) as quantity')->groupBy('product_variant_id');
        $devices = DB::table('serialized_devices')->where('business_id', $business->id)->where('location_id', $location->id)
            ->where('status', 'in_stock')->selectRaw('product_variant_id, COUNT(*) as device_count')->groupBy('product_variant_id');
        $movements = DB::table('stock_movements')->where('business_id', $business->id)->where('location_id', $location->id)
            ->selectRaw('product_variant_id, MAX(created_at) as last_movement_at')->groupBy('product_variant_id');
        $invalidComponent = "cv.status <> 'active' OR cp.status <> 'active' OR cp.product_type = 'combo' OR cv.business_id <> ? OR bc.quantity <= 0 OR (cv.tracking_mode = 'serialized' AND MOD(bc.quantity, 1) <> 0)";
        $componentQuantity = "CASE WHEN cv.tracking_mode = 'serialized' THEN COALESCE(cd.device_count, 0) ELSE COALESCE(cq.quantity, 0) END";
        $bundles = DB::table('bundle_components as bc')
            ->join('product_variants as cv', 'cv.id', '=', 'bc.component_variant_id')
            ->join('products as cp', 'cp.id', '=', 'cv.product_id')
            ->leftJoinSub(clone $balances, 'cq', 'cq.product_variant_id', '=', 'cv.id')
            ->leftJoinSub(clone $devices, 'cd', 'cd.product_variant_id', '=', 'cv.id')
            ->leftJoinSub(clone $movements, 'cm', 'cm.product_variant_id', '=', 'cv.id')
            ->where('bc.business_id', $business->id)
            ->select('bc.bundle_variant_id')
            ->selectRaw("MIN(CASE WHEN {$invalidComponent} THEN 0 ELSE FLOOR(({$componentQuantity}) / NULLIF(bc.quantity, 0)) END) as capacity", [$business->id])
            ->selectRaw("MAX(CASE WHEN {$invalidComponent} OR COALESCE(cq.quantity, 0) < 0 THEN 1 ELSE 0 END) as inconsistent", [$business->id])
            ->selectRaw('MAX(cm.last_movement_at) as last_component_movement_at')->groupBy('bc.bundle_variant_id');

        $stock = ProductVariant::query()->where('product_variants.business_id', $business->id)
            ->join('products as ap', 'ap.id', '=', 'product_variants.product_id')
            ->leftJoinSub($balances, 'q', 'q.product_variant_id', '=', 'product_variants.id')
            ->leftJoinSub($devices, 'd', 'd.product_variant_id', '=', 'product_variants.id')
            ->leftJoinSub($bundles, 'b', 'b.bundle_variant_id', '=', 'product_variants.id')
            ->leftJoinSub($movements, 'm', 'm.product_variant_id', '=', 'product_variants.id')
            ->leftJoin('reorder_levels as rl', fn ($join) => $join->on('rl.product_variant_id', '=', 'product_variants.id')->where('rl.location_id', $location->id)->where('rl.business_id', $business->id))
            ->select('product_variants.*')->selectRaw('rl.quantity AS reorder_quantity')
            ->selectRaw('COALESCE(q.quantity, 0) as quantity_on_hand, COALESCE(d.device_count, 0) as devices_in_stock')
            ->selectRaw("CASE WHEN ap.product_type = 'combo' THEN COALESCE(b.capacity, 0) WHEN product_variants.tracking_mode = 'serialized' THEN COALESCE(d.device_count, 0) ELSE COALESCE(q.quantity, 0) END as available_quantity")
            ->selectRaw("CASE WHEN COALESCE(q.quantity, 0) < 0 THEN 1 WHEN ap.product_type = 'combo' AND (b.capacity IS NULL OR COALESCE(q.quantity, 0) <> 0 OR b.inconsistent = 1) THEN 1 ELSE 0 END as stock_inconsistent")
            ->selectRaw('COALESCE(GREATEST(m.last_movement_at, b.last_component_movement_at), m.last_movement_at, b.last_component_movement_at) as last_movement_at');

        return ProductVariant::query()->fromSub($stock, 'product_variants');
    }

    /** @return array{available_quantity: string, stock_status: string, can_sell: bool} */
    public function describe(ProductVariant $variant): array
    {
        $quantity = BigDecimal::of((string) $variant->getAttribute('available_quantity'))->toScale(3);
        $inconsistent = (bool) $variant->getAttribute('stock_inconsistent') || $quantity->isNegative();

        return [
            'available_quantity' => (string) $quantity,
            'stock_status' => $inconsistent ? 'inconsistent' : ($quantity->isZero() ? 'out_of_stock' : ($variant->getAttribute('reorder_quantity') !== null && $quantity->isLessThanOrEqualTo((string) $variant->getAttribute('reorder_quantity')) ? 'low_stock' : 'in_stock')),
            'can_sell' => ! $inconsistent && $quantity->isGreaterThanOrEqualTo('1')
                && $variant->status === CatalogStatus::Active && $variant->product->status === CatalogStatus::Active,
        ];
    }
}
