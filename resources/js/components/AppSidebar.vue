<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    ChevronRight,
    LayoutGrid,
    Settings,
    ShieldCheck,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
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
    SidebarGroupLabel,
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
import { dashboard } from '@/routes';
import { create as createRole, index as rolesIndex } from '@/routes/roles';
import { index as permissionsIndex } from '@/routes/permissions';
import { create as createUser, index as usersIndex } from '@/routes/users';
import {
    create as createLocation,
    index as locationsIndex,
} from '@/routes/locations';
import { edit as editSecurity } from '@/routes/security';
import type { Auth, NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

const auth = usePage<{ auth: Auth }>().props.auth;
const can = (permission: string): boolean =>
    auth.permissions.includes(permission);
const userNavItems = computed<NavItem[]>(() => [
    ...(can('users.view') ? [{ title: 'All users', href: usersIndex() }] : []),
    ...(can('users.create')
        ? [{ title: 'Create user', href: createUser() }]
        : []),
]);
const roleNavItems = computed<NavItem[]>(() => [
    ...(can('roles.view') ? [{ title: 'All roles', href: rolesIndex() }] : []),
    ...(can('roles.create')
        ? [{ title: 'Create role', href: createRole() }]
        : []),
    ...(can('permissions.view')
        ? [{ title: 'Permissions', href: permissionsIndex() }]
        : []),
]);
const settingsNavItems = computed<NavItem[]>(() => [
    ...(can('business-settings.view')
        ? [{ title: 'Business settings', href: editBusiness() }]
        : []),
    ...(can('locations.view')
        ? [{ title: 'Locations', href: locationsIndex() }]
        : []),
    ...(can('locations.create')
        ? [{ title: 'Create location', href: createLocation() }]
        : []),
    { title: 'Security', href: editSecurity() },
]);

const { isCurrentUrl } = useCurrentUrl();
const settingsAreActive = computed(() =>
    settingsNavItems.value.some((item) => isCurrentUrl(item.href)),
);
const usersAreActive = computed(() =>
    userNavItems.value.some((item) => isCurrentUrl(item.href)),
);
const rolesAreActive = computed(() =>
    roleNavItems.value.some((item) => isCurrentUrl(item.href)),
);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
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

        <SidebarContent>
            <NavMain :items="mainNavItems" />

            <SidebarGroup class="px-2 py-0">
                <SidebarGroupLabel>Management</SidebarGroupLabel>
                <SidebarMenu>
                    <Collapsible
                        v-if="userNavItems.length"
                        as-child
                        :default-open="usersAreActive"
                        class="group/collapsible"
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

                    <Collapsible
                        v-if="roleNavItems.length"
                        as-child
                        :default-open="rolesAreActive"
                        class="group/collapsible"
                    >
                        <SidebarMenuItem>
                            <CollapsibleTrigger as-child
                                ><SidebarMenuButton
                                    tooltip="Role Management"
                                    :is-active="rolesAreActive"
                                    ><ShieldCheck /><span>Role Management</span
                                    ><ChevronRight
                                        class="ml-auto transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90" /></SidebarMenuButton
                            ></CollapsibleTrigger>
                            <CollapsibleContent
                                ><SidebarMenuSub
                                    ><SidebarMenuSubItem
                                        v-for="item in roleNavItems"
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

                    <Collapsible
                        as-child
                        :default-open="settingsAreActive"
                        class="group/collapsible"
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

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
