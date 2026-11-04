<?php

use App\Exceptions\StorefrontCartException;
use App\Http\Resources\Storefront\OrderOfferResource;
use App\Http\Resources\Storefront\QuoteResource;
use App\Models\Business;
use App\Models\ProductVariant;
use App\Models\SerializedDevice;
use App\Models\WebCartItem;
use App\Models\WebOrderOffer;
use App\Models\WebOrderOfferItem;
use App\Models\WebQuoteItem;
use App\Services\StorefrontCatalogService;
use App\Services\StorefrontOfferTermsService;
use App\Services\StorefrontSelectedDeviceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Tests\TestCase;

pest()->extend(TestCase::class);

function continuityUnitModels(): array
{
    return [
        (new Business)->forceFill(['id' => 1]),
        (new ProductVariant)->forceFill(['id' => 2, 'business_id' => 1, 'product_id' => 3, 'condition' => 'used', 'tracking_mode' => 'serialized']),
        new WebCartItem(['business_id' => 1, 'product_id' => 3, 'product_variant_id' => 2,
            'public_key' => '01K9P07N36QAX2D7P3VX3BZHTA', 'serialized_device_id' => 4,
            'selected_unit_price' => '1200.00', 'quantity' => 1]),
    ];
}

test('missing or incompatible selection never queries or falls back to catalog prices', function (string $change) {
    [$business, $variant, $item] = continuityUnitModels();
    match ($change) {
        'missing_device' => $item->serialized_device_id = null,
        'missing_price' => $item->selected_unit_price = null,
        'business' => $item->business_id = 9,
        'product' => $item->product_id = 9,
        'variant' => $item->product_variant_id = 9,
        'quantity' => $item->quantity = 2,
        'condition' => $variant->condition = 'new',
    };
    $catalog = Mockery::mock(StorefrontCatalogService::class);
    $catalog->shouldNotReceive('startingPrice', 'eligibleUsedDevices');
    expect(fn () => (new StorefrontSelectedDeviceService($catalog))->resolve($business, $variant, $item))
        ->toThrow(StorefrontCartException::class);
})->with(['missing_device', 'missing_price', 'business', 'product', 'variant', 'quantity', 'condition']);

test('exact resolution rejects a changed effective price or missing eligible device', function (bool $available, string $price) {
    [$business, $variant, $item] = continuityUnitModels();
    $device = $available ? (new SerializedDevice)->forceFill(['id' => 4]) : null;
    $query = Mockery::mock(Builder::class);
    $query->shouldReceive('whereKey')->once()->with(4)->andReturnSelf();
    $query->shouldReceive('first')->once()->andReturn($device);
    $catalog = Mockery::mock(StorefrontCatalogService::class);
    $catalog->shouldReceive('eligibleUsedDevices')->once()->with($business, $variant)->andReturn($query);
    $catalog->shouldNotReceive('startingPrice');
    if ($available) {
        $catalog->shouldReceive('usedDevicePrice')->once()->with($device, $variant)->andReturn($price);
    }
    $service = new StorefrontSelectedDeviceService($catalog);
    if ($available && $price === '1200.00') {
        expect($service->resolve($business, $variant, $item))->toBe($device);
    } else {
        expect(fn () => $service->resolve($business, $variant, $item))->toThrow(StorefrontCartException::class);
    }
})->with([[true, '1200.00'], [true, '700.00'], [false, '1200.00']]);

test('quote matching uses cart keys and exact identity rather than variant alone', function () {
    [$business, $variant, $first] = continuityUnitModels();
    $second = clone $first;
    $second->public_key = '01K9P07N36QAX2D7P3VX3BZHTB';
    $second->serialized_device_id = 5;
    $second->selected_unit_price = '700.00';
    $quote = fn (WebCartItem $item) => new WebQuoteItem([
        'business_id' => 1, 'product_id' => 3, 'product_variant_id' => 2,
        'source_cart_item_key' => $item->public_key, 'serialized_device_id' => $item->serialized_device_id,
        'selected_location_id' => 6, 'selected_condition' => 'used', 'quantity' => 1,
        'unit_price' => $item->selected_unit_price, 'line_total' => $item->selected_unit_price,
    ]);
    $a = $quote($first);
    $b = $quote($second);
    $service = new StorefrontSelectedDeviceService(Mockery::mock(StorefrontCatalogService::class));
    expect($service->quoteLine(collect([$b, $a]), $first, $variant))->toBe($a)
        ->and($service->quoteLine(collect([$a, $b]), $second, $variant))->toBe($b);
    foreach (['serialized_device_id' => 5, 'source_cart_item_key' => null, 'selected_condition' => 'new',
        'selected_location_id' => null, 'unit_price' => '700.00'] as $field => $value) {
        $changed = clone $a;
        $changed->setAttribute($field, $value);
        expect(fn () => $service->quoteLine(collect([$changed]), $first, $variant))->toThrow(StorefrontCartException::class);
    }
});

