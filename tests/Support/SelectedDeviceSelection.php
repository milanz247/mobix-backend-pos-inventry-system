<?php

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SerializedDevice;
use Illuminate\Support\Facades\Crypt;

/** Test-only driver: select a known physical fixture through the real public selection API. */
function selectFixtureDevice($test, Product $product, ProductVariant $variant, SerializedDevice $device): string
{
    $options = $test->getJson('/api/storefront/v1/products/'.$product->webListing->slug.'/device-options?variant='.$variant->id)
        ->assertOk()->json('data');
    foreach ($options as $option) {
        $private = json_decode(Crypt::decryptString($option['option_key']), true, 512, JSON_THROW_ON_ERROR);
        if ($private['device'] === $device->id) {
            return $test->postJson('/api/storefront/v1/device-selections', ['option_key' => $option['option_key']])
                ->assertCreated()->json('data.token');
        }
    }
    throw new RuntimeException('The requested physical fixture is not publicly eligible.');
}
