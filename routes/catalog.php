<?php

use App\Http\Controllers\Catalog\BrandController;
use App\Http\Controllers\Catalog\CategoryController;
use App\Http\Controllers\Catalog\ProductController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::get('products', [ProductController::class, 'index'])->middleware('can:products.view')->name('products.index');
    Route::get('products/create', [ProductController::class, 'create'])->middleware(['can:products.create', 'can:variants.manage'])->name('products.create');
    Route::post('products', [ProductController::class, 'store'])->middleware(['can:products.create', 'can:variants.manage'])->name('products.store');
    Route::get('products/{product}', [ProductController::class, 'show'])->middleware('can:products.view')->name('products.show');
    Route::get('products/{product}/edit', [ProductController::class, 'edit'])->middleware(['can:products.update', 'can:variants.manage'])->name('products.edit');
    Route::put('products/{product}', [ProductController::class, 'update'])->middleware(['can:products.update', 'can:variants.manage'])->name('products.update');
    Route::patch('products/{product}/status', [ProductController::class, 'updateStatus'])->middleware('can:products.deactivate')->name('products.status.update');

    Route::get('categories', [CategoryController::class, 'index'])->middleware('can:categories.view')->name('categories.index');
    Route::get('categories/create', [CategoryController::class, 'create'])->middleware('can:categories.create')->name('categories.create');
    Route::post('categories', [CategoryController::class, 'store'])->middleware('can:categories.create')->name('categories.store');
    Route::get('categories/{category}/edit', [CategoryController::class, 'edit'])->middleware('can:categories.update')->name('categories.edit');
    Route::put('categories/{category}', [CategoryController::class, 'update'])->middleware('can:categories.update')->name('categories.update');
    Route::patch('categories/{category}/status', [CategoryController::class, 'updateStatus'])->middleware('can:categories.deactivate')->name('categories.status.update');

    Route::get('brands', [BrandController::class, 'index'])->middleware('can:brands.view')->name('brands.index');
    Route::get('brands/create', [BrandController::class, 'create'])->middleware('can:brands.create')->name('brands.create');
    Route::post('brands', [BrandController::class, 'store'])->middleware('can:brands.create')->name('brands.store');
    Route::get('brands/{brand}/edit', [BrandController::class, 'edit'])->middleware('can:brands.update')->name('brands.edit');
    Route::put('brands/{brand}', [BrandController::class, 'update'])->middleware('can:brands.update')->name('brands.update');
    Route::patch('brands/{brand}/status', [BrandController::class, 'updateStatus'])->middleware('can:brands.deactivate')->name('brands.status.update');
});
