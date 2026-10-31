<?php

namespace App\Support;

use App\Models\User;
use App\PermissionName;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionCatalog
{
    /** @return array<string, list<array<string, mixed>>> */
    public function groups(?User $actor = null): array
    {
        $groups = [];
        foreach (Permission::query()->where('guard_name', 'web')->orderBy('name')->get() as $permission) {
            $defined = PermissionName::tryFrom($permission->name);
            $groups[$defined?->module() ?? 'Custom permissions'][] = [
                'id' => $permission->id, 'name' => $permission->name,
                'label' => $defined?->label() ?? $permission->name,
                'feature' => $defined?->feature() ?? 'Custom permissions',
                'action' => $defined?->action() ?? 'actions',
                'description' => $defined?->description() ?? 'Custom application permission.',
                'requires' => $defined?->dependencies() ?? [],
                'assignable' => $actor === null || $actor->can($permission->name),
            ];
        }
        ksort($groups);

        return $groups;
    }

    /** @return array<string, list<string>> */
    public static function legacyGrants(): array
    {
        return [
            'products.deactivate' => ['products.delete'], 'categories.deactivate' => ['categories.delete'],
            'brands.deactivate' => ['brands.delete'], 'suppliers.deactivate' => ['suppliers.delete'],
            'purchases.update' => ['purchases.delete'], 'users.update' => ['users.change-status'],
            'locations.update' => ['locations.change-status'],
            'business-settings.view' => ['invoice-settings.view', 'pos-settings.view'],
            'business-settings.update' => ['invoice-settings.update', 'pos-settings.update'],
            'inventory.view' => ['damaged-stock.view'],
            'inventory.adjust' => ['damaged-stock.create', 'damaged-stock.repair', 'damaged-stock.write-off'],
            'sales.view' => ['credit-sales.view', 'sales-receipts.print', 'return-receipts.print', 'customers.view'],
            'sales.credit' => ['credit-sales.add-items', 'customers.create'],
            'sale-returns.create' => ['sale-replacements.create'],
        ];
    }

    public static function install(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (PermissionName::cases() as $name) {
            Permission::findOrCreate($name->value, 'web');
        }
        foreach (self::legacyGrants() as $legacy => $newNames) {
            $oldId = Permission::query()->where('name', $legacy)->where('guard_name', 'web')->value('id');
            if ($oldId === null) {
                continue;
            }
            foreach ($newNames as $name) {
                $newId = Permission::findByName($name, 'web')->id;
                foreach (['role_has_permissions' => ['role_id'], 'model_has_permissions' => ['model_type', 'model_id']] as $table => $keys) {
                    foreach (DB::table($table)->where('permission_id', $oldId)->get() as $grant) {
                        $row = ['permission_id' => $newId];
                        foreach ($keys as $key) {
                            $row[$key] = $grant->{$key};
                        }
                        DB::table($table)->insertOrIgnore($row);
                    }
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
