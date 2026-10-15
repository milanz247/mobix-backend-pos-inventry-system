<?php

namespace App\Models;

use App\BusinessStatus;
use App\BusinessType;
use App\TimeFormat;
use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $owner_id
 * @property string $setup_key
 * @property string $name
 * @property BusinessType $business_type
 * @property string|null $email
 * @property string $phone
 * @property string $address
 * @property string $city
 * @property string $country
 * @property string $currency
 * @property string $timezone
 * @property TimeFormat $time_format
 * @property string|null $logo_path
 * @property BusinessStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'business_type', 'email', 'phone', 'address', 'city', 'country', 'currency', 'timezone', 'time_format', 'logo_path', 'status'])]
class Business extends Model
{
    /** @use HasFactory<BusinessFactory> */
    use HasFactory;

    /**
     * The model's default attributes.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'country' => 'Sri Lanka',
        'currency' => 'LKR',
        'timezone' => 'Asia/Colombo',
        'time_format' => '12',
        'status' => 'active',
    ];

    /**
     * Get the owner account for the business.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasMany<Location, $this> */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'business_type' => BusinessType::class,
            'time_format' => TimeFormat::class,
            'status' => BusinessStatus::class,
        ];
    }
}
