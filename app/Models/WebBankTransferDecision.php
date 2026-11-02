<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WebBankTransferDecision extends Model
{
    protected $guarded = ['id'];

    /** @return HasOne<SalePayment, $this> */
    public function salePayment(): HasOne
    {
        return $this->hasOne(SalePayment::class);
    }

    protected function casts(): array
    {
        return ['received_amount' => 'decimal:2', 'reviewed_at' => 'datetime'];
    }
}