test('ordinary legacy quotes retain unambiguous variant matching', function () {
    [$business, $variant, $item] = continuityUnitModels();
    $variant->condition = 'new';
    $item->serialized_device_id = null;
    $item->selected_unit_price = null;
    $line = new WebQuoteItem(['business_id' => 1, 'product_id' => 3, 'product_variant_id' => 2,
        'quantity' => 1, 'unit_price' => '1200.00', 'line_total' => '1200.00', 'requires_device_confirmation' => false]);
    $service = new StorefrontSelectedDeviceService(Mockery::mock(StorefrontCatalogService::class));
    expect($service->quoteLine(collect([$line]), $item, $variant))->toBe($line);
    expect(fn () => $service->quoteLine(collect([$line, clone $line]), $item, $variant))->toThrow(StorefrontCartException::class);
});

test('offer digest covers device source condition allocations price location and commercial terms', function () {
    $offer = new WebOrderOffer(['business_id' => 1, 'reference' => 'public-reference', 'web_cart_id' => 2,
        'web_quote_id' => 3, 'location_id' => 6, 'currency' => 'LKR', 'total' => '1200.00',
        'expires_at' => '2026-11-04 12:00:00', 'contact_snapshot' => ['name' => 'Customer']]);
    $line = new WebOrderOfferItem(['business_id' => 1, 'source_cart_item_key' => 'opaque-key', 'line_number' => 1,
        'serialized_device_id' => 4, 'condition_snapshot' => 'used', 'quantity' => 1,
        'unit_price' => '1200.00', 'line_total' => '1200.00',
        'private_allocation' => [['variant_id' => 2, 'quantity' => '1', 'device_ids' => [4]]]]);
    $service = new StorefrontOfferTermsService;
    $digest = $service->digest($offer, collect([$line]));
    foreach (['serialized_device_id' => 5, 'source_cart_item_key' => 'other-key', 'condition_snapshot' => 'new',
        'unit_price' => '700.00', 'private_allocation' => [['variant_id' => 2, 'quantity' => '1', 'device_ids' => [5]]]] as $field => $value) {
        $changed = clone $line;
        $changed->setAttribute($field, $value);
        expect($service->digest($offer, collect([$changed])))->not->toBe($digest);
    }
    $offer->location_id = 9;
    expect($service->digest($offer, collect([$line])))->not->toBe($digest);
});

test('public resources and model serialization exclude private quote and offer allocations', function () {
    $private = ['serialized_device_id' => 987654, 'selected_location_id' => 654321,
        'source_cart_item_key' => 'PRIVATE-SOURCE', 'private_allocation' => ['imei' => 'PRIVATE-IMEI']];
    $line = ['slug' => 'phone', 'name' => 'Phone', 'variant_name' => '128 GB', 'quantity' => 1,
        'unit_price' => '1200.00', 'line_total' => '1200.00', 'availability' => 'in_stock',
        'requires_device_confirmation' => true, 'condition' => 'used', 'included_device_conditions' => null] + $private;
    $common = ['reference' => 'public', 'status' => 'indicative', 'created_at' => 'now', 'expires_at' => 'later',
        'currency' => 'LKR', 'subtotal' => '1200.00', 'total' => '1200.00', 'discount_total' => '0.00', 'items' => [$line],
        'fulfillment_mode' => 'shop_pickup', 'delivery_area' => null, 'pickup_location' => null,
        'delivery_fee' => '0.00', 'payment_method' => 'cod', 'terms_digest' => str_repeat('a', 64)];
    foreach ([new QuoteResource($common), new OrderOfferResource($common)] as $resource) {
        $json = json_encode($resource->toArray(new Request), JSON_THROW_ON_ERROR);
        expect($json)->not->toContain('PRIVATE-', 'serialized_device_id', 'selected_location_id', 'source_cart_item_key', 'private_allocation');
    }
    expect((new WebQuoteItem($private))->toJson())->not->toContain('PRIVATE-', 'serialized_device_id', 'selected_location_id', 'source_cart_item_key');
});
