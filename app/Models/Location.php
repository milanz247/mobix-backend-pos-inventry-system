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
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property numeric-string|null $quantity_on_hand
 * @property int $serialized_in_stock
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

    /** @return HasMany<InventoryStock, $this> */
    public function inventoryStocks(): HasMany
    {
        return $this->hasMany(InventoryStock::class);
    }

    /** @return HasMany<SerializedDevice, $this> */
    public function serializedDevices(): HasMany
    {
        return $this->hasMany(SerializedDevice::class);
    }

    /** @return HasMany<Purchase, $this> */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    /** @return HasMany<Sale, $this> */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /** @return HasMany<PurchaseReturn, $this> */
    public function purchaseReturns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class);
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
