<?php

use App\Http\Controllers\Storefront\BankTransferProofController;
use App\Http\Controllers\Storefront\CartController;
use App\Http\Controllers\Storefront\CatalogController;
use App\Http\Controllers\Storefront\HomeBannerController;
use App\Http\Controllers\Storefront\OrderOfferController;
use App\Http\Controllers\Storefront\OrderSubmissionController;
use App\Http\Controllers\Storefront\OrderTrackingController;
use App\Http\Controllers\Storefront\PickupLocationController;
use App\Http\Controllers\Storefront\SiteAnnouncementController;
use App\Http\Middleware\NoStoreStorefrontCart;
use Illuminate\Support\Facades\Route;

Route::prefix('storefront/v1')->middleware('throttle:storefront-catalog')->group(function (): void {
    Route::get('categories', [CatalogController::class, 'categories']);
    Route::get('brands', [CatalogController::class, 'brands']);
    Route::get('products', [CatalogController::class, 'products']);
    Route::get('products/{slug}', [CatalogController::class, 'product']);
    Route::get('products/{slug}/availability', [CatalogController::class, 'availability']);
    Route::get('products/{slug}/device-options', [CatalogController::class, 'deviceOptions']);
    Route::get('delivery-areas', [OrderOfferController::class, 'deliveryAreas']);
    Route::get('pickup-locations', [PickupLocationController::class, 'index']);
    Route::get('home-banner', [HomeBannerController::class, 'show']);
    Route::get('site-announcement', [SiteAnnouncementController::class, 'show']);
    Route::get('shopping-status', [SiteAnnouncementController::class, 'shoppingStatus']);
    Route::get('contact', [SiteAnnouncementController::class, 'contact']);
});

Route::prefix('storefront/v1')->middleware(['throttle:storefront-cart', NoStoreStorefrontCart::class])->group(function (): void {
    Route::post('device-selections', [CatalogController::class, 'createDeviceSelection']);
});

Route::prefix('storefront/v1')->middleware(['throttle:storefront-cart', NoStoreStorefrontCart::class])->group(function (): void {
    Route::get('cart', [CartController::class, 'show']);
    Route::post('cart/items', [CartController::class, 'add']);
    Route::patch('cart/items/{item}', [CartController::class, 'update']);
    Route::delete('cart/items/{item}', [CartController::class, 'remove']);
    Route::delete('cart', [CartController::class, 'clear']);
    Route::post('quotes', [CartController::class, 'quote']);
    Route::post('order-offers', [OrderOfferController::class, 'store'])->middleware('throttle:storefront-offers');
    Route::post('orders', [OrderSubmissionController::class, 'store'])->middleware('throttle:storefront-orders');
});

Route::prefix('storefront/v1')->middleware(['throttle:storefront-order-tracking', NoStoreStorefrontCart::class])->group(function (): void {
    Route::get('orders/{reference}', [OrderTrackingController::class, 'show']);
    Route::post('orders/{reference}/bank-transfer-proof', [BankTransferProofController::class, 'store'])
        ->middleware('throttle:storefront-bank-proof');
});
