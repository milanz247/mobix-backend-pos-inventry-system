<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import RoleForm from '@/components/management/RoleForm.vue';
import { create, store } from '@/routes/roles';

import type { PermissionGroups } from '@/types/permissions';
defineProps<{ permissionGroups: PermissionGroups }>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Create role', href: create() }] },
});
</script>

<template>
    <Head title="Create role" />
    <section class="page-shell">
        <Heading
            title="Create role"
            description="Create a role and tick View, Add, Edit, Delete and action permissions by function."
        />
        <RoleForm
            :action="store.url()"
            method="post"
            :permission-groups="permissionGroups"
        />
    </section>
</template>
