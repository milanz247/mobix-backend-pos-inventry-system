<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * @property int $business_id
 * @property int $bundle_variant_id
 * @property int $component_variant_id
 * @property numeric-string $quantity
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['business_id', 'bundle_variant_id', 'component_variant_id', 'quantity'])]
class BundleComponent extends Pivot
{
    public $incrementing = false;

    protected $table = 'bundle_components';

    /** @return BelongsTo<ProductVariant, $this> */
    public function bundleVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'bundle_variant_id');
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function componentVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'component_variant_id');
    }
}
