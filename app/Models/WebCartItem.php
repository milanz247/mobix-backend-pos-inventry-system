<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_key
 * @property int $business_id
 * @property int $web_cart_id
 * @property int $product_id
 * @property int $product_variant_id
 * @property int $quantity
 */
class WebCartItem extends Model
{
    protected $fillable = ['public_key', 'business_id', 'web_cart_id', 'product_id', 'product_variant_id', 'quantity'];

    /** @return BelongsTo<WebCart, $this> */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(WebCart::class, 'web_cart_id');
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
}
