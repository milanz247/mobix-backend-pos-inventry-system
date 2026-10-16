<?php

namespace App\Models;

use App\VariantTrackingMode;
use Database\Factories\PurchaseLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property VariantTrackingMode $tracking_mode
 * @property numeric-string $quantity
 * @property numeric-string $unit_cost
 * @property numeric-string $subtotal
 * @property numeric-string $discount_amount
 * @property numeric-string $total
 */
#[Fillable(['business_id', 'product_variant_id', 'tracking_mode', 'quantity', 'unit_cost', 'subtotal', 'discount_amount', 'total'])]
class PurchaseLine extends Model
{
    /** @use HasFactory<PurchaseLineFactory> */
    use HasFactory;

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** @return HasMany<PurchaseLineDevice, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(PurchaseLineDevice::class);
    }

    /** @return HasMany<PurchaseReturnLine, $this> */
    public function returnLines(): HasMany
    {
        return $this->hasMany(PurchaseReturnLine::class);
    }

    protected function casts(): array
    {
        return [
            'tracking_mode' => VariantTrackingMode::class,
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }
}
