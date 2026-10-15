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
    layout: { breadcrumbs: [{ title: 'Create user', href: create() }] },
});
</script>

<template>
    <Head title="Create user" />
    <section class="space-y-6 p-6">
        <Heading
            title="Create user"
            description="Create an account and assign its access roles."
        />
        <UserForm
            :action="store.url()"
            method="post"
            :roles="roles"
            :statuses="statuses"
        />
    </section>
</template>
