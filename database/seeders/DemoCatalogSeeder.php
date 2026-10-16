<?php

namespace Database\Seeders;

use App\CatalogStatus;
use App\Models\Brand;
use App\Models\Business;
use App\Models\Category;
use App\Models\ProductVariant;
use App\ProductCondition;
use App\ProductType;
use App\VariantTrackingMode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoCatalogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call(CatalogDefaultsSeeder::class);

        Business::query()
            ->orderBy('id')
            ->chunkById(100, function ($businesses): void {
                foreach ($businesses as $business) {
                    DB::transaction(fn () => $this->seedBusiness($business));
                }
            });
    }

    private function seedBusiness(Business $business): void
    {
        $categories = $business->categories()->whereIn('name', [
            'Mobile Phones', 'Accessories', 'Spare Parts', 'Other',
        ])->get()->keyBy('name');
        $brands = $business->brands()->whereIn('name', [
            'Apple', 'Samsung', 'Xiaomi', 'Oppo', 'Vivo', 'Other',
        ])->get()->keyBy('name');

        $mobilePhones = $categories->get('Mobile Phones');
        $accessories = $categories->get('Accessories');
        $spareParts = $categories->get('Spare Parts');
        $other = $categories->get('Other');

        if (! $mobilePhones instanceof Category
            || ! $accessories instanceof Category
            || ! $spareParts instanceof Category
            || ! $other instanceof Category) {
            return;
        }

        $apple = $brands->get('Apple');
        $samsung = $brands->get('Samsung');
        $xiaomi = $brands->get('Xiaomi');
        $otherBrand = $brands->get('Other');

        $this->createProduct($business, $accessories, $samsung instanceof Brand ? $samsung : null, [
            'name' => 'Samsung 25W USB-C Fast Charger',
            'product_type' => ProductType::Simple,
            'description' => 'Compact USB-C fast charger suitable for supported Samsung devices.',
            'variants' => [
                $this->quantityVariant('Default', 'DEMO-ACC-CHG-25W', '8806090973352', '3200.00', '5200.00', '4800.00'),
            ],
        ]);

        $this->createProduct($business, $accessories, $otherBrand instanceof Brand ? $otherBrand : null, [
            'name' => 'USB-C to USB-C Cable 1m',
            'product_type' => ProductType::Simple,
            'description' => 'One-metre USB-C charging and data cable.',
            'variants' => [
                $this->quantityVariant('White', 'DEMO-ACC-CABLE-C2C', '4799999000011', '900.00', '1600.00', '1400.00', color: 'White'),
            ],
        ]);

        $this->createProduct($business, $accessories, $otherBrand instanceof Brand ? $otherBrand : null, [
            'name' => 'Universal Clear Phone Case',
            'product_type' => ProductType::Simple,
            'description' => 'Transparent protective case used by the starter bundle demo.',
            'variants' => [
                $this->quantityVariant('Clear', 'DEMO-ACC-CASE-CLEAR', '4799999000028', '1200.00', '2200.00', '1900.00', color: 'Clear'),
            ],
        ]);

        $this->createProduct($business, $accessories, $otherBrand instanceof Brand ? $otherBrand : null, [
            'name' => 'Tempered Glass Screen Protector',
            'product_type' => ProductType::Simple,
            'description' => 'Standard tempered-glass screen protector.',
            'variants' => [
                $this->quantityVariant('Standard', 'DEMO-ACC-GLASS-STD', '4799999000035', '700.00', '1500.00', '1200.00'),
            ],
        ]);

        $this->createProduct($business, $spareParts, $apple instanceof Brand ? $apple : null, [
            'name' => 'iPhone 13 OLED Display Assembly',
            'product_type' => ProductType::Simple,
            'description' => 'Replacement OLED display assembly for iPhone 13 repair jobs.',
            'variants' => [
                $this->quantityVariant('Standard', 'DEMO-SP-IPH13-OLED', null, '24500.00', '34500.00', '32000.00', model: 'iPhone 13'),
            ],
        ]);

        $this->createProduct($business, $spareParts, $samsung instanceof Brand ? $samsung : null, [
            'name' => 'Samsung A-Series Replacement Battery',
            'product_type' => ProductType::Simple,
            'description' => 'Replacement battery for supported Samsung Galaxy A-series models.',
            'variants' => [
                $this->quantityVariant('A54 5G', 'DEMO-SP-A54-BATTERY', null, '5500.00', '8500.00', '7800.00', model: 'Galaxy A54 5G'),
            ],
        ]);

        $this->createProduct($business, $mobilePhones, $samsung instanceof Brand ? $samsung : null, [
            'name' => 'Samsung Galaxy S24',
            'product_type' => ProductType::Variable,
            'description' => 'New Galaxy S24 model with storage and colour variants.',
            'variants' => [
                $this->serializedVariant('128 GB · Onyx Black', 'DEMO-PHN-S24-128-BLK', '8806095299693', '195000.00', '229000.00', '219000.00', 'SM-S921B', '128 GB', 'Onyx Black', ProductCondition::New),
                $this->serializedVariant('256 GB · Marble Gray', 'DEMO-PHN-S24-256-GRY', '8806095299709', '218000.00', '252000.00', '240000.00', 'SM-S921B', '256 GB', 'Marble Gray', ProductCondition::New),
            ],
        ]);

        $this->createProduct($business, $mobilePhones, $xiaomi instanceof Brand ? $xiaomi : null, [
            'name' => 'Xiaomi Redmi Note 14 Pro',
            'product_type' => ProductType::Variable,
            'description' => 'Redmi Note 14 Pro with multiple sellable configurations.',
            'variants' => [
                $this->serializedVariant('256 GB · Midnight Black', 'DEMO-PHN-RN14P-256-BLK', '6941812790014', '93000.00', '112000.00', '105000.00', '24090RA29G', '256 GB', 'Midnight Black', ProductCondition::New),
                $this->serializedVariant('512 GB · Lavender Purple', 'DEMO-PHN-RN14P-512-PUR', '6941812790021', '107000.00', '128000.00', '120000.00', '24090RA29G', '512 GB', 'Lavender Purple', ProductCondition::New),
            ],
        ]);

        $this->createProduct($business, $mobilePhones, $apple instanceof Brand ? $apple : null, [
            'name' => 'Pre-Owned iPhone 13',
            'product_type' => ProductType::Serialized,
            'description' => 'Serialized catalogue model for used and refurbished iPhone 13 devices. IMEIs are added during stock receiving.',
            'variants' => [
                $this->serializedVariant('128 GB · Used · Midnight', 'DEMO-PHN-IP13-128-USED', null, '112000.00', '139000.00', '130000.00', 'A2633', '128 GB', 'Midnight', ProductCondition::Used),
                $this->serializedVariant('128 GB · Refurbished · Blue', 'DEMO-PHN-IP13-128-REF', null, '125000.00', '152000.00', '142000.00', 'A2633', '128 GB', 'Blue', ProductCondition::Refurbished),
            ],
        ]);

        $this->createProduct($business, $accessories, $otherBrand instanceof Brand ? $otherBrand : null, [
            'name' => '10,000 mAh Fast-Charge Power Bank',
            'product_type' => ProductType::Simple,
            'description' => 'Portable power bank with USB-C charging and battery protection.',
            'variants' => [
                $this->quantityVariant('Black', 'DEMO-ACC-PBANK-10K', '4799999000110', '4800.00', '7500.00', '6900.00', color: 'Black'),
            ],
        ]);

        $this->createProduct($business, $accessories, $otherBrand instanceof Brand ? $otherBrand : null, [
            'name' => '33W USB-C Fast Charger',
            'product_type' => ProductType::Simple,
            'description' => 'Universal 33W USB-C wall charger for compatible phones.',
            'variants' => [
                $this->quantityVariant('White', 'DEMO-ACC-CHG-33W', '4799999000127', '3900.00', '6200.00', '5700.00', color: 'White'),
            ],
        ]);

        $this->createProduct($business, $accessories, $otherBrand instanceof Brand ? $otherBrand : null, [
            'name' => 'Lightning to USB-C Cable 1m',
            'product_type' => ProductType::Simple,
            'description' => 'One-metre charging and data cable for compatible Apple devices.',
            'variants' => [
                $this->quantityVariant('White', 'DEMO-ACC-CABLE-LIGHTNING', '4799999000134', '1250.00', '2400.00', '2100.00', color: 'White'),
            ],
        ]);

        $this->createProduct($business, $accessories, $samsung instanceof Brand ? $samsung : null, [
            'name' => 'Galaxy S24 Silicone Case',
            'product_type' => ProductType::Simple,
            'description' => 'Soft-touch protective case for Samsung Galaxy S24.',
            'variants' => [
                $this->quantityVariant('Navy Blue', 'DEMO-ACC-CASE-S24-NAVY', null, '1800.00', '3500.00', '3100.00', model: 'Galaxy S24', color: 'Navy Blue'),
            ],
        ]);

        $this->createProduct($business, $accessories, $samsung instanceof Brand ? $samsung : null, [
            'name' => 'Galaxy S24 Tempered Glass',
            'product_type' => ProductType::Simple,
            'description' => 'Tempered-glass screen protector sized for Samsung Galaxy S24.',
            'variants' => [
                $this->quantityVariant('Clear', 'DEMO-ACC-GLASS-S24', null, '900.00', '1900.00', '1600.00', model: 'Galaxy S24', color: 'Clear'),
            ],
        ]);

        $this->createProduct($business, $spareParts, $samsung instanceof Brand ? $samsung : null, [
            'name' => 'Galaxy A54 Charging Port Flex',
            'product_type' => ProductType::Simple,
            'description' => 'Replacement USB-C charging port flex assembly for Galaxy A54.',
            'variants' => [
                $this->quantityVariant('Service Part', 'DEMO-SP-A54-CHARGE-FLEX', null, '3200.00', '5900.00', '5200.00', model: 'Galaxy A54 5G'),
            ],
        ]);

        $this->createProduct($business, $spareParts, $apple instanceof Brand ? $apple : null, [
            'name' => 'iPhone 13 Replacement Battery',
            'product_type' => ProductType::Simple,
            'description' => 'Replacement battery for iPhone 13 repair service.',
            'variants' => [
                $this->quantityVariant('Service Part', 'DEMO-SP-IPH13-BATTERY', null, '8500.00', '12900.00', '11800.00', model: 'iPhone 13'),
            ],
        ]);

        $this->createProduct($business, $mobilePhones, $brands->get('Oppo') instanceof Brand ? $brands->get('Oppo') : null, [
            'name' => 'Oppo Reno 12 5G',
            'product_type' => ProductType::Variable,
            'description' => 'Reno 12 5G catalogue model with separate storage and colour variants.',
            'variants' => [
                $this->serializedVariant('256 GB · Astro Silver', 'DEMO-PHN-RENO12-256-SLV', null, '142000.00', '169000.00', '159000.00', 'CPH2625', '256 GB', 'Astro Silver', ProductCondition::New),
                $this->serializedVariant('256 GB · Sunset Pink', 'DEMO-PHN-RENO12-256-PNK', null, '142000.00', '169000.00', '159000.00', 'CPH2625', '256 GB', 'Sunset Pink', ProductCondition::New),
            ],
        ]);

        $this->createProduct($business, $mobilePhones, $samsung instanceof Brand ? $samsung : null, [
            'name' => 'Pre-Owned Samsung Galaxy A15',
            'product_type' => ProductType::Serialized,
            'description' => 'Used Galaxy A15 model; actual units and IMEIs are tracked individually.',
            'variants' => [
                $this->serializedVariant('128 GB · Used · Blue Black', 'DEMO-PHN-A15-128-USED', null, '39000.00', '52500.00', '48000.00', 'SM-A155F', '128 GB', 'Blue Black', ProductCondition::Used),
            ],
        ]);

        $this->createBundle($business, $other, $otherBrand instanceof Brand ? $otherBrand : null);
        $this->createBundle(
            $business,
            $other,
            $otherBrand instanceof Brand ? $otherBrand : null,
            'DEMO-BUNDLE-PROTECTION',
            'Phone Protection Pack',
            'Phone protection bundle containing one universal clear case and one standard tempered-glass protector.',
            ['DEMO-ACC-CASE-CLEAR' => '1.000', 'DEMO-ACC-GLASS-STD' => '1.000'],
            '3200.00',
            '2700.00',
            '4799999000141',
        );
    }

    /**
     * @param  array{name: string, product_type: ProductType, description: string, variants: list<array<string, mixed>>}  $definition
     */
    private function createProduct(Business $business, Category $category, ?Brand $brand, array $definition): void
    {
        $skus = collect($definition['variants'])->pluck('sku')->all();

        if ($business->productVariants()->whereIn('sku', $skus)->exists()
            || $business->products()->where('name', $definition['name'])->exists()) {
            return;
        }

        $product = $business->products()->create([
            'category_id' => $category->id,
            'brand_id' => $brand?->id,
            'name' => $definition['name'],
            'product_type' => $definition['product_type'],
            'description' => $definition['description'],
            'status' => CatalogStatus::Active,
        ]);

        foreach ($definition['variants'] as $attributes) {
            $variant = new ProductVariant($attributes);
            $variant->business()->associate($business);
            $variant->product()->associate($product);
            $variant->save();
        }
    }

    /** @param array<string, string> $componentSkus */
    private function createBundle(
        Business $business,
        Category $category,
        ?Brand $brand,
        string $bundleSku = 'DEMO-BUNDLE-STARTER',
        string $name = 'Mobile Starter Pack',
        string $description = 'Virtual bundle containing one charger, cable, clear case, and tempered-glass protector.',
        array $componentSkus = [
            'DEMO-ACC-CHG-25W' => '1.000',
            'DEMO-ACC-CABLE-C2C' => '1.000',
            'DEMO-ACC-CASE-CLEAR' => '1.000',
            'DEMO-ACC-GLASS-STD' => '1.000',
        ],
        string $sellingPrice = '8500.00',
        string $minimumSellingPrice = '7500.00',
        ?string $barcode = '4799999000103',
    ): void {

        if ($business->productVariants()->where('sku', $bundleSku)->exists()
            || $business->products()->where('name', $name)->exists()) {
            return;
        }
        $components = $business->productVariants()
            ->whereIn('sku', array_keys($componentSkus))
            ->get()
            ->keyBy('sku');

        if ($components->count() !== count($componentSkus)) {
            return;
        }

        $product = $business->products()->create([
            'category_id' => $category->id,
            'brand_id' => $brand?->id,
            'name' => $name,
            'product_type' => ProductType::Combo,
            'description' => $description,
            'status' => CatalogStatus::Active,
        ]);
        $bundleVariant = new ProductVariant([
            'name' => 'Standard Pack',
            'sku' => $bundleSku,
            'barcode' => $barcode,
            'tracking_mode' => VariantTrackingMode::QuantityBased,
            'unit' => 'pack',
            'cost_price' => '0.00',
            'selling_price' => $sellingPrice,
            'minimum_selling_price' => $minimumSellingPrice,
            'status' => CatalogStatus::Active,
        ]);
        $bundleVariant->business()->associate($business);
        $bundleVariant->product()->associate($product);
        $bundleVariant->save();

        foreach ($componentSkus as $sku => $quantity) {
            $component = $components->get($sku);

            if (! $component instanceof ProductVariant) {
                continue;
            }

            $bundleVariant->bundleComponents()->create([
                'business_id' => $business->id,
                'component_variant_id' => $component->id,
                'quantity' => $quantity,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function quantityVariant(
        string $name,
        string $sku,
        ?string $barcode,
        string $costPrice,
        string $sellingPrice,
        string $minimumSellingPrice,
        ?string $model = null,
        ?string $color = null,
    ): array {
        return [
            'name' => $name,
            'sku' => $sku,
            'barcode' => $barcode,
            'model_number' => $model,
            'color' => $color,
            'tracking_mode' => VariantTrackingMode::QuantityBased,
            'unit' => 'piece',
            'cost_price' => $costPrice,
            'selling_price' => $sellingPrice,
            'minimum_selling_price' => $minimumSellingPrice,
            'status' => CatalogStatus::Active,
        ];
    }

    /** @return array<string, mixed> */
    private function serializedVariant(
        string $name,
        string $sku,
        ?string $barcode,
        string $costPrice,
        string $sellingPrice,
        string $minimumSellingPrice,
        string $model,
        string $storage,
        string $color,
        ProductCondition $condition,
    ): array {
        return [
            'name' => $name,
            'sku' => $sku,
            'barcode' => $barcode,
            'model_number' => $model,
            'storage_capacity' => $storage,
            'color' => $color,
            'condition' => $condition,
            'tracking_mode' => VariantTrackingMode::Serialized,
            'unit' => 'piece',
            'cost_price' => $costPrice,
            'selling_price' => $sellingPrice,
            'minimum_selling_price' => $minimumSellingPrice,
            'status' => CatalogStatus::Active,
        ];
    }
}
