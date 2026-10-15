<?php

namespace App\Models;

use App\CatalogStatus;
use App\ProductCondition;
use App\VariantTrackingMode;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $business_id
 * @property int $product_id
 * @property string $name
 * @property string $sku
 * @property string|null $barcode
 * @property string|null $model_number
 * @property string|null $storage_capacity
 * @property string|null $color
 * @property array<string, mixed>|null $variant_attributes
 * @property ProductCondition|null $condition
 * @property VariantTrackingMode $tracking_mode
 * @property string $unit
 * @property numeric-string $cost_price
 * @property numeric-string $selling_price
 * @property numeric-string|null $minimum_selling_price
 * @property CatalogStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'sku', 'barcode', 'model_number', 'storage_capacity', 'color', 'variant_attributes', 'condition', 'tracking_mode', 'unit', 'cost_price', 'selling_price', 'minimum_selling_price', 'status'])]
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory;

    protected $attributes = ['name' => 'Default', 'tracking_mode' => 'quantity_based', 'unit' => 'piece', 'status' => 'active'];

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<BundleComponent, $this> */
    public function bundleComponents(): HasMany
    {
        return $this->hasMany(BundleComponent::class, 'bundle_variant_id');
    }

    /** @return HasMany<BundleComponent, $this> */
    public function includedInBundles(): HasMany
    {
        return $this->hasMany(BundleComponent::class, 'component_variant_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'variant_attributes' => 'array',
            'condition' => ProductCondition::class,
            'tracking_mode' => VariantTrackingMode::class,
            'cost_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'minimum_selling_price' => 'decimal:2',
            'status' => CatalogStatus::class,
        ];
    }
}
