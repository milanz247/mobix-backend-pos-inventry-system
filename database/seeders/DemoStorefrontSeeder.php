<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\WebHomeBanner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;

class DemoStorefrontSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new LogicException('Demo storefront data cannot be seeded in production.');
        }

        $images = [
            'Samsung 25W USB-C Fast Charger' => 'charger-25w',
            'USB-C to USB-C Cable 1m' => 'cable-usbc',
            'Universal Clear Phone Case' => 'clear-case',
            'Tempered Glass Screen Protector' => 'screen-protector',
            'iPhone 13 OLED Display Assembly' => 'iphone-display',
            'Samsung A-Series Replacement Battery' => 'samsung-battery',
            'Samsung Galaxy S24' => 'galaxy-s24',
            'Xiaomi Redmi Note 14 Pro' => 'redmi-note',
            'Pre-Owned iPhone 13' => 'iphone-13',
            '10,000 mAh Fast-Charge Power Bank' => 'powerbank',
            '33W USB-C Fast Charger' => 'charger-33w',
            'Lightning to USB-C Cable 1m' => 'lightning-cable',
            'Galaxy S24 Silicone Case' => 's24-case',
            'Galaxy S24 Tempered Glass' => 's24-glass',
            'Galaxy A54 Charging Port Flex' => 'charging-flex',
            'iPhone 13 Replacement Battery' => 'iphone-battery',
            'Oppo Reno 12 5G' => 'oppo-reno',
            'Pre-Owned Samsung Galaxy A15' => 'galaxy-a15',
            'Mobile Starter Pack' => 'starter-pack',
            'Phone Protection Pack' => 'protection-pack',
            'Wireless Earbuds Pro' => 'wireless-earbuds',
            'Studio Wireless Headphones' => 'headphones',
            'Portable Bluetooth Speaker' => 'bluetooth-speaker',
            'Smart Watch Active' => 'smartwatch',
            'Fitness Band Fit' => 'fitness-band',
            'Tab Air 11 Wi-Fi' => 'tablet',
            'Tablet Keyboard Cover' => 'tablet-keyboard',
            'Dual USB Car Charger' => 'car-charger',
            '15W Wireless Charging Pad' => 'wireless-pad',
            'Foldable Desktop Phone Stand' => 'phone-stand',
        ];

        $business = Business::query()->where('setup_key', 'initial')->sole();
        foreach ($business->products()->whereIn('name', array_keys($images))->get() as $product) {
            $path = $this->installImage($images[$product->name], 'product-images');
            if ($product->image_path === null) {
                $product->update(['image_path' => $path, 'gallery_paths' => [$path]]);
            }
            $product->webListing()->firstOrCreate(['business_id' => $business->id], [
                'slug' => Str::slug($product->name),
                'status' => 'published',
                'public_title' => $product->name,
                'public_description' => $product->description,
                'seo_title' => $product->name.' | Mobix Mobile',
                'seo_description' => Str::limit($product->description ?? $product->name, 300),
            ]);
        }

        foreach ([
            ['hero-mobile', 'Your next upgrade starts here', 'MOBIX MOBILE', 'Explore phones, tablets and everyday tech. Find your favourite colour and configuration.', true],
            ['hero-accessories', 'Small essentials. Big possibilities.', 'ACCESSORIES & MORE', 'Discover audio, charging and protection essentials for your everyday setup.', false],
        ] as [$image, $headline, $eyebrow, $description, $active]) {
            $path = $this->installImage($image, 'storefront-banners');
            $hasActive = WebHomeBanner::query()->where('business_id', $business->id)->where('is_active', true)->exists();
            WebHomeBanner::query()->firstOrCreate(['business_id' => $business->id, 'headline' => $headline], [
                'eyebrow' => $eyebrow,
                'description' => $description,
                'button_text' => 'Shop collection',
                'button_href' => '/shop',
                'image_path' => $path,
                'image_alt' => $description,
                'is_active' => $active && ! $hasActive,
            ]);
        }
    }

    private function installImage(string $name, string $directory): string
    {
        $source = public_path('demo/'.$name.'.webp');
        $path = $directory.'/demo/'.$name.'.webp';
        if (! is_file($source)) {
            throw new LogicException('Missing committed demo asset: '.$source);
        }
        $disk = Storage::disk('public');
        if (! $disk->exists($path)) {
            $contents = file_get_contents($source);
            if ($contents === false || ! $disk->put($path, $contents)) {
                throw new LogicException('Could not install demo asset: '.$path);
            }
        }

        return $path;
    }
}
