<?php

namespace App\Models;

use Database\Factories\PurchasePaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $payment_date
 */
#[Fillable(['business_id', 'amount', 'operation_key', 'payload_hash', 'payment_date', 'method', 'reference', 'notes', 'recorded_by'])]
class PurchasePayment extends Model
{
    /** @use HasFactory<PurchasePaymentFactory> */
    use HasFactory;

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'payment_date' => 'date'];
    }
}
