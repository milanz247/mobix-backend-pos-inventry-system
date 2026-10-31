<?php

namespace App\Models;

use Database\Factories\PurchaseReturnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $return_date
 */
#[Fillable(['business_id', 'purchase_id', 'location_id', 'return_number', 'return_date', 'status', 'total', 'notes', 'operation_key', 'payload_hash', 'inventory_operation_id', 'created_by', 'posted_by', 'posted_at'])]
class PurchaseReturn extends Model
{
    /** @use HasFactory<PurchaseReturnFactory> */
    use HasFactory;

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
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

    /** @return HasMany<PurchaseReturnLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseReturnLine::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['return_date' => 'date', 'posted_at' => 'datetime', 'total' => 'decimal:2'];
    }
}
