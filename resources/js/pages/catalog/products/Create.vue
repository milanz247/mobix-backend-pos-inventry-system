<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import ProductForm from '@/components/catalog/ProductForm.vue';
import { create, store } from '@/routes/products';

type Option = { value: string; label: string };
type RelatedOption = { id: number; name: string };
type ComponentOption = {
    id: number;
    name: string;
    sku: string;
    cost_price: string | null;
    product: string;
};
defineProps<{
    categories: RelatedOption[];
    brands: RelatedOption[];
    productTypes: Option[];
    conditions: Option[];
    trackingModes: Option[];
    statuses: Option[];
    componentVariants: ComponentOption[];
    currency: string;
    canViewCosts: boolean;
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Create product', href: create() }] },
});
</script>

<template>
    <Head title="Create product" />
    <section class="page-shell">
        <Heading
            title="Create product"
            description="Add a phone, spare part, or accessory to the product catalogue."
        />
        <ProductForm
            :action="store.url()"
            method="post"
            :categories="categories"
            :brands="brands"
            :product-types="productTypes"
            :conditions="conditions"
            :tracking-modes="trackingModes"
            :statuses="statuses"
            :component-variants="componentVariants"
            :currency="currency"
            :can-view-costs="canViewCosts"
        />
    </section>
</template>
