<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import PurchaseForm from '@/components/purchasing/PurchaseForm.vue';
import { update, index } from '@/routes/purchases';

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
type Purchase = {
    id: number;
    supplier: Option;
    location: Location;
    invoice_number: string;
    invoice_date: string;
    notes: string | null;
    lines: {
        product_variant_id: number;
        tracking_mode: string;
        quantity: string;
        unit_cost: string;
        discount_amount: string;
        devices: {
            imei: string;
            imei2: string | null;
            serial_number: string | null;
            condition: string;
            unit_cost: string;
            unit_selling_price: string | null;
        }[];
    }[];
};
const props = defineProps<{
    suppliers: Option[];
    locations: Location[];
    variants: Variant[];
    currency: string;
    purchase: Purchase;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Purchases', href: index() },
            { title: 'Edit draft' },
        ],
    },
});
</script>
<template>
    <Head :title="`Edit ${purchase.invoice_number}`" />
    <section class="page-shell">
        <Heading
            title="Edit purchase draft"
            :description="`Update invoice ${purchase.invoice_number}. Draft changes do not affect stock.`"
        /><PurchaseForm
            :action="update(purchase.id).url"
            method="put"
            :suppliers="suppliers"
            :locations="locations"
            :variants="variants"
            :currency="currency"
            :purchase="purchase"
        />
    </section>
</template>
