<?php

namespace App;

enum PermissionName: string
{
    case DashboardView = 'dashboard.view';
    case ShiftsView = 'shifts.view';
    case ShiftsOpen = 'shifts.open';
    case ShiftsMovements = 'shifts.movements';
    case ShiftsClose = 'shifts.close';
    case ShiftsViewAll = 'shifts.view-all';
    case ShiftsApprove = 'shifts.approve';
    case ShiftsCorrect = 'shifts.correct';
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
    case InventoryView = 'inventory.view';
    case InventoryReceive = 'inventory.receive';
    case InventoryOpening = 'inventory.opening';
    case InventoryAdjust = 'inventory.adjust';
    case InventoryTransfer = 'inventory.transfer';
    case SerializedDevicesView = 'serialized-devices.view';
    case SerializedDevicesManage = 'serialized-devices.manage';
    case StockMovementsView = 'stock-movements.view';
    case SuppliersView = 'suppliers.view';
    case SuppliersCreate = 'suppliers.create';
    case SuppliersUpdate = 'suppliers.update';
    case SuppliersDeactivate = 'suppliers.deactivate';
    case PurchasesView = 'purchases.view';
    case PurchasesCreate = 'purchases.create';
    case PurchasesUpdate = 'purchases.update';
    case PurchasesPost = 'purchases.post';
    case PurchasePaymentsCreate = 'purchase-payments.create';
    case PurchaseReturnsCreate = 'purchase-returns.create';
    case PosAccess = 'sales.pos';
    case SalesView = 'sales.view';
    case SalesCreate = 'sales.create';
    case SalesPriceOverride = 'sales.price-override';
    case SalesDiscount = 'sales.discount';
    case SalesCredit = 'sales.credit';
    case SalePaymentsCreate = 'sale-payments.create';
    case SaleReturnsCreate = 'sale-returns.create';
    case SaleRefundsCreate = 'sale-refunds.create';
    case SalesCostsView = 'sales.costs.view';
    case SalesReportsView = 'sales.reports.view';

    public function module(): string
    {
        return match ($this) {
            self::DashboardView => 'Dashboard',
            self::ShiftsView, self::ShiftsOpen, self::ShiftsMovements, self::ShiftsClose,
            self::ShiftsViewAll, self::ShiftsApprove, self::ShiftsCorrect => 'Cashier Shifts',
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
            self::InventoryView,
            self::InventoryReceive,
            self::InventoryOpening,
            self::InventoryAdjust,
            self::InventoryTransfer => 'Inventory',
            self::SerializedDevicesView,
            self::SerializedDevicesManage => 'Serialized Devices',
            self::StockMovementsView => 'Stock Movements',
            self::SuppliersView,
            self::SuppliersCreate,
            self::SuppliersUpdate,
            self::SuppliersDeactivate => 'Suppliers',
            self::PurchasesView,
            self::PurchasesCreate,
            self::PurchasesUpdate,
            self::PurchasesPost,
            self::PurchasePaymentsCreate,
            self::PurchaseReturnsCreate => 'Purchases',
            self::PosAccess,
            self::SalesView,
            self::SalesCreate,
            self::SalesPriceOverride,
            self::SalesDiscount,
            self::SalesCredit,
            self::SalePaymentsCreate,
            self::SaleReturnsCreate,
            self::SaleRefundsCreate,
            self::SalesCostsView,
            self::SalesReportsView => 'POS & Sales',
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
            self::BundlesManage,
            self::SerializedDevicesManage => 'Manage',
            self::PurchasePaymentsCreate => 'Record payment',
            self::PurchaseReturnsCreate => 'Create return',
            self::PurchasesPost => 'Post invoice',
            self::SuppliersDeactivate => 'Deactivate',
            self::PosAccess => 'Access POS',
            self::SalesPriceOverride => 'Override prices',
            self::SalesDiscount => 'Apply discounts',
            self::SalesCredit => 'Allow credit sales',
            self::SalePaymentsCreate => 'Record payment',
            self::SaleReturnsCreate => 'Process returns',
            self::SaleRefundsCreate => 'Issue refunds',
            self::SalesCostsView => 'View costs and profit',
            self::SalesReportsView => 'View sales reports',
            default => ucfirst(str_replace('-', ' ', str($this->value)->after('.')->toString())),
        };
    }
}
