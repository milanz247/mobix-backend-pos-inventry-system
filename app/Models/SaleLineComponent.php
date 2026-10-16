<?php

namespace App\Models;

use Database\Factories\SaleLineComponentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['business_id', 'product_variant_id', 'serialized_device_id', 'sku_snapshot', 'name_snapshot', 'imei_snapshot', 'quantity', 'unit_cost_snapshot'])]
class SaleLineComponent extends Model
{
    /** @use HasFactory<SaleLineComponentFactory> */
    use HasFactory;

    /** @return BelongsTo<SaleLine, $this> */
    public function saleLine(): BelongsTo
    {
        return $this->belongsTo(SaleLine::class);
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

    /** @return HasMany<SaleReturnLine, $this> */
    public function returnLines(): HasMany
    {
        return $this->hasMany(SaleReturnLine::class);
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_cost_snapshot' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Posted sale components are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Posted sale components are immutable.'));
    }
}
