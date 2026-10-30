<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $sale_id
 * @property string $payload_hash
 */
#[Fillable(['cashier_shift_id', 'business_id', 'sale_id', 'operation_key', 'payload_hash', 'created_by', 'total'])]
class SaleAddition extends Model {}
