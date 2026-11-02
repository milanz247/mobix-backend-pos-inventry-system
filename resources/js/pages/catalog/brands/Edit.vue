<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import BrandForm from '@/components/catalog/BrandForm.vue';
import Heading from '@/components/Heading.vue';
import { index, update } from '@/routes/brands';

type Option = { value: string; label: string };
type Brand = {
    id: number;
    name: string;
    description: string | null;
    logo_url: string | null;
    show_on_homepage: boolean;
    homepage_order: number;
    status: string;
};
defineProps<{ brand: Brand; statuses: Option[] }>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Edit brand', href: index() }] },
});
</script>

<template>
    <Head :title="`Edit ${brand.name}`" />
    <section class="page-shell">
        <Heading
            title="Edit brand"
            description="Update brand details without removing linked products."
        />
        <BrandForm
            :action="update.url(brand.id)"
            method="put"
            :brand="brand"
            :statuses="statuses"
        />
    </section>
</template>
