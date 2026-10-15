<?php

namespace App\Models;

use App\CatalogStatus;
use App\ProductType;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $business_id
 * @property int $category_id
 * @property int|null $brand_id
 * @property string $name
 * @property ProductType $product_type
 * @property string|null $description
 * @property string|null $image_path
 * @property CatalogStatus $status
 * @property int $active_variants_count
 * @property string|null $lowest_variant_price
 * @property string|null $primary_sku
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['category_id', 'brand_id', 'name', 'product_type', 'description', 'image_path', 'status'])]

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $attributes = ['status' => 'active'];

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'product_type' => ProductType::class,
            'status' => CatalogStatus::class,
        ];
    }
}
