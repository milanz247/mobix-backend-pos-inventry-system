<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/** @property Carbon $expires_at */
class WebOrderOffer extends Model
{
    protected $attributes = ['terms_digest_version' => null];

    protected $fillable = ['business_id', 'web_cart_id', 'web_quote_id', 'delivery_area_id', 'location_id', 'delivery_area_code_snapshot', 'delivery_area_name_snapshot', 'fulfillment_mode', 'pickup_location_code_snapshot', 'pickup_location_name_snapshot', 'pickup_location_address_snapshot', 'pickup_location_city_snapshot', 'reference', 'principal_hash', 'status', 'currency', 'subtotal', 'discount_total', 'delivery_fee', 'total', 'payment_method', 'contact_snapshot', 'address_snapshot', 'terms_digest', 'terms_digest_version', 'expires_at'];

    protected $hidden = ['principal_hash', 'contact_snapshot', 'address_snapshot', 'terms_digest_version'];

    protected function casts(): array
    {
        return ['terms_digest_version' => 'integer', 'subtotal' => 'decimal:2', 'discount_total' => 'decimal:2', 'delivery_fee' => 'decimal:2', 'total' => 'decimal:2', 'contact_snapshot' => 'array', 'address_snapshot' => 'array', 'expires_at' => 'datetime'];
    }

    /** @return HasMany<WebOrderOfferItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(WebOrderOfferItem::class);
    }

    /** @return HasOne<WebOrder, $this> */
    public function order(): HasOne
    {
        return $this->hasOne(WebOrder::class, 'web_order_offer_id');
    }
}
