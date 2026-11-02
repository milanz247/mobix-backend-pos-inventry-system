<?php

namespace App\Http\Resources\Storefront;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $cart = $this->resource;
        if (! is_array($cart)) {
            throw new \LogicException('CartResource requires cart data.');
        }

        return [
            'items' => array_map(fn (array $item): array => [
                'key' => $item['key'], 'name' => $item['name'], 'slug' => $item['slug'],
                'variant' => $item['variant'] === null ? null : [
                    'id' => $item['variant']['id'], 'name' => $item['variant']['name'],
                    'condition' => $item['variant']['condition'],
                ], 'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'], 'line_total' => $item['line_total'],
                'image_url' => $item['image_url'], 'availability' => $item['availability'],
                'requires_device_confirmation' => $item['requires_device_confirmation'],
            ], $cart['items']),
            'item_count' => $cart['item_count'], 'currency' => $cart['currency'],
            'indicative_subtotal' => $cart['indicative_subtotal'], 'can_quote' => $cart['can_quote'],
            'availability_is_advisory' => true, 'cart_expires_at' => $cart['cart_expires_at'],
        ];
    }
}
