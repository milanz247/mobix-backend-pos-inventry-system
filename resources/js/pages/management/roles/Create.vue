<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import RoleForm from '@/components/management/RoleForm.vue';
import { create, store } from '@/routes/roles';

type Permission = { id: number; name: string; label: string };
defineProps<{ permissionGroups: Record<string, Permission[]> }>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Create role', href: create() }] },
});
</script>

<template>
    <Head title="Create role" />
    <section class="page-shell">
        <Heading
            title="Create role"
            description="Create a role from the available implemented permissions."
        />
        <RoleForm
            :action="store.url()"
            method="post"
            :permission-groups="permissionGroups"
        />
    </section>
</template>
