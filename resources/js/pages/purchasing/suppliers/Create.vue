<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import SupplierForm from '@/components/purchasing/SupplierForm.vue';
import { store } from '@/routes/suppliers';
import { index } from '@/routes/suppliers';
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Suppliers', href: index() },
            { title: 'Add supplier' },
        ],
    },
});
</script>
<template>
    <Head title="Add supplier" />
    <section class="page-shell">
        <Heading
            title="Add supplier"
            description="Save supplier contact information for future purchase invoices."
        /><SupplierForm :action="store().url" method="post" />
    </section>
</template>
