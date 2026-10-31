<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ActivityLog extends Model
{
    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Activity records are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Activity records are immutable.'));
    }
}
