<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebHomeBanner extends Model
{
    protected $fillable = ['business_id', 'headline', 'eyebrow', 'description', 'button_text', 'button_href', 'image_path', 'image_alt', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
