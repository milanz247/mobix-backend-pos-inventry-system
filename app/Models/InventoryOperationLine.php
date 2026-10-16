<?php

namespace App\Models;

use App\ProductCondition;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'product_variant_id', 'quantity', 'unit_cost', 'unit_selling_price', 'condition'])]
class InventoryOperationLine extends Model
{
    /** @return BelongsTo<InventoryOperation, $this> */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(InventoryOperation::class, 'inventory_operation_id');
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_cost' => 'decimal:2', 'unit_selling_price' => 'decimal:2', 'condition' => ProductCondition::class];
    }
}
