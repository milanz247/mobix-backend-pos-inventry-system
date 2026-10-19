<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['view', 'open', 'movements', 'close', 'view-all', 'approve', 'correct'] as $ability) {
            $permission = Permission::findOrCreate('shifts.'.$ability, 'web');
            $roles = in_array($ability, ['view-all', 'approve', 'correct'], true) ? ['Owner', 'Admin', 'Manager'] : ['Owner', 'Admin', 'Manager', 'Cashier'];
            foreach (Role::query()->where('guard_name', 'web')->whereIn('name', $roles)->get() as $role) {
                $role->givePermissionTo($permission);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', ['shifts.view', 'shifts.open', 'shifts.movements', 'shifts.close', 'shifts.view-all', 'shifts.approve', 'shifts.correct'])->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
