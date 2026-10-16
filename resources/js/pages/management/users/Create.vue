<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import UserForm from '@/components/management/UserForm.vue';
import { create, store } from '@/routes/users';

defineProps<{
    roles: { id: number; name: string }[];
    statuses: { value: string; label: string }[];
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Invite team member', href: create() }] },
});
</script>

<template>
    <Head title="Invite team member" />
    <section class="page-shell">
        <Heading
            title="Invite a team member"
            description="Send a secure password setup link and assign the right access roles."
        />
        <UserForm
            :action="store.url()"
            method="post"
            :roles="roles"
            :statuses="statuses"
        />
    </section>
</template>
