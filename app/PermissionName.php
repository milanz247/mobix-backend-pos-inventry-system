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
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::UsersAssignRoles => 'Assign roles',
            self::LocationsAssignUsers => 'Assign users',
            default => ucfirst(str_replace('-', ' ', str($this->value)->after('.')->toString())),
        };
    }
}
