<?php

namespace App\Models;

use App\SerializedDeviceEventType;
use App\SerializedDeviceStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int $business_id
 * @property int $serialized_device_id
 * @property int|null $inventory_operation_id
 * @property string $event_group_id
 * @property SerializedDeviceEventType $event_type
 * @property int|null $from_location_id
 * @property int|null $to_location_id
 * @property SerializedDeviceStatus|null $status_before
 * @property SerializedDeviceStatus $status_after
 * @property int $performed_by
 * @property Carbon $created_at
 * @property-read Location|null $fromLocation
 * @property-read Location|null $toLocation
 * @property-read User $performer
 */
#[Fillable(['business_id', 'inventory_operation_id', 'event_group_id', 'event_type', 'from_location_id', 'to_location_id', 'status_before', 'status_after', 'reason', 'notes', 'performed_by'])]
class SerializedDeviceEvent extends Model
{
    public const UPDATED_AT = null;

    /** @return BelongsTo<SerializedDevice, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(SerializedDevice::class, 'serialized_device_id');
    }

    /** @return BelongsTo<InventoryOperation, $this> */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(InventoryOperation::class, 'inventory_operation_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    /** @return BelongsTo<User, $this> */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Serialized device events are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Serialized device events are immutable.'));
    }

    protected function casts(): array
    {
        return ['event_type' => SerializedDeviceEventType::class, 'status_before' => SerializedDeviceStatus::class, 'status_after' => SerializedDeviceStatus::class];
    }
}
