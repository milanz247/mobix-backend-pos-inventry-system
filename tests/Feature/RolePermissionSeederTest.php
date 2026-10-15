<?php

use App\Models\Role;
use App\PermissionName;
use App\SystemRole;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\Models\Permission;

test('role and permission seeder is idempotent', function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(RolePermissionSeeder::class);

    expect(Role::query()->count())->toBe(count(SystemRole::cases()))
        ->and(Permission::query()->count())->toBe(count(PermissionName::cases()))
        ->and(Role::findByName(SystemRole::Owner->value)->permissions)->toHaveCount(count(PermissionName::cases()));
});

test('reseed does not overwrite customized non owner permissions', function () {
    $this->seed(RolePermissionSeeder::class);
    $role = Role::findByName(SystemRole::Manager->value);
    $role->syncPermissions([PermissionName::DashboardView->value]);

    $this->seed(RolePermissionSeeder::class);

    expect($role->refresh()->permissions->pluck('name')->all())->toBe([PermissionName::DashboardView->value]);
});
