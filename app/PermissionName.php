<?php

namespace App;

enum PermissionName: string
{
    case DashboardView = 'dashboard.view';
    case UsersView = 'users.view';
    case UsersCreate = 'users.create';
    case UsersUpdate = 'users.update';
    case UsersDelete = 'users.delete';
    case UsersAssignRoles = 'users.assign-roles';
    case RolesView = 'roles.view';
    case RolesCreate = 'roles.create';
    case RolesUpdate = 'roles.update';
    case RolesDelete = 'roles.delete';
    case PermissionsView = 'permissions.view';
    case BusinessSettingsView = 'business-settings.view';
    case BusinessSettingsUpdate = 'business-settings.update';
    case LocationsView = 'locations.view';
    case LocationsCreate = 'locations.create';
    case LocationsUpdate = 'locations.update';
    case LocationsAssignUsers = 'locations.assign-users';
    case ProductsView = 'products.view';
    case ProductsCreate = 'products.create';
    case ProductsUpdate = 'products.update';
    case ProductsDeactivate = 'products.deactivate';
    case CategoriesView = 'categories.view';
    case CategoriesCreate = 'categories.create';
    case CategoriesUpdate = 'categories.update';
    case CategoriesDeactivate = 'categories.deactivate';
    case BrandsView = 'brands.view';
    case BrandsCreate = 'brands.create';
    case BrandsUpdate = 'brands.update';
    case BrandsDeactivate = 'brands.deactivate';
    case VariantsManage = 'variants.manage';
    case BundlesManage = 'bundles.manage';

    public function module(): string
    {
        return match ($this) {
            self::DashboardView => 'Dashboard',
            self::UsersView,
            self::UsersCreate,
            self::UsersUpdate,
            self::UsersDelete,
            self::UsersAssignRoles => 'Users',
            self::RolesView,
            self::RolesCreate,
            self::RolesUpdate,
            self::RolesDelete => 'Roles',
            self::PermissionsView => 'Permissions',
            self::BusinessSettingsView,
            self::BusinessSettingsUpdate => 'Business Settings',
            self::LocationsView,
            self::LocationsCreate,
            self::LocationsUpdate,
            self::LocationsAssignUsers => 'Locations',
            self::ProductsView,
            self::ProductsCreate,
            self::ProductsUpdate,
            self::ProductsDeactivate => 'Products',
            self::CategoriesView,
            self::CategoriesCreate,
            self::CategoriesUpdate,
            self::CategoriesDeactivate => 'Categories',
            self::BrandsView,
            self::BrandsCreate,
            self::BrandsUpdate,
            self::BrandsDeactivate => 'Brands',
            self::VariantsManage => 'Variants',
            self::BundlesManage => 'Bundles',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::UsersAssignRoles => 'Assign roles',
            self::LocationsAssignUsers => 'Assign users',
            self::ProductsDeactivate,
            self::CategoriesDeactivate,
            self::BrandsDeactivate => 'Deactivate',
            self::VariantsManage,
            self::BundlesManage => 'Manage',
            default => ucfirst(str_replace('-', ' ', str($this->value)->after('.')->toString())),
        };
    }
}
