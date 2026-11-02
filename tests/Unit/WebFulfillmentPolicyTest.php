<?php

use App\Exceptions\StorefrontCartException;
use App\Services\WebFulfillmentPolicy;
use App\WebFulfillmentMode;

test('only approved first-release payment and fulfillment combinations pass', function () {
    $policy = new WebFulfillmentPolicy;
    expect($policy->assertAllowed('bank_transfer', 'home_delivery'))->toBe(WebFulfillmentMode::HomeDelivery)
        ->and($policy->assertAllowed('cod', 'shop_pickup'))->toBe(WebFulfillmentMode::ShopPickup);
    foreach ([['cod', 'home_delivery'], ['bank_transfer', 'shop_pickup'], ['cod', 'courier_cod'], ['cod', null]] as [$method, $mode]) {
        expect(fn () => $policy->assertAllowed($method, $mode))->toThrow(StorefrontCartException::class);
    }
});
