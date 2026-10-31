<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\Role;
use App\PermissionName;
use App\Support\PermissionCatalog;
use App\SystemRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = collect(PermissionName::cases())->mapWithKeys(
            fn (PermissionName $permission): array => [
                $permission->value => Permission::findOrCreate($permission->value, 'web'),
            ],
        );

        foreach (SystemRole::cases() as $systemRole) {
            $role = Role::query()->firstOrCreate(
                ['name' => $systemRole->value, 'guard_name' => 'web'],
                ['description' => $systemRole->description(), 'is_system' => true],
            );

            if (! $role->is_system) {
                $role->forceFill(['is_system' => true])->save();
            }

            if ($systemRole === SystemRole::Owner) {
                $role->givePermissionTo($permissions->values());
            } elseif ($role->wasRecentlyCreated) {
                $role->syncPermissions($this->defaultPermissions($systemRole));
            }
        }

        $business = Business::query()->with('owner')->first();

        if ($business?->owner !== null && ! $business->owner->hasRole(SystemRole::Owner->value)) {
            $business->owner->assignRole(SystemRole::Owner->value);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @return list<string> */
    private function defaultPermissions(SystemRole $role): array
    {
        $defaults = match ($role) {
            SystemRole::Owner, SystemRole::Admin => array_column(PermissionName::cases(), 'value'),
            SystemRole::Manager => [
                PermissionName::ShiftsView->value,
                PermissionName::ShiftsOpen->value,
                PermissionName::ShiftsMovements->value,
                PermissionName::ShiftsClose->value,
                PermissionName::ShiftsViewAll->value,
                PermissionName::ShiftsApprove->value,
                PermissionName::ShiftsCorrect->value,
                PermissionName::DashboardView->value,
                PermissionName::UsersView->value,
                PermissionName::BusinessSettingsView->value,
                PermissionName::LocationsView->value,
                PermissionName::SuppliersView->value,
                PermissionName::PurchasesView->value,
                PermissionName::SalesView->value,
                PermissionName::PosAccess->value,
                PermissionName::SalesCreate->value,
                PermissionName::SalesDiscount->value,
                PermissionName::SalePaymentsCreate->value,
                PermissionName::SaleReturnsCreate->value,
            ],
            SystemRole::Cashier => [
                PermissionName::ShiftsView->value,
                PermissionName::ShiftsOpen->value,
                PermissionName::ShiftsMovements->value,
                PermissionName::ShiftsClose->value,
                PermissionName::DashboardView->value,
                PermissionName::PosAccess->value,
                PermissionName::SalesCreate->value,
                PermissionName::SalesView->value,
                PermissionName::SalePaymentsCreate->value,
            ],
            SystemRole::Technician => [PermissionName::DashboardView->value],
        };
        foreach (PermissionCatalog::legacyGrants() as $old => $new) {
            if (in_array($old, $defaults, true)) {
                $defaults = [...$defaults, ...$new];
            }
        }

        return array_values(array_unique($defaults));
    }
}
