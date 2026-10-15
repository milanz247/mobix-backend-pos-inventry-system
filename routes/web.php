<?php

use App\Models\Business;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

Route::get('/', fn (): Response => Inertia::render('Welcome', [
    'canRegister' => ! Business::query()->exists(),
]))->name('home');

Route::middleware(['auth'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->middleware('can:dashboard.view')->name('dashboard');
});

require __DIR__.'/management.php';
require __DIR__.'/catalog.php';
require __DIR__.'/settings.php';
