<?php

namespace App\Models;

use App\ProductCondition;
use App\SerializedDeviceStatus;
use Database\Factories\SerializedDeviceFactory;
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
 * @property int $product_variant_id
 * @property int $location_id
 * @property string $imei
 * @property string|null $imei2
 * @property string|null $serial_number
 * @property ProductCondition $condition
 * @property SerializedDeviceStatus $status
 * @property numeric-string $unit_cost
 * @property numeric-string|null $unit_selling_price
 * @property Carbon $received_at
 * @property-read ProductVariant $variant
 * @property-read Location $location
 * @property-read Collection<int, SerializedDeviceEvent> $events
 */
#[Fillable(['business_id', 'product_variant_id', 'location_id', 'received_operation_id', 'imei', 'imei2', 'serial_number', 'condition', 'status', 'unit_cost', 'unit_selling_price', 'received_at', 'created_by'])]
class SerializedDevice extends Model
{
    /** @use HasFactory<SerializedDeviceFactory> */
    use HasFactory;

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return HasMany<SerializedDeviceEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(SerializedDeviceEvent::class);
    }

    /** @return HasMany<SerializedDeviceIdentifier, $this> */
    public function identifiers(): HasMany
    {
        return $this->hasMany(SerializedDeviceIdentifier::class);
    }

    /** @return HasMany<PurchaseLineDevice, $this> */
    public function purchaseLineDevices(): HasMany
    {
        return $this->hasMany(PurchaseLineDevice::class);
    }

    /** @return HasMany<PurchaseReturnLine, $this> */
    public function purchaseReturnLines(): HasMany
    {
        return $this->hasMany(PurchaseReturnLine::class);
    }

    protected function casts(): array
    {
        return ['condition' => ProductCondition::class, 'status' => SerializedDeviceStatus::class, 'unit_cost' => 'decimal:2', 'unit_selling_price' => 'decimal:2', 'received_at' => 'datetime'];
    }
}
