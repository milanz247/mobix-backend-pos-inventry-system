<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    ChevronRight,
    LayoutGrid,
    Package,
    ShoppingCart,
    Settings,
    Users,
    Warehouse,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { edit as editBusiness } from '@/routes/business-settings';
import { edit as editInvoices } from '@/routes/invoice-settings';
import { edit as editPosSettings } from '@/routes/pos-settings';
import { edit as editProfile } from '@/routes/profile-settings';
import { dashboard } from '@/routes';
import { index as brandsIndex } from '@/routes/brands';
import { index as categoriesIndex } from '@/routes/categories';
import { index as permissionsIndex } from '@/routes/permissions';
import { index as productsIndex } from '@/routes/products';
import { index as rolesIndex } from '@/routes/roles';
import { index as usersIndex } from '@/routes/users';
import { index as locationsIndex } from '@/routes/locations';
import { index as inventoryIndex } from '@/routes/inventory';
import { index as inventoryLocations } from '@/routes/inventory/locations';
import { create as receivingCreate } from '@/routes/inventory/receiving';
import { create as openingCreate } from '@/routes/inventory/opening';
import { create as adjustmentsCreate } from '@/routes/inventory/adjustments';
import { create as transfersCreate } from '@/routes/inventory/transfers';
import { index as devicesIndex } from '@/routes/inventory/devices';
import { index as movementsIndex } from '@/routes/inventory/movements';
import { index as suppliersIndex } from '@/routes/suppliers';
import {
    index as purchasesIndex,
    create as purchaseCreate,
} from '@/routes/purchases';
import { index as posIndex } from '@/routes/pos';
import { index as salesIndex, reports as salesReports } from '@/routes/sales';
import { edit as editSecurity } from '@/routes/security';
import type { Auth, NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

const page = usePage<{ auth: Auth }>();
const auth = page.props.auth;
const appName = page.props.name;
const appVersion = '1.0.0';
const currentYear = new Date().getFullYear();
const can = (permission: string): boolean =>
    auth.permissions.includes(permission);
const userNavItems = computed<NavItem[]>(() => [
    ...(can('users.view') ? [{ title: 'All users', href: usersIndex() }] : []),
    ...(can('roles.view') ? [{ title: 'Roles', href: rolesIndex() }] : []),
    ...(can('permissions.view')
        ? [{ title: 'Permissions', href: permissionsIndex() }]
        : []),
]);
const catalogNavItems = computed<NavItem[]>(() => [
    ...(can('products.view')
        ? [{ title: 'All products', href: productsIndex() }]
        : []),
    ...(can('categories.view')
        ? [{ title: 'Categories', href: categoriesIndex() }]
        : []),
    ...(can('brands.view') ? [{ title: 'Brands', href: brandsIndex() }] : []),
]);
const inventoryNavItems = computed<NavItem[]>(() => [
    ...(can('inventory.view')
        ? [
              { title: 'Overview', href: inventoryIndex() },
              { title: 'Stock by location', href: inventoryLocations() },
          ]
        : []),
    ...(can('inventory.receive')
        ? [{ title: 'Stock receiving', href: receivingCreate() }]
        : []),
    ...(can('inventory.opening')
        ? [{ title: 'Opening stock', href: openingCreate() }]
        : []),
    ...(can('inventory.adjust')
        ? [{ title: 'Adjustments', href: adjustmentsCreate() }]
        : []),
    ...(can('inventory.transfer')
        ? [{ title: 'Transfers', href: transfersCreate() }]
        : []),
    ...(can('serialized-devices.view')
        ? [{ title: 'IMEI lookup', href: devicesIndex() }]
        : []),
    ...(can('stock-movements.view')
        ? [{ title: 'Movement history', href: movementsIndex() }]
        : []),
]);
const purchasingNavItems = computed<NavItem[]>(() => [
    ...(can('suppliers.view')
        ? [{ title: 'Suppliers', href: suppliersIndex() }]
        : []),
    ...(can('purchases.view')
        ? [{ title: 'Purchase invoices', href: purchasesIndex() }]
        : []),
    ...(can('purchases.create')
        ? [{ title: 'Create purchase', href: purchaseCreate() }]
        : []),
]);
const salesNavItems = computed<NavItem[]>(() => [
    ...(can('sales.pos') ? [{ title: 'POS checkout', href: posIndex() }] : []),
    ...(can('sales.view')
        ? [{ title: 'Sales history', href: salesIndex() }]
        : []),
    ...(can('sales.reports.view')
        ? [{ title: 'Sales reports', href: salesReports() }]
        : []),
]);
const settingsNavItems = computed<NavItem[]>(() => [
    ...(can('business-settings.view')
        ? [
              { title: 'Business settings', href: editBusiness() },
              { title: 'Invoice settings', href: editInvoices() },
              { title: 'POS screen settings', href: editPosSettings() },
          ]
        : []),
    ...(can('locations.view')
        ? [{ title: 'Locations', href: locationsIndex() }]
        : []),
    { title: 'My profile', href: editProfile() },
    { title: 'Security', href: editSecurity() },
]);

const { isCurrentUrl } = useCurrentUrl();
const settingsAreActive = computed(() =>
    settingsNavItems.value.some((item) => isCurrentUrl(item.href)),
);
const usersAreActive = computed(() =>
    userNavItems.value.some((item) => isCurrentUrl(item.href)),
);
const catalogIsActive = computed(() =>
    catalogNavItems.value.some((item) => isCurrentUrl(item.href)),
);
const inventoryIsActive = computed(() =>
    inventoryNavItems.value.some((item) => isCurrentUrl(item.href)),
);
const purchasingIsActive = computed(() =>
    purchasingNavItems.value.some((item) => isCurrentUrl(item.href)),
);
const salesIsActive = computed(() =>
    salesNavItems.value.some((item) => isCurrentUrl(item.href)),
);

type SidebarSection =
    | 'users'
    | 'catalog'
    | 'inventory'
    | 'purchasing'
    | 'sales'
    | 'settings';

const openSection = ref<SidebarSection | null>(
    usersAreActive.value
        ? 'users'
        : catalogIsActive.value
          ? 'catalog'
          : inventoryIsActive.value
            ? 'inventory'
            : purchasingIsActive.value
              ? 'purchasing'
              : salesIsActive.value
                ? 'sales'
                : settingsAreActive.value
                  ? 'settings'
                  : null,
);

const updateOpenSection = (section: SidebarSection, isOpen: boolean): void => {
    openSection.value = isOpen ? section : null;
};
</script>

<template>
    <Sidebar collapsible="icon" variant="inset" class="border-0">
        <SidebarHeader class="border-b border-sidebar-border/70 p-3">
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent
            class="[scrollbar-width:none] gap-1 px-1 py-3 [&::-webkit-scrollbar]:hidden"
        >
            <NavMain :items="mainNavItems" />

            <SidebarGroup class="px-2 py-0">
                <SidebarMenu>
                    <Collapsible
                        v-if="userNavItems.length"
                        as-child
                        :open="openSection === 'users'"
                        class="group/collapsible"
                        @update:open="updateOpenSection('users', $event)"
                    >
                        <SidebarMenuItem>
                            <CollapsibleTrigger as-child
                                ><SidebarMenuButton
                                    tooltip="User Management"
                                    :is-active="usersAreActive"
                                    ><Users /><span>User Management</span
                                    ><ChevronRight
                                        class="ml-auto transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90" /></SidebarMenuButton
                            ></CollapsibleTrigger>
                            <CollapsibleContent
                                ><SidebarMenuSub
                                    ><SidebarMenuSubItem
                                        v-for="item in userNavItems"
                                        :key="item.title"
                                        ><SidebarMenuSubButton
                                            as-child
                                            :is-active="isCurrentUrl(item.href)"
                                            ><Link :href="item.href"
                                                ><span>{{
                                                    item.title
                                                }}</span></Link
                                            ></SidebarMenuSubButton
                                        ></SidebarMenuSubItem
                                    ></SidebarMenuSub
                                ></CollapsibleContent
                            >
                        </SidebarMenuItem>
                    </Collapsible>
                </SidebarMenu>
            </SidebarGroup>

            <SidebarGroup v-if="catalogNavItems.length" class="px-2 py-0">
                <SidebarMenu>
                    <Collapsible
                        as-child
                        :open="openSection === 'catalog'"
                        class="group/collapsible"
                        @update:open="updateOpenSection('catalog', $event)"
                    >
                        <SidebarMenuItem>
                            <CollapsibleTrigger as-child>
                                <SidebarMenuButton
                                    tooltip="Products"
                                    :is-active="catalogIsActive"
                                >
                                    <Package />
                                    <span>Products</span>
                                    <ChevronRight
                                        class="ml-auto transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90"
                                    />
                                </SidebarMenuButton>
                            </CollapsibleTrigger>
                            <CollapsibleContent>
                                <SidebarMenuSub>
                                    <SidebarMenuSubItem
                                        v-for="item in catalogNavItems"
                                        :key="item.title"
                                    >
                                        <SidebarMenuSubButton
                                            as-child
                                            :is-active="isCurrentUrl(item.href)"
                                        >
                                            <Link :href="item.href">
                                                <span>{{ item.title }}</span>
                                            </Link>
                                        </SidebarMenuSubButton>
                                    </SidebarMenuSubItem>
                                </SidebarMenuSub>
                            </CollapsibleContent>
                        </SidebarMenuItem>
                    </Collapsible>
                </SidebarMenu>
            </SidebarGroup>

            <SidebarGroup v-if="inventoryNavItems.length" class="px-2 py-0">
                <SidebarMenu>
                    <Collapsible
                        as-child
                        :open="openSection === 'inventory'"
                        class="group/collapsible"
                        @update:open="updateOpenSection('inventory', $event)"
                    >
                        <SidebarMenuItem>
                            <CollapsibleTrigger as-child>
                                <SidebarMenuButton
                                    tooltip="Inventory"
                                    :is-active="inventoryIsActive"
                                >
                                    <Warehouse /><span>Inventory</span
                                    ><ChevronRight
                                        class="ml-auto transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90"
                                    />
                                </SidebarMenuButton>
                            </CollapsibleTrigger>
                            <CollapsibleContent>
                                <SidebarMenuSub>
                                    <SidebarMenuSubItem
                                        v-for="item in inventoryNavItems"
                                        :key="item.title"
                                    >
                                        <SidebarMenuSubButton
                                            as-child
                                            :is-active="isCurrentUrl(item.href)"
                                            ><Link :href="item.href"
                                                ><span>{{
                                                    item.title
                                                }}</span></Link
                                            ></SidebarMenuSubButton
                                        >
                                    </SidebarMenuSubItem>
                                </SidebarMenuSub>
                            </CollapsibleContent>
                        </SidebarMenuItem>
                    </Collapsible>
                </SidebarMenu>
            </SidebarGroup>

            <SidebarGroup v-if="purchasingNavItems.length" class="px-2 py-0">
                <SidebarMenu>
                    <Collapsible
                        as-child
                        :open="openSection === 'purchasing'"
                        class="group/collapsible"
                        @update:open="updateOpenSection('purchasing', $event)"
                    >
                        <SidebarMenuItem>
                            <CollapsibleTrigger as-child>
                                <SidebarMenuButton
                                    tooltip="Suppliers & Purchases"
                                    :is-active="purchasingIsActive"
                                >
                                    <Package />
                                    <span>Purchasing</span>
                                    <ChevronRight
                                        class="ml-auto transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90"
                                    />
                                </SidebarMenuButton>
                            </CollapsibleTrigger>
                            <CollapsibleContent>
                                <SidebarMenuSub>
                                    <SidebarMenuSubItem
                                        v-for="item in purchasingNavItems"
                                        :key="item.title"
                                    >
                                        <SidebarMenuSubButton
                                            as-child
                                            :is-active="isCurrentUrl(item.href)"
                                        >
                                            <Link :href="item.href">
                                                <span>{{ item.title }}</span>
                                            </Link>
                                        </SidebarMenuSubButton>
                                    </SidebarMenuSubItem>
                                </SidebarMenuSub>
                            </CollapsibleContent>
                        </SidebarMenuItem>
                    </Collapsible>
                </SidebarMenu>
            </SidebarGroup>

            <SidebarGroup v-if="salesNavItems.length" class="px-2 py-0">
                <SidebarMenu>
                    <Collapsible
                        as-child
                        :open="openSection === 'sales'"
                        class="group/collapsible"
                        @update:open="updateOpenSection('sales', $event)"
                    >
                        <SidebarMenuItem>
                            <CollapsibleTrigger as-child>
                                <SidebarMenuButton
                                    tooltip="POS & Sales"
                                    :is-active="salesIsActive"
                                >
                                    <ShoppingCart />
                                    <span>POS &amp; Sales</span>
                                    <ChevronRight
                                        class="ml-auto transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90"
                                    />
                                </SidebarMenuButton>
                            </CollapsibleTrigger>
                            <CollapsibleContent>
                                <SidebarMenuSub>
                                    <SidebarMenuSubItem
                                        v-for="item in salesNavItems"
                                        :key="item.title"
                                    >
                                        <SidebarMenuSubButton
                                            as-child
                                            :is-active="isCurrentUrl(item.href)"
                                        >
                                            <Link :href="item.href">
                                                <span>{{ item.title }}</span>
                                            </Link>
                                        </SidebarMenuSubButton>
                                    </SidebarMenuSubItem>
                                </SidebarMenuSub>
                            </CollapsibleContent>
                        </SidebarMenuItem>
                    </Collapsible>
                </SidebarMenu>
            </SidebarGroup>

            <SidebarGroup class="px-2 py-0">
                <SidebarMenu>
                    <Collapsible
                        as-child
                        :open="openSection === 'settings'"
                        class="group/collapsible"
                        @update:open="updateOpenSection('settings', $event)"
                    >
                        <SidebarMenuItem>
                            <CollapsibleTrigger as-child>
                                <SidebarMenuButton
                                    tooltip="Settings"
                                    :is-active="settingsAreActive"
                                >
                                    <Settings />
                                    <span>Settings</span>
                                    <ChevronRight
                                        class="ml-auto transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90"
                                    />
                                </SidebarMenuButton>
                            </CollapsibleTrigger>
                            <CollapsibleContent>
                                <SidebarMenuSub>
                                    <SidebarMenuSubItem
                                        v-for="item in settingsNavItems"
                                        :key="item.title"
                                    >
                                        <SidebarMenuSubButton
                                            as-child
                                            :is-active="isCurrentUrl(item.href)"
                                        >
                                            <Link :href="item.href">
                                                <span>{{ item.title }}</span>
                                            </Link>
                                        </SidebarMenuSubButton>
                                    </SidebarMenuSubItem>
                                </SidebarMenuSub>
                            </CollapsibleContent>
                        </SidebarMenuItem>
                    </Collapsible>
                </SidebarMenu>
            </SidebarGroup>
        </SidebarContent>

        <SidebarFooter
            class="shrink-0 border-t border-sidebar-border/70 bg-sidebar/95 px-3 py-3 backdrop-blur-sm group-data-[collapsible=icon]:px-2"
        >
            <div
                class="min-w-0 text-center text-[0.65rem] leading-relaxed text-sidebar-foreground/50 group-data-[collapsible=icon]:hidden"
            >
                <p class="truncate font-medium text-sidebar-foreground/70">
                    {{ appName }} <span aria-hidden="true">&middot;</span> v{{
                        appVersion
                    }}
                </p>
                <p>&copy; {{ currentYear }} All rights reserved.</p>
            </div>
            <span
                class="hidden text-center text-[0.6rem] font-semibold text-sidebar-foreground/55 group-data-[collapsible=icon]:block"
                :aria-label="`${appName} version ${appVersion}`"
            >
                v{{ appVersion.split('.').slice(0, 2).join('.') }}
            </span>
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
