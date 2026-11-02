<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $business_id
 * @property int $web_quote_id
 * @property int|null $product_id
 * @property int|null $product_variant_id
 * @property string $product_slug
 * @property string $product_name
 * @property string $variant_name
 * @property int $quantity
 * @property numeric-string $unit_price
 * @property numeric-string $line_total
 * @property string $availability
 * @property bool $requires_device_confirmation
 */
class WebQuoteItem extends Model
{
    protected $fillable = ['business_id', 'web_quote_id', 'product_id', 'product_variant_id', 'product_slug', 'product_name', 'variant_name', 'quantity', 'unit_price', 'line_total', 'availability', 'requires_device_confirmation'];

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:2', 'line_total' => 'decimal:2', 'requires_device_confirmation' => 'boolean'];
    }

    /** @return BelongsTo<WebQuote, $this> */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(WebQuote::class, 'web_quote_id');
    }
}
