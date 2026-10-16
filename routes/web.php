<?php

use App\Http\Controllers\DashboardController;
use App\Models\Business;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

Route::get('/', function (): Response|RedirectResponse {
    if (auth()->check()) {
        return to_route('dashboard');
    }

    return Inertia::render('Welcome', [
        'canRegister' => ! Business::query()->exists(),
    ]);
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)->middleware('can:dashboard.view')->name('dashboard');
});

require __DIR__.'/management.php';
require __DIR__.'/catalog.php';
require __DIR__.'/inventory.php';
require __DIR__.'/purchasing.php';
require __DIR__.'/sales.php';
require __DIR__.'/settings.php';
