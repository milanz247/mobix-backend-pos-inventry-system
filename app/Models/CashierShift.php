<?php

namespace App\Models;

use Database\Factories\CashierShiftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int $business_id
 * @property int $location_id
 * @property int $cashier_id
 * @property string $reference
 * @property string $operation_key
 * @property string $status
 * @property string $opening_cash
 * @property string|null $actual_cash
 * @property string|null $variance
 * @property array<string, mixed>|null $closing_summary
 * @property Carbon $opened_at
 * @property Carbon|null $closed_at
 * @property-read User $cashier
 * @property-read Location $location
 */
#[Fillable(['business_id', 'location_id', 'cashier_id', 'active_cashier_id', 'reference', 'operation_key', 'status', 'opening_cash', 'opening_note', 'opened_at', 'closed_at', 'closed_by', 'expected_cash', 'actual_cash', 'variance', 'variance_reason', 'closing_note', 'closing_summary', 'approved_by', 'approver_name', 'approved_at'])]
class CashierShift extends Model
{
    /** @use HasFactory<CashierShiftFactory> */
    use HasFactory;

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<User, $this> */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return HasMany<CashMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    /** @return HasMany<ShiftEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ShiftEvent::class);
    }

    protected function casts(): array
    {
        return ['opening_cash' => 'decimal:2', 'expected_cash' => 'decimal:2', 'actual_cash' => 'decimal:2', 'variance' => 'decimal:2', 'closing_summary' => 'array', 'opened_at' => 'datetime', 'closed_at' => 'datetime', 'approved_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Shift history cannot be deleted.'));
        static::updating(function (self $shift): void {
            if ($shift->getOriginal('closed_at') !== null && array_diff(array_keys($shift->getDirty()), ['status', 'approved_by', 'approver_name', 'approved_at', 'updated_at']) !== []) {
                throw new LogicException('Closed shift totals are immutable.');
            }
        });
    }
}
