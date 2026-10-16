<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import PurchaseForm from '@/components/purchasing/PurchaseForm.vue';
import { store } from '@/routes/purchases';
import { index } from '@/routes/purchases';

type Option = { id: number; name: string };
type Location = Option & { code: string };
type Variant = {
    id: number;
    label: string;
    sku: string;
    tracking_mode: 'quantity_based' | 'serialized';
    cost_price: string;
    selling_price: string;
};
defineProps<{
    suppliers: Option[];
    locations: Location[];
    variants: Variant[];
    currency: string;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Purchases', href: index() },
            { title: 'Create purchase' },
        ],
    },
});
</script>
<template>
    <Head title="Create purchase" />
    <section class="page-shell">
        <Heading
            title="Create purchase"
            description="Enter a supplier invoice and keep it as a draft until it is ready to receive."
        /><PurchaseForm
            :action="store().url"
            method="post"
            :suppliers="suppliers"
            :locations="locations"
            :variants="variants"
            :currency="currency"
        />
    </section>
</template>
