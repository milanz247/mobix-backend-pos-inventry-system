<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $business_id
 * @property int $location_id
 * @property int $created_by
 * @property string|null $label
 * @property array<string, mixed> $payload
 * @property Carbon $created_at
 */
#[Fillable(['id', 'business_id', 'location_id', 'created_by', 'label', 'payload'])]
class HeldCart extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
