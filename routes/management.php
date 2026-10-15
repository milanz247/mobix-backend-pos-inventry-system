<?php

use App\Http\Controllers\Management\PermissionController;
use App\Http\Controllers\Management\RoleController;
use App\Http\Controllers\Management\UserController;
use App\Http\Controllers\Management\UserStatusController;
use App\PermissionName;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::get('users', [UserController::class, 'index'])->middleware('can:'.PermissionName::UsersView->value)->name('users.index');
    Route::get('users/create', [UserController::class, 'create'])->middleware('can:'.PermissionName::UsersCreate->value)->name('users.create');
    Route::post('users', [UserController::class, 'store'])->middleware('can:'.PermissionName::UsersCreate->value)->name('users.store');
    Route::get('users/{user}/edit', [UserController::class, 'edit'])->middleware('can:'.PermissionName::UsersUpdate->value)->name('users.edit');
    Route::put('users/{user}', [UserController::class, 'update'])->middleware('can:'.PermissionName::UsersUpdate->value)->name('users.update');
    Route::patch('users/{user}/status', [UserStatusController::class, 'update'])->middleware('can:'.PermissionName::UsersUpdate->value)->name('users.status.update');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware('can:'.PermissionName::UsersDelete->value)->name('users.destroy');

    Route::get('roles', [RoleController::class, 'index'])->middleware('can:'.PermissionName::RolesView->value)->name('roles.index');
    Route::get('roles/create', [RoleController::class, 'create'])->middleware('can:'.PermissionName::RolesCreate->value)->name('roles.create');
    Route::post('roles', [RoleController::class, 'store'])->middleware('can:'.PermissionName::RolesCreate->value)->name('roles.store');
    Route::get('roles/{role}/edit', [RoleController::class, 'edit'])->middleware('can:'.PermissionName::RolesUpdate->value)->name('roles.edit');
    Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('can:'.PermissionName::RolesUpdate->value)->name('roles.update');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('can:'.PermissionName::RolesDelete->value)->name('roles.destroy');

    Route::get('permissions', [PermissionController::class, 'index'])->middleware('can:'.PermissionName::PermissionsView->value)->name('permissions.index');
});
