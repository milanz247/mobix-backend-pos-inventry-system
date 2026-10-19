<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $type
 * @property Carbon $created_at
 * @property array<string, mixed> $details
 * @property-read User $actor
 */
#[Fillable(['cashier_shift_id', 'actor_id', 'type', 'details', 'created_at'])]
class ShiftEvent extends Model
{
    public $timestamps = false;

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Shift audit events are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Shift audit events cannot be deleted.'));
    }
}
