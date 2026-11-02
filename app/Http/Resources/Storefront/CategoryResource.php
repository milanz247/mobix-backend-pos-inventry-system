<?php

namespace App\Http\Resources\Storefront;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    /** @return array{id: int, name: string} */
    public function toArray(Request $request): array
    {
        $category = $this->resource;
        if (! $category instanceof Category) {
            throw new \LogicException('CategoryResource requires a category.');
        }

        return ['id' => $category->id, 'name' => $category->name];
    }
}
