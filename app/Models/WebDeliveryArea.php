<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $business_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property numeric-string $base_delivery_fee
 * @property string $currency
 * @property bool $is_active
 * @property-read Collection<int, WebLocationDeliveryArea> $mappings
 */
class WebDeliveryArea extends Model
{
    protected $fillable = ['business_id', 'code', 'name', 'description', 'base_delivery_fee', 'currency', 'is_active'];

    protected function casts(): array
    {
        return ['base_delivery_fee' => 'decimal:2', 'is_active' => 'boolean'];
    }

    /** @return HasMany<WebLocationDeliveryArea, $this> */
    public function mappings(): HasMany
    {
        return $this->hasMany(WebLocationDeliveryArea::class, 'delivery_area_id');
    }
}
