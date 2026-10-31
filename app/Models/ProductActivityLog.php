<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $business_id
 * @property int|null $location_id
 * @property int|null $actor_id
 * @property string $actor_name
 * @property string $action
 * @property string $subject_type
 * @property int|null $subject_id
 * @property int $product_id
 * @property string $product_name
 * @property array<string, array{before: mixed, after: mixed}>|null $changes
 * @property Carbon $created_at
 * @property-read User|null $actor
 * @property-read Location|null $location
 */
#[Fillable(['business_id', 'location_id', 'actor_id', 'actor_name', 'action', 'subject_type', 'subject_id', 'product_id', 'product_name', 'changes', 'created_at'])]
class ProductActivityLog extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
