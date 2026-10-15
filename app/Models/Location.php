<?php

namespace App\Models;

use App\LocationStatus;
use App\LocationType;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $business_id
 * @property string $name
 * @property string $code
 * @property LocationType $type
 * @property string $phone
 * @property string|null $email
 * @property string $address
 * @property string $city
 * @property bool $is_main
 * @property LocationStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'code', 'type', 'phone', 'email', 'address', 'city', 'status'])]
#[Hidden(['main_slot'])]
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use HasFactory;

    /** @var array<string, string|bool> */
    protected $attributes = [
        'is_main' => false,
        'status' => 'active',
    ];

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => LocationType::class,
            'is_main' => 'boolean',
            'status' => LocationStatus::class,
        ];
    }
}
