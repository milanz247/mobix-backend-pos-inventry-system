<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import BrandForm from '@/components/catalog/BrandForm.vue';
import Heading from '@/components/Heading.vue';
import { create, store } from '@/routes/brands';

type Option = { value: string; label: string };
defineProps<{ statuses: Option[] }>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Create brand', href: create() }] },
});
</script>

<template>
    <Head title="Create brand" />
    <section class="page-shell">
        <Heading
            title="Create brand"
            description="Add a manufacturer or brand to the product catalogue."
        />
        <BrandForm :action="store.url()" method="post" :statuses="statuses" />
    </section>
</template>
