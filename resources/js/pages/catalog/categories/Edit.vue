<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CategoryForm from '@/components/catalog/CategoryForm.vue';
import Heading from '@/components/Heading.vue';
import { index, update } from '@/routes/categories';

type Option = { value: string; label: string };
type Category = {
    id: number;
    name: string;
    description: string | null;
    status: string;
};
defineProps<{ category: Category; statuses: Option[] }>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Edit category', href: index() }] },
});
</script>

<template>
    <Head :title="`Edit ${category.name}`" />
    <section class="page-shell">
        <Heading
            title="Edit category"
            description="Update this category while preserving linked products."
        />
        <CategoryForm
            :action="update.url(category.id)"
            method="put"
            :category="category"
            :statuses="statuses"
        />
    </section>
</template>
