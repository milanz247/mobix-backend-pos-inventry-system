<?php

namespace App\Http\Resources\Storefront;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AvailabilityResource extends JsonResource
{
    /** @return array{slug: string, advisory: true, variants: list<array{variant_id: int, status: string}>} */
    public function toArray(Request $request): array
    {
        $availability = $this->resource;
        if (! is_array($availability)) {
            throw new \LogicException('AvailabilityResource requires availability data.');
        }

        return [
            'slug' => $availability['slug'],
            'advisory' => true,
            'variants' => $availability['variants'],
        ];
    }
}
