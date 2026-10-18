<?php

namespace App\Actions\Catalog;

use App\CatalogStatus;
use App\Models\BundleComponent;
use App\Models\Business;
use App\Models\InventoryOperationLine;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturnLine;
use App\Models\SaleLine;
use App\Models\SaleLineComponent;
use App\Models\SerializedDevice;
use App\Models\StockMovement;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteProduct
{
    /**
     * Permanently delete an unused, inactive product and its catalogue-only data.
     */
    public function handle(Business $business, Product $product): ?string
    {
        try {
            return DB::transaction(function () use ($business, $product): ?string {
                $managedProduct = $business->products()
                    ->lockForUpdate()
                    ->findOrFail($product->id);

                if ($managedProduct->status !== CatalogStatus::Inactive) {
                    throw ValidationException::withMessages([
                        'product' => __('Deactivate the product before permanently deleting it.'),
                    ]);
                }

                $variantIds = $managedProduct->variants()
                    ->lockForUpdate()
                    ->pluck('id')
                    ->map(fn (int|string $id): int => (int) $id)
                    ->values()
                    ->all();

                if ($this->hasTransactionHistory($variantIds)) {
                    throw ValidationException::withMessages([
                        'product' => __('This product cannot be deleted because it has transaction or inventory history. Keep it inactive instead.'),
                    ]);
                }

                if (BundleComponent::query()
                    ->whereIn('component_variant_id', $variantIds)
                    ->whereNotIn('bundle_variant_id', $variantIds)
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'product' => __('This product cannot be deleted because another bundle uses one of its variants.'),
                    ]);
                }

                BundleComponent::query()->whereIn('bundle_variant_id', $variantIds)->delete();
                $managedProduct->variants()->delete();

                $imagePath = $managedProduct->image_path;
                $managedProduct->delete();

                return $imagePath;
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'product' => __('This product is still referenced by other records and cannot be deleted. Keep it inactive instead.'),
            ]);
        }
    }

    /** @param array<int, int> $variantIds */
    private function hasTransactionHistory(array $variantIds): bool
    {
        return PurchaseLine::query()->whereIn('product_variant_id', $variantIds)->exists()
            || PurchaseReturnLine::query()->whereIn('product_variant_id', $variantIds)->exists()
            || SaleLine::query()->whereIn('product_variant_id', $variantIds)->exists()
            || SaleLineComponent::query()->whereIn('product_variant_id', $variantIds)->exists()
            || InventoryOperationLine::query()->whereIn('product_variant_id', $variantIds)->exists()
            || StockMovement::query()->whereIn('product_variant_id', $variantIds)->exists()
            || InventoryStock::query()->whereIn('product_variant_id', $variantIds)->exists()
            || SerializedDevice::query()->whereIn('product_variant_id', $variantIds)->exists();
    }
}
