<?php

namespace App\Http\Resources\Storefront;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuoteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $quote = $this->resource;
        if (! is_array($quote)) {
            throw new \LogicException('QuoteResource requires quote data.');
        }

        return [
            'reference' => $quote['reference'], 'status' => $quote['status'],
            'created_at' => $quote['created_at'], 'expires_at' => $quote['expires_at'],
            'currency' => $quote['currency'], 'subtotal' => $quote['subtotal'], 'total' => $quote['total'],
            'discount_total' => $quote['discount_total'], 'delivery_included' => false,
            'total_is_final' => false, 'availability_is_advisory' => true, 'inventory_reserved' => false,
            'items' => array_map(fn (array $item): array => [
                'slug' => $item['slug'], 'name' => $item['name'], 'variant_name' => $item['variant_name'],
                'quantity' => $item['quantity'], 'unit_price' => $item['unit_price'],
                'line_total' => $item['line_total'], 'availability' => $item['availability'],
                'requires_device_confirmation' => $item['requires_device_confirmation'],
            ], $quote['items']),
        ];
    }
}
