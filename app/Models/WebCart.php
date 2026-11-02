<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $business_id
 * @property string $token_hash
 * @property string $status
 * @property Carbon $expires_at
 * @property Carbon $last_activity_at
 */
class WebCart extends Model
{
    protected $fillable = ['business_id', 'token_hash', 'status', 'expires_at', 'last_activity_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'last_activity_at' => 'datetime'];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return HasMany<WebCartItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(WebCartItem::class);
    }

    /** @return HasMany<WebQuote, $this> */
    public function quotes(): HasMany
    {
        return $this->hasMany(WebQuote::class);
    }
}
