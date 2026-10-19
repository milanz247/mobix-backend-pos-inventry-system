<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * @property int $id
 * @property int $cashier_shift_id
 * @property int|null $reverses_id
 * @property string $type
 * @property string $amount
 * @property string $reason
 * @property string|null $supporting_reference
 */
#[Fillable(['cashier_shift_id', 'created_by', 'reference', 'operation_key', 'type', 'amount', 'reason', 'supporting_reference', 'reverses_id'])]
class CashMovement extends Model
{
    /** @return BelongsTo<CashierShift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }

    /** @return BelongsTo<CashMovement, $this> */
    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasOne<CashMovement, $this> */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_id');
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Cash movements are immutable. Record a reversal.'));
        static::deleting(fn (): never => throw new LogicException('Cash movements cannot be deleted.'));
    }
}
