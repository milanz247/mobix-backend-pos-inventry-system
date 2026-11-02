<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $business_id
 * @property int $web_cart_id
 * @property string $reference
 * @property string $status
 * @property string $currency
 * @property numeric-string $subtotal
 * @property numeric-string $total
 * @property Carbon $expires_at
 * @property Carbon $created_at
 */
class WebQuote extends Model
{
    protected $fillable = ['business_id', 'web_cart_id', 'reference', 'status', 'currency', 'subtotal', 'total', 'expires_at'];

    protected function casts(): array
    {
        return ['subtotal' => 'decimal:2', 'total' => 'decimal:2', 'expires_at' => 'datetime'];
    }

    /** @return BelongsTo<WebCart, $this> */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(WebCart::class, 'web_cart_id');
    }

    /** @return HasMany<WebQuoteItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(WebQuoteItem::class);
    }
}
