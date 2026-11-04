<?php

namespace App\Services;

use App\Models\WebOrderOffer;
use App\Models\WebOrderOfferItem;
use Illuminate\Support\Collection;

/** Recomputable commercial digest, including private allocations; never serialized publicly. */
class StorefrontOfferTermsService
{
    /** @param Collection<int, WebOrderOfferItem> $items */
    public function digest(WebOrderOffer $offer, Collection $items): string
    {
        $terms = [];
        foreach (['terms_digest_version', 'business_id', 'web_cart_id', 'web_quote_id', 'reference', 'principal_hash', 'currency',
            'fulfillment_mode', 'location_id', 'delivery_area_id', 'delivery_area_code_snapshot',
            'delivery_area_name_snapshot', 'pickup_location_code_snapshot', 'pickup_location_name_snapshot',
            'pickup_location_address_snapshot', 'pickup_location_city_snapshot', 'payment_method',
            'subtotal', 'discount_total', 'delivery_fee', 'total', 'contact_snapshot', 'address_snapshot'] as $field) {
            $terms[$field] = $offer->getAttribute($field);
        }
        $terms['expires_at'] = $offer->expires_at->format('Y-m-d H:i:s');
        $terms['items'] = $items->sortBy('line_number')->values()->map(function (WebOrderOfferItem $item): array {
            $line = [];
            foreach (['business_id', 'source_cart_item_key', 'line_number', 'product_id', 'product_variant_id',
                'serialized_device_id', 'private_allocation', 'product_slug', 'product_name', 'variant_name',
                'condition_snapshot', 'included_device_conditions', 'quantity', 'unit_price', 'line_total'] as $field) {
                $line[$field] = $item->getAttribute($field);
            }

            return $line;
        })->all();

        return hash('sha256', json_encode($terms, JSON_THROW_ON_ERROR));
    }
}
