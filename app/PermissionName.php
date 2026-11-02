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
    case StorefrontPublish = 'storefront.publish';
    case StorefrontManageDelivery = 'storefront.manage-delivery';
    case StorefrontManagePickupLocations = 'storefront.manage-pickup-locations';
    case StorefrontManageHomepage = 'storefront.manage-homepage';
    case StorefrontViewOrders = 'storefront.view-orders';
    case StorefrontConfirmOrders = 'storefront.confirm-orders';
    case StorefrontCancelOrders = 'storefront.cancel-orders';
    case StorefrontViewPaymentProofs = 'storefront.view-payment-proofs';
    case StorefrontVerifyPayments = 'storefront.verify-payments';
    case StorefrontPrepareOrders = 'storefront.prepare-orders';
    case StorefrontVerifyPicks = 'storefront.verify-picks';
    case StorefrontMarkReady = 'storefront.mark-ready';
    case StorefrontConfirmBankOrders = 'storefront.confirm-bank-orders';
    case StorefrontHandoverOrders = 'storefront.handover-orders';
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

    case ProductsDelete = 'products.delete';
    case ProductActivityView = 'product-activity.view';
    case CategoriesDelete = 'categories.delete';
    case BrandsDelete = 'brands.delete';
    case SuppliersDelete = 'suppliers.delete';
    case PurchasesDelete = 'purchases.delete';
    case InvoiceSettingsView = 'invoice-settings.view';
    case InvoiceSettingsUpdate = 'invoice-settings.update';
    case PosSettingsView = 'pos-settings.view';
    case PosSettingsUpdate = 'pos-settings.update';
    case DamagedStockView = 'damaged-stock.view';
    case DamagedStockCreate = 'damaged-stock.create';
    case DamagedStockRepair = 'damaged-stock.repair';
    case DamagedStockWriteOff = 'damaged-stock.write-off';
    case CreditSalesView = 'credit-sales.view';
    case CreditSalesAddItems = 'credit-sales.add-items';
    case CustomersView = 'customers.view';
    case CustomersCreate = 'customers.create';
    case CustomersUpdate = 'customers.update';
    case CustomersDelete = 'customers.delete';
    case SalesReceiptsPrint = 'sales-receipts.print';
    case ReturnReceiptsPrint = 'return-receipts.print';
    case SaleReplacementsCreate = 'sale-replacements.create';
    case SalesCustomerLink = 'sales.customer-link';
    case UsersChangeStatus = 'users.change-status';
    case LocationsChangeStatus = 'locations.change-status';

    public function module(): string
    {
        return match ($this) {
            self::DashboardView => 'Dashboard',
            self::ProductsDelete, self::ProductActivityView, self::StorefrontPublish => 'Products', self::StorefrontManageDelivery => 'Storefront delivery', self::StorefrontManagePickupLocations => 'Storefront pickup', self::StorefrontManageHomepage => 'Storefront homepage',
            self::StorefrontViewOrders, self::StorefrontConfirmOrders, self::StorefrontCancelOrders,
            self::StorefrontViewPaymentProofs, self::StorefrontVerifyPayments, self::StorefrontPrepareOrders,
            self::StorefrontVerifyPicks, self::StorefrontMarkReady, self::StorefrontConfirmBankOrders,
            self::StorefrontHandoverOrders => 'Storefront orders', self::CategoriesDelete => 'Categories', self::BrandsDelete => 'Brands',
            self::SuppliersDelete => 'Suppliers', self::PurchasesDelete => 'Purchases',
            self::InvoiceSettingsView, self::InvoiceSettingsUpdate => 'Invoice Settings',
            self::PosSettingsView, self::PosSettingsUpdate => 'POS Settings',
            self::DamagedStockView, self::DamagedStockCreate, self::DamagedStockRepair, self::DamagedStockWriteOff => 'Damaged Stock',
            self::CreditSalesView, self::CreditSalesAddItems, self::SalesReceiptsPrint, self::ReturnReceiptsPrint, self::SaleReplacementsCreate, self::SalesCustomerLink => 'POS & Sales',
            self::CustomersView, self::CustomersCreate, self::CustomersUpdate, self::CustomersDelete => 'Customers',
            self::UsersChangeStatus => 'Users', self::LocationsChangeStatus => 'Locations',
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
            self::SalesCostsView => 'POS & Sales',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ProductsDelete, self::CategoriesDelete, self::BrandsDelete, self::SuppliersDelete => 'Delete unused record',
            self::ProductActivityView => 'View product activity history',
            self::PurchasesDelete => 'Delete draft invoice',
            self::UsersChangeStatus, self::LocationsChangeStatus => 'Activate / deactivate',
            self::StorefrontViewOrders => 'View web orders', self::StorefrontConfirmOrders => 'Record calls and confirm COD orders',
            self::StorefrontCancelOrders => 'Cancel declined COD orders',
            self::StorefrontViewPaymentProofs => 'View bank transfer proofs', self::StorefrontVerifyPayments => 'Verify bank transfer payments',
            self::StorefrontPrepareOrders => 'Start web order preparation', self::StorefrontVerifyPicks => 'Verify web order picks',
            self::StorefrontMarkReady => 'Mark web orders ready',
            self::StorefrontConfirmBankOrders => 'Confirm verified bank transfer orders',
            self::StorefrontHandoverOrders => 'Collect COD and hand over shop pickup orders',
            self::StorefrontManagePickupLocations => 'Manage customer pickup locations',
            self::StorefrontManageHomepage => 'Manage storefront homepage and web settings',
            self::DamagedStockCreate => 'Move stock to damaged', self::DamagedStockRepair => 'Return repaired stock', self::DamagedStockWriteOff => 'Write off damaged stock',
            self::CreditSalesView => 'View credit invoices', self::CreditSalesAddItems => 'Add items to credit bill',
            self::CustomersView => 'View customers and statements', self::CustomersCreate => 'Add customer',
            self::SalesReceiptsPrint => 'Print sale receipt', self::ReturnReceiptsPrint => 'Print return / replacement receipt',
            self::SaleReplacementsCreate => 'Issue replacement',
            self::SalesCustomerLink => 'Link historical sale to customer',
            self::UsersAssignRoles => 'Assign roles',
            self::LocationsAssignUsers => 'Assign users',
            self::ProductsDeactivate,
            self::CategoriesDeactivate,
            self::BrandsDeactivate => 'Activate / deactivate',
            self::VariantsManage,
            self::BundlesManage,
            self::SerializedDevicesManage => 'Manage',
            self::PurchasePaymentsCreate => 'Record payment',
            self::PurchaseReturnsCreate => 'Create return',
            self::PurchasesPost => 'Post invoice',
            self::SuppliersDeactivate => 'Activate / deactivate',
            self::PosAccess => 'Access POS',
            self::SalesPriceOverride => 'Override prices',
            self::SalesDiscount => 'Apply discounts',
            self::SalesCredit => 'Allow credit sales',
            self::SalePaymentsCreate => 'Record payment',
            self::SaleReturnsCreate => 'Process returns',
            self::SaleRefundsCreate => 'Issue refunds',
            self::SalesCostsView => 'View costs and profit',
            default => match (str($this->value)->after('.')->toString()) {
                'create' => 'Add', 'update' => 'Edit', 'delete' => 'Delete', default => ucfirst(str_replace('-', ' ', str($this->value)->after('.')->toString()))
            },
        };
    }

    public function feature(): string
    {
        return match ($this) {
            self::PosAccess => 'POS checkout',
            self::SalesCredit, self::CreditSalesView, self::CreditSalesAddItems => 'Credit sales',
            self::SalesReceiptsPrint => 'Sales invoices',
            self::SalePaymentsCreate => 'Customer payments',
            self::SaleReturnsCreate, self::ReturnReceiptsPrint => 'Sales returns',
            self::SaleReplacementsCreate => 'Warranty replacements',
            self::SaleRefundsCreate => 'Customer refunds',
            self::SalesCostsView => 'Cost and profit information',
            self::ProductActivityView => 'Product activity history',
            self::SalesCustomerLink => 'Customer accounts',
            self::InventoryReceive => 'Stock receiving', self::InventoryOpening => 'Opening stock',
            self::InventoryAdjust => 'Stock adjustments', self::InventoryTransfer => 'Stock transfers',
            self::InventoryView => 'Stock overview / location balances',
            self::PurchasePaymentsCreate => 'Supplier payments', self::PurchaseReturnsCreate => 'Purchase returns',
            self::SalesView, self::SalesCreate, self::SalesPriceOverride, self::SalesDiscount => 'Sales invoices',
            default => $this->module(),
        };
    }

    public function action(): string
    {
        return match (true) {
            $this === self::PosAccess => 'view',
            $this === self::StorefrontViewOrders => 'view',
            $this === self::StorefrontConfirmOrders => 'edit',
            $this === self::StorefrontCancelOrders => 'delete',
            $this === self::StorefrontViewPaymentProofs => 'view',
            $this === self::StorefrontVerifyPayments => 'edit',
            $this === self::StorefrontPrepareOrders, $this === self::StorefrontVerifyPicks,
            $this === self::StorefrontMarkReady, $this === self::StorefrontConfirmBankOrders,
            $this === self::StorefrontHandoverOrders => 'edit',
            $this === self::SalesCredit => 'add',
            str_ends_with($this->value, '.view') => 'view',
            str_ends_with($this->value, '.create') => 'add',
            str_ends_with($this->value, '.update') => 'edit',
            str_ends_with($this->value, '.delete') => 'delete',
            default => 'actions',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ProductsDelete => 'Permanently remove an inactive product only when it has no inventory or transaction history.',
            self::CategoriesDelete, self::BrandsDelete, self::SuppliersDelete => 'Remove an unused record; records referenced by transactions remain protected.',
            self::PurchasesDelete => 'Delete draft purchases only. Posted invoices retain their transaction history.',
            self::DamagedStockCreate => 'Deduct available stock and place faulty items in the damaged stock list.',
            self::DamagedStockRepair => 'Move a repaired quantity from damaged stock back to available stock.',
            self::DamagedStockWriteOff => 'Remove unusable damaged quantity without increasing available stock.',
            self::SalesCostsView => 'Reveal buying costs, damaged-stock valuation and gross profit.',
            self::ProductActivityView => 'View recorded changes to product details and variants.',
            self::SaleReplacementsCreate => 'Return faulty goods and issue a matching replacement without a cash refund.',
            self::ShiftsViewAll => 'View other cashiers’ shifts within assigned locations.',
            self::UsersAssignRoles => 'Assign permitted roles to user accounts.',
            default => $this->label().' for '.$this->feature().'.',
        };
    }

    /** @return list<string> */
    public function dependencies(): array
    {
        $specific = match ($this) {
            self::UsersCreate, self::UsersUpdate => ['users.view', 'users.assign-roles'],
            self::ProductsCreate, self::ProductsUpdate => ['products.view', 'variants.manage'],
            self::StorefrontPublish => ['products.view'],
            self::StorefrontManageDelivery => ['locations.view'],
            self::StorefrontConfirmOrders, self::StorefrontCancelOrders, self::StorefrontViewPaymentProofs => ['storefront.view-orders'],
            self::StorefrontVerifyPayments => ['storefront.view-orders', 'storefront.view-payment-proofs'],
            self::StorefrontPrepareOrders, self::StorefrontVerifyPicks, self::StorefrontMarkReady => ['storefront.view-orders'],
            self::StorefrontConfirmBankOrders => ['storefront.view-orders', 'storefront.view-payment-proofs'],
            self::StorefrontHandoverOrders => ['storefront.view-orders', 'shifts.view'],
            self::VariantsManage, self::BundlesManage => ['products.view'],
            self::PosAccess => ['shifts.view'],
            self::SalesCreate => ['sales.view', 'sales.pos', 'shifts.view'],
            self::SalesCredit => ['sales.create', 'credit-sales.view', 'customers.view'],
            self::CreditSalesView => ['sales.view'],
            self::CreditSalesAddItems => ['sales.credit', 'sales.create', 'credit-sales.view'],
            self::SalePaymentsCreate, self::SaleReturnsCreate, self::SaleRefundsCreate, self::SalesReceiptsPrint, self::ReturnReceiptsPrint => ['sales.view'],
            self::SaleReplacementsCreate => ['sales.view', 'sales.create', 'sale-returns.create', 'shifts.view'],
            self::SalesCustomerLink => ['sales.view', 'customers.view'],
            self::PurchasesPost => ['purchases.view', 'inventory.receive'],
            self::PurchasePaymentsCreate, self::PurchaseReturnsCreate => ['purchases.view'],
            self::DamagedStockCreate, self::DamagedStockRepair, self::DamagedStockWriteOff => ['damaged-stock.view'],
            self::ProductActivityView => ['products.view'],
            self::ShiftsOpen, self::ShiftsMovements, self::ShiftsClose => ['shifts.view'],
            self::ShiftsApprove, self::ShiftsCorrect => ['shifts.view', 'shifts.view-all'],
            self::UsersChangeStatus => ['users.view'], self::LocationsChangeStatus => ['locations.view'],
            default => [],
        };
        $view = explode('.', $this->value)[0].'.view';
        if ($view !== $this->value && self::tryFrom($view) !== null && in_array($this->action(), ['add', 'edit', 'delete'], true)) {
            $specific[] = $view;
        }

        return array_values(array_unique($specific));
    }
}
