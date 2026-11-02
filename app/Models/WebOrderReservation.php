<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** @property Carbon|null $expires_at */
class WebOrderReservation extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['serialized_device_id', 'active_device_id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'expires_at' => 'datetime', 'transitioned_at' => 'datetime'];
    }
}
