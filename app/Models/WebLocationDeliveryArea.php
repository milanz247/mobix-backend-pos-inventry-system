<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $business_id
 * @property int $delivery_area_id
 * @property int $location_id
 * @property numeric-string|null $delivery_fee_override
 * @property int $selection_priority
 * @property bool $is_active
 * @property-read Location $location
 */
class WebLocationDeliveryArea extends Model
{
    protected $fillable = ['business_id', 'delivery_area_id', 'location_id', 'delivery_fee_override', 'selection_priority', 'is_active'];

    protected function casts(): array
    {
        return ['delivery_fee_override' => 'decimal:2', 'is_active' => 'boolean', 'selection_priority' => 'integer'];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
