<?php

namespace App\Models;

use App\ProductCondition;
use Database\Factories\PurchaseLineDeviceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'imei', 'imei2', 'serial_number', 'condition', 'unit_cost', 'unit_selling_price', 'serialized_device_id'])]
class PurchaseLineDevice extends Model
{
    /** @use HasFactory<PurchaseLineDeviceFactory> */
    use HasFactory;

    /** @return BelongsTo<PurchaseLine, $this> */
    public function purchaseLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseLine::class);
    }

    /** @return BelongsTo<SerializedDevice, $this> */
    public function serializedDevice(): BelongsTo
    {
        return $this->belongsTo(SerializedDevice::class);
    }

    protected function casts(): array
    {
        return [
            'condition' => ProductCondition::class,
            'unit_cost' => 'decimal:2',
            'unit_selling_price' => 'decimal:2',
        ];
    }
}
