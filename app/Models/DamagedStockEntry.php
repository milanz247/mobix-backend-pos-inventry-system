<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $business_id
 * @property int $location_id
 * @property int $product_variant_id
 * @property string $quantity
 * @property string $remaining_quantity
 */
#[Fillable(['unit_cost', 'business_id', 'location_id', 'product_variant_id', 'inventory_operation_id', 'sale_return_line_id', 'quantity', 'remaining_quantity', 'reason', 'notes', 'recorded_by'])]
class DamagedStockEntry extends Model
{
    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<SaleReturnLine, $this> */
    public function returnLine(): BelongsTo
    {
        return $this->belongsTo(SaleReturnLine::class, 'sale_return_line_id');
    }

    protected function casts(): array
    {
        return ['unit_cost' => 'decimal:2', 'quantity' => 'decimal:3', 'remaining_quantity' => 'decimal:3'];
    }
}
