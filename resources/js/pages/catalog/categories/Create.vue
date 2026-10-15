<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CategoryForm from '@/components/catalog/CategoryForm.vue';
import Heading from '@/components/Heading.vue';
import { create, store } from '@/routes/categories';

type Option = { value: string; label: string };
defineProps<{ statuses: Option[] }>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Create category', href: create() }] },
});
</script>

<template>
    <Head title="Create category" />
    <section class="page-shell">
        <Heading
            title="Create category"
            description="Group products into clear catalogue categories."
        />
        <CategoryForm
            :action="store.url()"
            method="post"
            :statuses="statuses"
        />
    </section>
</template>
