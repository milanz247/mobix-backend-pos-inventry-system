<?php

use App\Http\Controllers\Settings\BusinessSettingsController;
use App\Http\Controllers\Settings\InvoiceSettingsController;
use App\Http\Controllers\Settings\LocationController;
use App\Http\Controllers\Settings\LocationStatusController;
use App\Http\Controllers\Settings\LocationUserController;
use App\Http\Controllers\Settings\PosSettingsController;
use App\Http\Controllers\Settings\ProfilePhotoController;
use App\Http\Controllers\Settings\ProfileSettingsController;
use App\Http\Controllers\Settings\SecurityController;
use App\PermissionName;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('settings', fn (Request $request): RedirectResponse => $request->user()->can(PermissionName::BusinessSettingsView->value)
        ? to_route('business-settings.edit')
        : to_route('profile-settings.edit'));

    Route::get('settings/profile', [ProfileSettingsController::class, 'edit'])
        ->name('profile-settings.edit');
    Route::put('settings/profile', [ProfileSettingsController::class, 'update'])
        ->name('profile-settings.update');
    Route::get('settings/profile/photo', [ProfilePhotoController::class, 'show'])
        ->name('profile-photo.show');
    Route::delete('settings/profile/photo', [ProfilePhotoController::class, 'destroy'])
        ->name('profile-photo.destroy');

    Route::get('settings/business', [BusinessSettingsController::class, 'edit'])
        ->middleware('can:'.PermissionName::BusinessSettingsView->value)
        ->name('business-settings.edit');

    Route::put('settings/business', [BusinessSettingsController::class, 'update'])
        ->middleware('can:'.PermissionName::BusinessSettingsUpdate->value)
        ->name('business-settings.update');

    Route::get('settings/invoices', [InvoiceSettingsController::class, 'edit'])
        ->middleware('can:'.PermissionName::InvoiceSettingsView->value)
        ->name('invoice-settings.edit');

    Route::put('settings/invoices', [InvoiceSettingsController::class, 'update'])
        ->middleware('can:'.PermissionName::InvoiceSettingsUpdate->value)
        ->name('invoice-settings.update');

    Route::get('settings/pos', [PosSettingsController::class, 'edit'])
        ->middleware('can:'.PermissionName::PosSettingsView->value)
        ->name('pos-settings.edit');

    Route::put('settings/pos', [PosSettingsController::class, 'update'])
        ->middleware('can:'.PermissionName::PosSettingsUpdate->value)
        ->name('pos-settings.update');

    Route::get('settings/locations', [LocationController::class, 'index'])->name('locations.index');
    Route::get('settings/locations/create', [LocationController::class, 'create'])->name('locations.create');
    Route::post('settings/locations', [LocationController::class, 'store'])->name('locations.store');
    Route::get('settings/locations/{location}', [LocationController::class, 'show'])->name('locations.show');
    Route::get('settings/locations/{location}/edit', [LocationController::class, 'edit'])->name('locations.edit');
    Route::put('settings/locations/{location}', [LocationController::class, 'update'])->name('locations.update');
    Route::patch('settings/locations/{location}/status', [LocationStatusController::class, 'update'])->name('locations.status.update');
    Route::get('settings/locations/{location}/users', [LocationUserController::class, 'edit'])->name('locations.users.edit');
    Route::put('settings/locations/{location}/users', [LocationUserController::class, 'update'])->name('locations.users.update');
});

Route::middleware(['auth'])->group(function () {
    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
