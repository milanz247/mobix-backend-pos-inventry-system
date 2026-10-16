<?php

use App\Http\Controllers\Purchasing\PurchaseController;
use App\Http\Controllers\Purchasing\PurchasePaymentController;
use App\Http\Controllers\Purchasing\PurchaseReturnController;
use App\Http\Controllers\Purchasing\SupplierController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::get('suppliers', [SupplierController::class, 'index'])->middleware('can:suppliers.view')->name('suppliers.index');
    Route::get('suppliers/create', [SupplierController::class, 'create'])->middleware('can:suppliers.create')->name('suppliers.create');
    Route::post('suppliers', [SupplierController::class, 'store'])->middleware('can:suppliers.create')->name('suppliers.store');
    Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->middleware('can:suppliers.view')->name('suppliers.show');
    Route::get('suppliers/{supplier}/edit', [SupplierController::class, 'edit'])->middleware('can:suppliers.update')->name('suppliers.edit');
    Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->middleware('can:suppliers.update')->name('suppliers.update');
    Route::patch('suppliers/{supplier}/status', [SupplierController::class, 'updateStatus'])->middleware('can:suppliers.deactivate')->name('suppliers.status.update');

    Route::get('purchases', [PurchaseController::class, 'index'])->middleware('can:purchases.view')->name('purchases.index');
    Route::get('purchases/create', [PurchaseController::class, 'create'])->middleware('can:purchases.create')->name('purchases.create');
    Route::post('purchases', [PurchaseController::class, 'store'])->middleware('can:purchases.create')->name('purchases.store');
    Route::get('purchases/{purchase}', [PurchaseController::class, 'show'])->middleware('can:purchases.view')->name('purchases.show');
    Route::get('purchases/{purchase}/edit', [PurchaseController::class, 'edit'])->middleware('can:purchases.update')->name('purchases.edit');
    Route::put('purchases/{purchase}', [PurchaseController::class, 'update'])->middleware('can:purchases.update')->name('purchases.update');
    Route::post('purchases/{purchase}/post', [PurchaseController::class, 'post'])->middleware(['can:purchases.post', 'can:inventory.receive'])->name('purchases.post');
    Route::post('purchases/{purchase}/payments', [PurchasePaymentController::class, 'store'])->middleware('can:purchase-payments.create')->name('purchases.payments.store');
    Route::get('purchases/{purchase}/returns/create', [PurchaseReturnController::class, 'create'])->middleware(['can:purchases.view', 'can:purchase-returns.create'])->name('purchases.returns.create');
    Route::post('purchases/{purchase}/returns', [PurchaseReturnController::class, 'store'])->middleware(['can:purchases.view', 'can:purchase-returns.create'])->name('purchases.returns.store');
});
