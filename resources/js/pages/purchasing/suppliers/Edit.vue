<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import SupplierForm from '@/components/purchasing/SupplierForm.vue';
import { update } from '@/routes/suppliers';
import { index } from '@/routes/suppliers';
type Supplier = {
    id: number;
    name: string;
    contact_person: string | null;
    email: string | null;
    phone: string;
    alternate_phone: string | null;
    address: string | null;
    city: string | null;
    notes: string | null;
};
const props = defineProps<{ supplier: Supplier }>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Suppliers', href: index() },
            { title: 'Edit supplier' },
        ],
    },
});
</script>
<template>
    <Head :title="`Edit ${supplier.name}`" />
    <section class="page-shell">
        <Heading
            title="Edit supplier"
            :description="`Update contact details for ${supplier.name}.`"
        /><SupplierForm
            :action="update(supplier.id).url"
            method="put"
            :supplier="supplier"
        />
    </section>
</template>
