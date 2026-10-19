<?php

namespace App\Models;

use Database\Factories\SaleRefundFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property numeric-string $amount
 * @property string $method
 * @property Carbon $refunded_at
 */
#[Fillable(['cashier_shift_id', 'business_id', 'sale_id', 'amount', 'operation_key', 'method', 'reference', 'refunded_at', 'processed_by'])]
class SaleRefund extends Model
{
    /** @use HasFactory<SaleRefundFactory> */
    use HasFactory;

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** @return BelongsTo<SaleReturn, $this> */
    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    /** @return BelongsTo<User, $this> */
    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'refunded_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Posted sale refunds are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Posted sale refunds are immutable.'));
    }
}
