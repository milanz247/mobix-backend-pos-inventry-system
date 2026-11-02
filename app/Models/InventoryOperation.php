<?php

namespace App\Models;

use App\InventoryOperationType;
use Database\Factories\InventoryOperationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $business_id
 * @property string $operation_key
 * @property string $payload_hash
 * @property InventoryOperationType $type
 * @property string $status
 * @property int|null $source_location_id
 * @property int|null $destination_location_id
 * @property string|null $reference
 * @property string|null $reason
 * @property string|null $notes
 * @property int $performed_by
 * @property Carbon $posted_at
 */
#[Fillable(['business_id', 'operation_key', 'payload_hash', 'type', 'status', 'source_location_id', 'destination_location_id', 'reference', 'reason', 'notes', 'performed_by', 'posted_at'])]
class InventoryOperation extends Model
{
    /** @use HasFactory<InventoryOperationFactory> */
    use HasFactory;

    /** @return HasMany<InventoryOperationLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InventoryOperationLine::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function sourceLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'source_location_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'destination_location_id');
    }

    /** @return BelongsTo<User, $this> */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /** @return HasMany<SerializedDeviceEvent, $this> */
    public function deviceEvents(): HasMany
    {
        return $this->hasMany(SerializedDeviceEvent::class);
    }

    /** @return HasOne<WebOrderFulfillment, $this> */
    public function webFulfillment(): HasOne
    {
        return $this->hasOne(WebOrderFulfillment::class);
    }

    protected function casts(): array
    {
        return ['type' => InventoryOperationType::class, 'posted_at' => 'datetime'];
    }
}
