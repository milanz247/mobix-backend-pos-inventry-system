<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $business_id
 * @property int $product_id
 * @property int $product_variant_id
 * @property int $serialized_device_id
 * @property string $token_hash
 * @property numeric-string $selected_price
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 */
#[Fillable(['business_id', 'product_id', 'product_variant_id', 'serialized_device_id', 'token_hash', 'selected_price', 'expires_at', 'consumed_at'])]
class StorefrontDeviceSelection extends Model
{
    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** @return BelongsTo<SerializedDevice, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(SerializedDevice::class, 'serialized_device_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['selected_price' => 'decimal:2', 'expires_at' => 'datetime', 'consumed_at' => 'datetime'];
    }
}
