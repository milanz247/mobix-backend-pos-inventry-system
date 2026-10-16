<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'identifier', 'slot'])]
class SerializedDeviceIdentifier extends Model
{
    public $timestamps = false;

    /** @return BelongsTo<SerializedDevice, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(SerializedDevice::class, 'serialized_device_id');
    }
}
