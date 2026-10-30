<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import RoleForm from '@/components/management/RoleForm.vue';
import { index, update } from '@/routes/roles';

import type {
    PermissionGroups,
    FunctionPermission as Permission,
} from '@/types/permissions';
type Role = {
    id: number;
    name: string;
    description: string | null;
    is_system: boolean;
    permissions: Permission[];
};
defineProps<{ role: Role; permissionGroups: PermissionGroups }>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Edit role', href: index() }] },
});
</script>

<template>
    <Head :title="`Edit ${role.name}`" />
    <section class="page-shell">
        <Heading
            title="Edit role"
            :description="
                role.is_system
                    ? 'System role names are protected.'
                    : 'Update role details and permissions.'
            "
        />
        <RoleForm
            :action="update.url(role.id)"
            method="put"
            :role="role"
            :permission-groups="permissionGroups"
        />
    </section>
</template>
