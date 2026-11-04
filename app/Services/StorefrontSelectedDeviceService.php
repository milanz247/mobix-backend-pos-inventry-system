<?php

namespace App\Services;

use App\Exceptions\StorefrontCartException;
use App\Models\Business;
use App\Models\ProductVariant;
use App\Models\SerializedDevice;
use App\Models\WebCartItem;
use App\Models\WebQuoteItem;
use App\ProductCondition;
use App\VariantTrackingMode;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Internal identity boundary shared by cart, quote, offer and acceptance. */
class StorefrontSelectedDeviceService
{
    public function __construct(private readonly StorefrontCatalogService $catalog) {}

    public function required(ProductVariant $variant): bool
    {
        return $variant->condition === ProductCondition::Used && $variant->tracking_mode === VariantTrackingMode::Serialized;
    }

    public function resolve(Business $business, ProductVariant $variant, WebCartItem $item, bool $lock = false, string $errorCode = 'DEVICE_SELECTION_UNAVAILABLE'): ?SerializedDevice
    {
        if (! $this->required($variant)) {
            if ($item->serialized_device_id !== null || $item->selected_unit_price !== null) {
                throw $this->unavailable($errorCode);
            }

            return null;
        }
        if ($item->business_id !== $business->id || $variant->business_id !== $business->id
            || $item->product_id !== $variant->product_id || $item->product_variant_id !== $variant->id
            || $item->quantity !== 1 || $item->serialized_device_id === null || $item->selected_unit_price === null) {
            throw $this->unavailable($errorCode);
        }
        if ($lock && DB::transactionLevel() < 1) {
            throw new \LogicException('Selected device locks require a transaction.');
        }
        $query = $this->catalog->eligibleUsedDevices($business, $variant)->whereKey($item->serialized_device_id);
        $device = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($device === null || $this->catalog->usedDevicePrice($device, $variant) !== $item->selected_unit_price) {
            throw $this->unavailable($errorCode);
        }

        return $device;
    }

    /** @param Collection<int, WebQuoteItem> $lines */
    public function quoteLine(Collection $lines, WebCartItem $item, ProductVariant $variant, string $errorCode = 'QUOTE_STALE'): WebQuoteItem
    {
        $matches = $lines->filter(fn (WebQuoteItem $line): bool => $line->source_cart_item_key === $item->public_key);
        // Preserve ordinary legacy quotes, which had one line per variant. Never infer used identity.
        if ($matches->isEmpty() && ! $this->required($variant) && $item->serialized_device_id === null) {
            $matches = $lines->filter(fn (WebQuoteItem $line): bool => $line->source_cart_item_key === null
                && $line->product_variant_id === $variant->id && ! $line->requires_device_confirmation);
        }
        $line = $matches->count() === 1 ? $matches->first() : null;
        if ($line === null || $line->business_id !== $item->business_id || $line->product_id !== $item->product_id
            || $line->product_variant_id !== $variant->id || $line->quantity !== $item->quantity
            || $line->serialized_device_id !== $item->serialized_device_id
            || BigDecimal::of($line->unit_price)->multipliedBy($line->quantity)->compareTo($line->line_total) !== 0) {
            throw $this->unavailable($errorCode);
        }
        if ($this->required($variant) && ($item->selected_unit_price === null
            || $line->selected_condition !== ProductCondition::Used->value || $line->selected_location_id === null
            || $line->unit_price !== $item->selected_unit_price)) {
            throw $this->unavailable($errorCode);
        }
        if (! $this->required($variant) && ($line->selected_condition !== null || $line->selected_location_id !== null)) {
            throw $this->unavailable($errorCode);
        }

        return $line;
    }

    private function unavailable(string $errorCode): StorefrontCartException
    {
        return new StorefrontCartException($errorCode, 'A selected option is no longer valid. Remove it from your cart and select an available option again.', 409);
    }
}
