<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import UserForm from '@/components/management/UserForm.vue';
import { index, update } from '@/routes/users';

type Role = { id: number; name: string };
type ManagedUser = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    status: string;
    roles: Role[];
    locations: { id: number; name: string; code: string; status: string }[];
};
defineProps<{
    managedUser: ManagedUser;
    roles: Role[];
    statuses: { value: string; label: string }[];
    locations: { id: number; name: string; code: string; status: string }[];
}>();
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Edit user', href: index() }],
    },
});
</script>

<template>
    <Head :title="`Edit ${managedUser.name}`" />
    <section class="page-shell">
        <Heading
            title="Edit user"
            description="Update account details, status, password, and roles."
        />
        <UserForm
            :action="update.url(managedUser.id)"
            method="put"
            :managed-user="managedUser"
            :roles="roles"
            :statuses="statuses"
            :locations="locations"
        />
    </section>
</template>
