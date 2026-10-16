<?php

namespace App\Models;

use Database\Factories\SaleReturnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int $business_id
 * @property int $sale_id
 * @property int $location_id
 * @property numeric-string $total
 * @property numeric-string $refund_total
 * @property Carbon $processed_at
 * @property-read Collection<int, SaleReturnLine> $lines
 * @property-read Collection<int, SaleRefund> $refunds
 */
#[Fillable(['business_id', 'location_id', 'return_number', 'operation_key', 'payload_hash', 'total', 'refund_total', 'reason', 'notes', 'inventory_operation_id', 'processed_by', 'processed_at'])]
class SaleReturn extends Model
{
    /** @use HasFactory<SaleReturnFactory> */
    use HasFactory;

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<InventoryOperation, $this> */
    public function inventoryOperation(): BelongsTo
    {
        return $this->belongsTo(InventoryOperation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /** @return HasMany<SaleReturnLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(SaleReturnLine::class);
    }

    /** @return HasMany<SaleRefund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(SaleRefund::class);
    }

    protected function casts(): array
    {
        return ['total' => 'decimal:2', 'refund_total' => 'decimal:2', 'processed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Posted sale returns are immutable.'));
    }
}
