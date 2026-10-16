<?php

namespace App\Models;

use App\StockMovementType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int $business_id
 * @property int $location_id
 * @property int $product_variant_id
 * @property int $inventory_operation_id
 * @property string $movement_group_id
 * @property StockMovementType $movement_type
 * @property numeric-string $quantity_delta
 * @property numeric-string $balance_before
 * @property numeric-string $balance_after
 * @property int $performed_by
 * @property Carbon $created_at
 * @property-read Location $location
 * @property-read ProductVariant $variant
 * @property-read InventoryOperation $operation
 * @property-read User $performer
 */
#[Fillable(['business_id', 'location_id', 'product_variant_id', 'inventory_operation_id', 'movement_group_id', 'movement_type', 'quantity_delta', 'balance_before', 'balance_after', 'reference_type', 'reference_id', 'reason', 'notes', 'performed_by'])]
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** @return BelongsTo<InventoryOperation, $this> */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(InventoryOperation::class, 'inventory_operation_id');
    }

    /** @return BelongsTo<User, $this> */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /** @return MorphTo<Model, $this> */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Posted stock movements are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Posted stock movements are immutable.'));
    }

    protected function casts(): array
    {
        return ['movement_type' => StockMovementType::class, 'quantity_delta' => 'decimal:3', 'balance_before' => 'decimal:3', 'balance_after' => 'decimal:3'];
    }
}
