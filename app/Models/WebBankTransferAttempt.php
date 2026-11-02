<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** @property Carbon|null $submitted_at */
class WebBankTransferAttempt extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['payload_hash', 'evidence_path'];

    protected function casts(): array
    {
        return ['submitted_amount' => 'decimal:2', 'transfer_date' => 'date', 'submitted_at' => 'datetime'];
    }
}
