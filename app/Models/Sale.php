<?php

namespace App\Models;

use App\SalePaymentStatus;
use App\SaleSource;
use App\SaleStatus;
use Database\Factories\SaleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $business_id
 * @property int $location_id
 * @property int $cashier_id
 * @property string|null $sale_number
 * @property numeric-string $subtotal
 * @property numeric-string $discount_total
 * @property numeric-string $tax_total
 * @property numeric-string $total
 * @property string $paid_total
 * @property SaleSource $source
 * @property SaleStatus $status
 * @property SalePaymentStatus $payment_status
 * @property Carbon $posted_at
 * @property int $lines_count
 * @property-read Collection<int, SaleLine> $lines
 * @property-read Collection<int, SalePayment> $payments
 * @property-read Collection<int, SaleReturn> $returns
 * @property-read Collection<int, SaleRefund> $refunds
 */
#[Fillable(['cashier_shift_id', 'business_id', 'location_id', 'sale_number', 'operation_key', 'payload_hash', 'cashier_id', 'source', 'customer_name', 'customer_phone', 'customer_email', 'subtotal', 'discount_total', 'tax_total', 'total', 'paid_total', 'status', 'payment_status', 'inventory_operation_id', 'notes', 'posted_at'])]
class Sale extends Model
{
    /** @use HasFactory<SaleFactory> */
    use HasFactory;

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<User, $this> */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    /** @return BelongsTo<InventoryOperation, $this> */
    public function inventoryOperation(): BelongsTo
    {
        return $this->belongsTo(InventoryOperation::class);
    }

    /** @return HasMany<SaleLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(SaleLine::class);
    }

    /** @return HasMany<SalePayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    /** @return HasMany<SaleReturn, $this> */
    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }

    /** @return HasMany<SaleRefund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(SaleRefund::class);
    }

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_total' => 'decimal:2',
            'source' => SaleSource::class,
            'status' => SaleStatus::class,
            'payment_status' => SalePaymentStatus::class,
            'posted_at' => 'datetime',
        ];
    }
}
