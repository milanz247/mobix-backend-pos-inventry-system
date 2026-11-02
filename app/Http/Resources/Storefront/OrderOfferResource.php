<?php

namespace App\Http\Resources\Storefront;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderOfferResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $offer = $this->resource;
        if (! is_array($offer)) {
            throw new \LogicException('OrderOfferResource requires public offer data.');
        }

        return [
            'reference' => $offer['reference'], 'expires_at' => $offer['expires_at'],
            'currency' => $offer['currency'], 'fulfillment_mode' => $offer['fulfillment_mode'],
            'delivery_area' => $offer['delivery_area'], 'pickup_location' => $offer['pickup_location'],
            'items' => array_map(fn (array $item): array => [
                'slug' => $item['slug'], 'name' => $item['name'], 'variant_name' => $item['variant_name'],
                'condition' => $item['condition'], 'included_device_conditions' => $item['included_device_conditions'], 'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'], 'line_total' => $item['line_total'],
            ], $offer['items']),
            'subtotal' => $offer['subtotal'], 'discount_total' => $offer['discount_total'],
            'delivery_fee' => $offer['delivery_fee'], 'total' => $offer['total'],
            'payment_method' => $offer['payment_method'], 'terms_digest' => $offer['terms_digest'],
            'total_is_final' => true, 'delivery_included' => true, 'inventory_reserved' => false,
            'requires_customer_acceptance' => true,
        ];
    }
}
