<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebPickupLocation extends Model
{
    protected $fillable = ['business_id', 'location_id', 'public_code', 'display_name', 'selection_priority', 'is_active'];

    protected function casts(): array
    {
        return ['selection_priority' => 'integer', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function publicName(): string
    {
        return $this->display_name ?: $this->location->name;
    }
}
