<?php

namespace App\Actions\Catalog;

use App\Models\Business;
use App\Models\Product;
use App\Models\ProductVariant;
use App\ProductType;
use App\Services\ProductActivityLogger;
use App\VariantTrackingMode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SaveProduct
{
    /**
     * Create or update a product, its variants, and its virtual bundle definition atomically.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Business $business, array $attributes, ?Product $product = null, ?UploadedFile $image = null, ?ProductActivityLogger $activity = null): Product
    {
        $activity ??= app(ProductActivityLogger::class);
        $variantRows = $attributes['variants'];
        unset($attributes['variants'], $attributes['image']);

        $newImagePath = $image === null ? null : $image->store('product-images', 'public');

        if ($image !== null && $newImagePath === false) {
            throw new RuntimeException(__('The product image could not be stored.'));
        }

        $previousImagePath = $product?->image_path;

        if ($newImagePath !== null) {
            $attributes['image_path'] = $newImagePath;
        }

        try {
            $product = DB::transaction(function () use ($business, $attributes, $product, $variantRows, $activity): Product {
                $productBefore = $product?->getAttributes() ?? [];
                $isNewProduct = $product === null;
                if ($product === null) {
                    $product = $business->products()->create($attributes);
                } else {
                    $product->update($attributes);
                }
                $activity->product($product, $isNewProduct ? 'created' : 'updated', $productBefore, $product->getAttributes());

                $retainedVariantIds = [];

                foreach ($variantRows as $variantRow) {
                    $variantId = isset($variantRow['id']) && $variantRow['id'] !== ''
                        ? (int) $variantRow['id']
                        : null;
                    $variantBefore = [];
                    $componentsBefore = [];
                    if ($variantId !== null) {
                        $existingVariant = $product->variants()->whereKey($variantId)->firstOrFail();
                        $variantBefore = $existingVariant->getAttributes();
                        $componentsBefore = $existingVariant->bundleComponents()->orderBy('component_variant_id')->get(['component_variant_id', 'quantity'])->map(fn ($component): array => ['component_variant_id' => $component->component_variant_id, 'quantity' => $component->quantity])->all();
                    }
                    $variantAttributes = Arr::only($variantRow, [
                        'name', 'barcode', 'model_number', 'storage_capacity', 'color',
                        'variant_attributes', 'condition', 'tracking_mode', 'unit', 'cost_price',
                        'selling_price', 'minimum_selling_price', 'status',
                    ]);

                    if (! empty($variantRow['other_attributes'])) {
                        $variantAttributes['variant_attributes'] = array_merge(
                            $variantAttributes['variant_attributes'] ?? [],
                            ['details' => trim($variantRow['other_attributes'])],
                        );
                    }

                    if ($variantAttributes['tracking_mode'] !== VariantTrackingMode::Serialized->value) {
                        $variantAttributes['condition'] = null;
                    }

                    if ($variantId === null) {
                        $variantAttributes['sku'] = $this->generateSku($business);
                        $variant = $product->variants()->make($variantAttributes);
                        $variant->forceFill(['business_id' => $business->id]);
                        $variant->save();
                    } else {
                        $variant = $existingVariant;
                        $variant->update($variantAttributes);
                    }

                    $retainedVariantIds[] = $variant->id;

                    if ($product->product_type === ProductType::Combo) {
                        $this->saveComponents($business, $variant, $variantRow['components'] ?? []);
                    } else {
                        DB::table('bundle_components')->where('bundle_variant_id', $variant->id)->delete();
                    }
                    $componentsAfter = $variant->bundleComponents()->orderBy('component_variant_id')->get(['component_variant_id', 'quantity'])->map(fn ($component): array => ['component_variant_id' => $component->component_variant_id, 'quantity' => $component->quantity])->all();
                    $variantAfter = $variant->getAttributes();
                    if ($componentsBefore !== $componentsAfter) {
                        $variantBefore['components'] = $componentsBefore;
                        $variantAfter['components'] = $componentsAfter;
                    }
                    $activity->variant($variant, $variantId === null ? 'created' : 'updated', $variantBefore, $variantAfter);
                }

                $removedVariants = $product->variants()->whereNotIn('id', $retainedVariantIds)->get();
                foreach ($removedVariants as $removedVariant) {
                    $before = $removedVariant->getAttributes();
                    $removedVariant->update(['status' => 'inactive']);
                    $activity->variant($removedVariant, 'updated', $before, $removedVariant->getAttributes());
                }

                $product->refresh()->load('variants.bundleComponents');

                return $product;
            }, attempts: 3);
        } catch (Throwable $exception) {
            if (is_string($newImagePath)) {
                Storage::disk('public')->delete($newImagePath);
            }

            throw $exception;
        }

        if (is_string($newImagePath)
            && $previousImagePath !== null
            && ! Product::query()->where('image_path', $previousImagePath)->exists()) {
            Storage::disk('public')->delete($previousImagePath);
        }

        return $product;
    }

    private function generateSku(Business $business): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $sku = 'NM-'.Str::upper(Str::random(12));

            if (! $business->productVariants()->where('sku', $sku)->exists()) {
                return $sku;
            }
        }

        throw new RuntimeException(__('A unique SKU could not be generated. Please try again.'));
    }

    /** @param list<array{component_variant_id: int|string, quantity: int|float|string}> $components */
    private function saveComponents(Business $business, ProductVariant $bundleVariant, array $components): void
    {
        DB::table('bundle_components')->where('bundle_variant_id', $bundleVariant->id)->delete();

        foreach ($components as $component) {
            DB::table('bundle_components')->insert([
                'business_id' => $business->id,
                'bundle_variant_id' => $bundleVariant->id,
                'component_variant_id' => (int) $component['component_variant_id'],
                'quantity' => $component['quantity'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
