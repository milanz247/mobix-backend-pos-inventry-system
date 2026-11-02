<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Initial payment obligation only; no collection or verification is recorded at submission. */
class WebOrderPayment extends Model
{
    protected $fillable = ['business_id', 'web_order_id', 'method', 'status', 'currency', 'amount_due'];

    protected function casts(): array
    {
        return ['amount_due' => 'decimal:2'];
    }
}
