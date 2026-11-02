<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebStorefrontSetting extends Model
{
    protected $fillable = ['business_id', 'announcement_text', 'announcement_direction', 'announcement_enabled'];

    protected function casts(): array
    {
        return ['announcement_enabled' => 'boolean'];
    }
}
