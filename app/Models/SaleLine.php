<?php

namespace App\Models;

use App\VariantTrackingMode;
use Database\Factories\SaleLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property int $id
 * @property int $business_id
 * @property int $sale_id
 * @property int $product_variant_id
 * @property int|null $serialized_device_id
 * @property string $product_name_snapshot
 * @property string $variant_name_snapshot
 * @property string $sku_snapshot
 * @property VariantTrackingMode $tracking_mode
 * @property bool $is_combo
 * @property numeric-string $quantity
 * @property numeric-string $unit_price
 * @property numeric-string $discount_amount
 * @property numeric-string $cost_snapshot
 * @property numeric-string $subtotal
 * @property numeric-string $total
 * @property-read Collection<int, SaleLineComponent> $components
 * @property-read Collection<int, SaleReturnLine> $returnLines
 */
#[Fillable(['business_id', 'product_variant_id', 'serialized_device_id', 'product_name_snapshot', 'variant_name_snapshot', 'sku_snapshot', 'barcode_snapshot', 'tracking_mode', 'is_combo', 'imei_snapshot', 'quantity', 'unit_price', 'discount_amount', 'cost_snapshot', 'subtotal', 'total'])]
class SaleLine extends Model
{
    /** @use HasFactory<SaleLineFactory> */
    use HasFactory;

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
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

    /** @return HasMany<SaleLineComponent, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(SaleLineComponent::class);
    }

    /** @return HasMany<SaleReturnLine, $this> */
    public function returnLines(): HasMany
    {
        return $this->hasMany(SaleReturnLine::class);
    }

    protected function casts(): array
    {
        return [
            'tracking_mode' => VariantTrackingMode::class,
            'is_combo' => 'boolean',
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'cost_snapshot' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Posted sale lines are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Posted sale lines are immutable.'));
    }
}
