<?php

namespace App\Models;

use Database\Factories\SalePaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property numeric-string $amount
 * @property numeric-string|null $cash_tendered
 * @property numeric-string $change_given
 * @property string $method
 * @property Carbon $paid_at
 */
#[Fillable(['business_id', 'amount', 'cash_tendered', 'change_given', 'operation_key', 'payload_hash', 'method', 'reference', 'paid_at', 'received_by'])]
class SalePayment extends Model
{
    /** @use HasFactory<SalePaymentFactory> */
    use HasFactory;

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** @return BelongsTo<User, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'cash_tendered' => 'decimal:2', 'change_given' => 'decimal:2', 'paid_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Posted sale payments are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Posted sale payments are immutable.'));
    }
}
