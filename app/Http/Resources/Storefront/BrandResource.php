<?php

namespace App\Http\Resources\Storefront;

use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class BrandResource extends JsonResource
{
    /** @return array{id: int, name: string, logo_url: string|null, show_on_homepage: bool, homepage_order: int} */
    public function toArray(Request $request): array
    {
        $brand = $this->resource;
        if (! $brand instanceof Brand) {
            throw new \LogicException('BrandResource requires a brand.');
        }

        return [
            'id' => $brand->id,
            'name' => $brand->name,
            'show_on_homepage' => (bool) $brand->show_on_homepage,
            'homepage_order' => (int) $brand->homepage_order,
            'logo_url' => $brand->logo_path === null ? null : Storage::disk('public')->url($brand->logo_path),
        ];
    }
}
