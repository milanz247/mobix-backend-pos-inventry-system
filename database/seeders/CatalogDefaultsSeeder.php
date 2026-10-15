<?php

namespace Database\Seeders;

use App\Models\Business;
use Illuminate\Database\Seeder;

class CatalogDefaultsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Business::query()->each(function (Business $business): void {
            foreach ($this->categories() as $category) {
                $business->categories()->firstOrCreate(
                    ['name' => $category['name']],
                    ['description' => $category['description']],
                );
            }

            foreach ($this->brands() as $brand) {
                $business->brands()->firstOrCreate(
                    ['name' => $brand['name']],
                    ['description' => $brand['description']],
                );
            }
        });
    }

    /** @return list<array{name: string, description: string}> */
    private function categories(): array
    {
        return [
            ['name' => 'Mobile Phones', 'description' => 'New, used, and refurbished mobile phones.'],
            ['name' => 'Accessories', 'description' => 'Chargers, cables, cases, screen protectors, and other accessories.'],
            ['name' => 'Spare Parts', 'description' => 'Displays, batteries, charging ports, cameras, and repair components.'],
            ['name' => 'Other', 'description' => 'Bundles and products that do not belong to another category.'],
        ];
    }

    /** @return list<array{name: string, description: string}> */
    private function brands(): array
    {
        return [
            ['name' => 'Apple', 'description' => 'Apple mobile devices and compatible parts.'],
            ['name' => 'Samsung', 'description' => 'Samsung mobile devices, accessories, and parts.'],
            ['name' => 'Xiaomi', 'description' => 'Xiaomi and Redmi mobile devices and accessories.'],
            ['name' => 'Oppo', 'description' => 'Oppo mobile devices and accessories.'],
            ['name' => 'Vivo', 'description' => 'Vivo mobile devices and accessories.'],
            ['name' => 'Other', 'description' => 'Unbranded and other-brand catalogue items.'],
        ];
    }
}
