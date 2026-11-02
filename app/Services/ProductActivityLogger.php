<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductActivityLog;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Auth;

class ProductActivityLogger
{
    private const PRODUCT_FIELDS = ['name', 'category_id', 'brand_id', 'product_type', 'description', 'image_path', 'status', 'variants'];

    private const VARIANT_FIELDS = ['name', 'sku', 'barcode', 'model_number', 'storage_capacity', 'color', 'variant_attributes', 'condition', 'tracking_mode', 'unit', 'cost_price', 'selling_price', 'minimum_selling_price', 'status', 'components'];

    /** @param array<string, mixed> $before
     * @param  array<string, mixed>  $after
     */
    public function publication(Product $product, array $before, array $after): void
    {
        $changes = $this->diff($before, $after, ['status', 'slug', 'public_title', 'public_description', 'seo_title', 'seo_description']);
        if ($changes !== []) {
            $this->record($product, $product->id, $product->name, 'publication', 'updated', $changes);
        }
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function product(Product $product, string $action, array $before, array $after): void
    {
        $this->record($product, $product->id, $product->name, 'product', $action, $this->diff($before, $after, self::PRODUCT_FIELDS));
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function variant(ProductVariant $variant, string $action, array $before, array $after): void
    {
        $product = $variant->product;
        $this->record($product, $variant->id, $product->name, 'variant', $action, $this->diff($before, $after, self::VARIANT_FIELDS));
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  list<string>  $fields
     * @return array<string, array{before: mixed, after: mixed}>
     */
    private function diff(array $before, array $after, array $fields): array
    {
        $changes = [];
        foreach ($fields as $field) {
            $old = $this->normalize($before[$field] ?? null);
            $new = $this->normalize($after[$field] ?? null);
            if ($old !== $new) {
                $changes[$field] = ['before' => $old, 'after' => $new];
            }
        }

        return $changes;
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if (is_array($value)) {
            ksort($value);

            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }
        if (is_string($value) && (str_starts_with($value, '{') || str_starts_with($value, '['))) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $this->normalize($decoded);
            }
        }

        return $value;
    }

    /** @param array<string, array{before: mixed, after: mixed}> $changes */
    private function record(Product $product, int $subjectId, string $productName, string $subjectType, string $action, array $changes): void
    {
        $request = request();
        $actor = Auth::user();
        $locationId = $actor === null ? null : app(LocationAccessService::class)->selectedLocationId($request);

        ProductActivityLog::query()->create([
            'business_id' => $product->business_id,
            'location_id' => $locationId,
            'actor_id' => $actor === null ? null : $actor->id,
            'actor_name' => $actor === null ? __('System') : $actor->name,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'product_id' => $product->id,
            'product_name' => $productName,
            'changes' => $changes,
            'created_at' => now(),
        ]);
    }
}
