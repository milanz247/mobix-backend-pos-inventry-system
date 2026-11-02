<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/** @property Carbon|null $reservation_expires_at
 * @property Carbon|null $cod_confirmation_deadline_at
 * @property Carbon|null $bank_proof_deadline_at
 * @property Carbon|null $submitted_at
 * @property array<string, mixed>|null $contact_snapshot
 * @property array<string, mixed>|null $address_snapshot
 */
class WebOrder extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['payload_hash', 'guest_principal_hash', 'guest_access_token_hash', 'accepted_terms_digest'];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2', 'discount_total' => 'decimal:2',
            'delivery_fee' => 'decimal:2', 'total' => 'decimal:2',
            'product_tax_rate_snapshot' => 'decimal:2', 'product_tax_total_snapshot' => 'decimal:2',
            'contact_snapshot' => 'array', 'address_snapshot' => 'array',
            'submitted_at' => 'datetime', 'reservation_expires_at' => 'datetime',
            'cod_confirmation_deadline_at' => 'datetime', 'bank_proof_deadline_at' => 'datetime',
        ];
    }

    /** @return HasMany<WebOrderReservation, $this> */
    public function reservations(): HasMany
    {
        return $this->hasMany(WebOrderReservation::class);
    }

    /** @return HasOne<Sale, $this> */
    public function sale(): HasOne
    {
        return $this->hasOne(Sale::class);
    }

    /** @return HasOne<WebOrderFulfillment, $this> */
    public function fulfillment(): HasOne
    {
        return $this->hasOne(WebOrderFulfillment::class);
    }

    /** @return HasOne<SalePayment, $this> */
    public function codCollection(): HasOne
    {
        return $this->hasOne(SalePayment::class, 'web_cod_order_id');
    }
}
