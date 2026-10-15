<?php

namespace Database\Factories;

use App\CatalogStatus;
use App\Models\Brand;
use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\ProductType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'category_id' => fn (array $attributes) => Category::factory()->state([
                'business_id' => $attributes['business_id'],
            ]),
            'brand_id' => null,
            'name' => fake()->words(3, true),
            'product_type' => ProductType::Simple,
            'description' => fake()->optional()->sentence(),
            'image_path' => null,
            'status' => CatalogStatus::Active,
        ];
    }

    public function variable(): static
    {
        return $this->state(['product_type' => ProductType::Variable]);
    }

    public function serialized(): static
    {
        return $this->state(['product_type' => ProductType::Serialized]);
    }

    public function combo(): static
    {
        return $this->state(['product_type' => ProductType::Combo]);
    }

    public function withBrand(Brand $brand): static
    {
        return $this->state(['brand_id' => $brand->id, 'business_id' => $brand->business_id]);
    }
}
