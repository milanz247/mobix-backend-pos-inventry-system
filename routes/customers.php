<?php

use App\Http\Controllers\Customers\CustomerController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'can:customers.view'])->prefix('customers')->name('customers.')->group(function (): void {
    Route::get('/', [CustomerController::class, 'index'])->name('index');
    Route::get('/search', [CustomerController::class, 'search'])->name('search');
    Route::post('/', [CustomerController::class, 'store'])->middleware('can:customers.create')->name('store');
    Route::get('/{customer}', [CustomerController::class, 'show'])->name('show');
    Route::delete('/{customer}', [CustomerController::class, 'destroy'])->middleware('can:customers.delete')->name('destroy');
    Route::put('/{customer}', [CustomerController::class, 'update'])->middleware('can:customers.update')->name('update');
});
