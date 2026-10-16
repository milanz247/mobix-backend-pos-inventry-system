<?php

namespace App\Models;

use Database\Factories\SaleReturnLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $business_id
 * @property int $sale_line_id
 * @property int|null $sale_line_component_id
 * @property int|null $serialized_device_id
 * @property bool $is_component
 * @property bool $restock
 * @property numeric-string $quantity
 * @property numeric-string $unit_price
 * @property numeric-string $unit_cost_snapshot
 * @property numeric-string $total
 */
#[Fillable(['business_id', 'sale_line_id', 'sale_line_component_id', 'serialized_device_id', 'is_component', 'restock', 'quantity', 'unit_price', 'unit_cost_snapshot', 'total'])]
class SaleReturnLine extends Model
{
    /** @use HasFactory<SaleReturnLineFactory> */
    use HasFactory;

    /** @return BelongsTo<SaleReturn, $this> */
    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    /** @return BelongsTo<SaleLine, $this> */
    public function saleLine(): BelongsTo
    {
        return $this->belongsTo(SaleLine::class);
    }

    /** @return BelongsTo<SaleLineComponent, $this> */
    public function component(): BelongsTo
    {
        return $this->belongsTo(SaleLineComponent::class, 'sale_line_component_id');
    }

    /** @return BelongsTo<SerializedDevice, $this> */
    public function serializedDevice(): BelongsTo
    {
        return $this->belongsTo(SerializedDevice::class);
    }

    protected function casts(): array
    {
        return ['is_component' => 'boolean', 'restock' => 'boolean', 'quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'unit_cost_snapshot' => 'decimal:2', 'total' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Posted sale return lines are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Posted sale return lines are immutable.'));
    }
}
