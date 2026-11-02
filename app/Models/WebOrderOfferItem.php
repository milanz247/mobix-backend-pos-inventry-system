<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @property array<int, mixed>|null $private_allocation */
class WebOrderOfferItem extends Model
{
    protected $fillable = ['business_id', 'web_order_offer_id', 'source_cart_item_key', 'line_number', 'product_id', 'product_variant_id', 'serialized_device_id', 'private_allocation', 'product_slug', 'product_name', 'variant_name', 'condition_snapshot', 'included_device_conditions', 'quantity', 'unit_price', 'line_total'];

    protected $hidden = ['serialized_device_id', 'private_allocation'];

    protected function casts(): array
    {
        return ['private_allocation' => 'array', 'included_device_conditions' => 'array', 'unit_price' => 'decimal:2', 'line_total' => 'decimal:2'];
    }
}
