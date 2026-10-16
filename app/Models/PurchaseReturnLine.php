<?php

namespace App\Models;

use Database\Factories\PurchaseReturnLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'purchase_line_id', 'product_variant_id', 'serialized_device_id', 'quantity', 'unit_cost', 'total'])]
class PurchaseReturnLine extends Model
{
    /** @use HasFactory<PurchaseReturnLineFactory> */
    use HasFactory;

    /** @return BelongsTo<PurchaseReturn, $this> */
    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    /** @return BelongsTo<PurchaseLine, $this> */
    public function purchaseLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseLine::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** @return BelongsTo<SerializedDevice, $this> */
    public function serializedDevice(): BelongsTo
    {
        return $this->belongsTo(SerializedDevice::class);
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_cost' => 'decimal:2', 'total' => 'decimal:2'];
    }
}
