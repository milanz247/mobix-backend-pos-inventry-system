<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';

type Line = {
    product: string;
    variant: string;
    sku: string;
    quantity: string;
    total: string;
};
type Sale = {
    id: number;
    sale_number: string;
    posted_at: string;
    customer: string;
    phone: string | null;
    location: string;
    cashier: string;
    total: string;
    paid: string;
    outstanding: string;
    payment_status: string;
    lines: Line[];
};
type PageLink = { url: string | null; label: string; active: boolean };
type Location = { id: number; name: string };
const props = defineProps<{
    sales: { data: Sale[]; links: PageLink[] };
    summary: {
        invoices: number;
        sales_total: string;
        paid_total: string;
        outstanding_total: string;
    };
    locations: Location[];
    filters: {
        product: string;
        customer: string;
        date_from: string;
        date_to: string;
        location_id: string;
    };
    currency: string;
}>();
const filters = ref({ ...props.filters });
let timer: ReturnType<typeof setTimeout> | undefined;
watch(
    filters,
    () => {
        if (timer) clearTimeout(timer);
        timer = setTimeout(
            () =>
                router.get(
                    '/sales/credit/report',
                    {
                        ...filters.value,
                        product: filters.value.product || undefined,
                        customer: filters.value.customer || undefined,
                        date_from: filters.value.date_from || undefined,
                        date_to: filters.value.date_to || undefined,
                        location_id: filters.value.location_id || undefined,
                    },
                    {
                        preserveState: true,
                        preserveScroll: true,
                        replace: true,
                        only: ['sales', 'summary', 'filters'],
                    },
                ),
            250,
        );
    },
    { deep: true },
);
onBeforeUnmount(() => {
    if (timer) clearTimeout(timer);
});
function money(value: string): string {
    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: props.currency,
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Number(value));
}
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Credit sales report', href: '/sales/credit/report' },
        ],
    },
});
</script>

<template>
    <Head title="Credit sales report" />
    <section class="page-shell space-y-4">
        <Heading
            title="Credit sales report"
            description="Review all credit invoices, sold products, payments, and outstanding balances across your authorized locations."
        />
        <div class="surface-panel grid gap-2 p-3 sm:grid-cols-2 xl:grid-cols-5">
            <Input
                v-model="filters.product"
                placeholder="Filter product, variant, or SKU"
                aria-label="Filter by product"
            />
            <Input
                v-model="filters.customer"
                placeholder="Filter customer or phone"
                aria-label="Filter by customer"
            />
            <Input
                v-model="filters.date_from"
                type="date"
                aria-label="From date"
            />
            <Input v-model="filters.date_to" type="date" aria-label="To date" />
            <select
                v-model="filters.location_id"
                aria-label="Filter by location"
            >
                <option value="">All authorized locations</option>
                <option
                    v-for="location in locations"
                    :key="location.id"
                    :value="String(location.id)"
                >
                    {{ location.name }}
                </option>
            </select>
        </div>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <article class="surface-panel p-4">
                <p class="text-xs text-muted-foreground">Credit invoices</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">
                    {{ summary.invoices.toLocaleString() }}
                </p>
            </article>
            <article class="surface-panel p-4">
                <p class="text-xs text-muted-foreground">Credit sales total</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">
                    {{ money(summary.sales_total) }}
                </p>
            </article>
            <article class="surface-panel p-4">
                <p class="text-xs text-muted-foreground">Payments received</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">
                    {{ money(summary.paid_total) }}
                </p>
            </article>
            <article class="surface-panel p-4">
                <p class="text-xs text-muted-foreground">Outstanding balance</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">
                    {{ money(summary.outstanding_total) }}
                </p>
            </article>
        </div>
        <div class="table-panel">
            <div class="overflow-x-auto">
                <table class="data-table responsive-table min-w-[1100px]">
                    <thead>
                        <tr>
                            <th>Date / invoice</th>
                            <th>Customer</th>
                            <th>Location / cashier</th>
                            <th>Products</th>
                            <th>Payment status</th>
                            <th class="text-right">Sale total</th>
                            <th class="text-right">Paid</th>
                            <th class="text-right">Outstanding</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="sale in sales.data" :key="sale.id">
                            <td data-label="Date / invoice">
                                <a
                                    :href="`/sales/${sale.id}`"
                                    class="font-medium text-primary hover:underline"
                                    >{{ sale.sale_number }}</a
                                >
                                <div class="text-xs text-muted-foreground">
                                    {{ sale.posted_at }}
                                </div>
                            </td>
                            <td data-label="Customer">
                                <div class="font-medium">
                                    {{ sale.customer }}
                                </div>
                                <div
                                    v-if="sale.phone"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ sale.phone }}
                                </div>
                            </td>
                            <td data-label="Location / cashier">
                                {{ sale.location }}
                                <div class="text-xs text-muted-foreground">
                                    {{ sale.cashier }}
                                </div>
                            </td>
                            <td data-label="Products">
                                <div
                                    v-for="(line, index) in sale.lines"
                                    :key="`${line.sku}-${index}`"
                                    class="mb-1"
                                >
                                    <span class="font-medium">{{
                                        line.product
                                    }}</span
                                    ><span v-if="line.variant !== 'Default'">
                                        · {{ line.variant }}</span
                                    >
                                    <div class="text-xs text-muted-foreground">
                                        {{ line.sku }} · Qty
                                        {{ line.quantity }} ·
                                        {{ money(line.total) }}
                                    </div>
                                </div>
                            </td>
                            <td data-label="Payment status">
                                <Badge variant="secondary">{{
                                    sale.payment_status.replaceAll('_', ' ')
                                }}</Badge>
                            </td>
                            <td
                                data-label="Sale total"
                                class="text-right tabular-nums"
                            >
                                {{ money(sale.total) }}
                            </td>
                            <td
                                data-label="Paid"
                                class="text-right tabular-nums"
                            >
                                {{ money(sale.paid) }}
                            </td>
                            <td
                                data-label="Outstanding"
                                class="text-right font-semibold tabular-nums"
                            >
                                {{ money(sale.outstanding) }}
                            </td>
                        </tr>
                        <tr v-if="sales.data.length === 0">
                            <td
                                colspan="8"
                                class="py-10 text-center text-muted-foreground"
                            >
                                No credit sales match these filters.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <nav
                v-if="sales.links.length > 3"
                class="flex flex-wrap justify-end gap-1 border-t p-3"
                aria-label="Pagination"
            >
                <button
                    v-for="link in sales.links"
                    :key="link.label"
                    type="button"
                    class="rounded-md border px-3 py-1.5 text-sm disabled:opacity-40"
                    :class="
                        link.active
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-background hover:bg-muted'
                    "
                    :disabled="!link.url"
                    @click="
                        link.url &&
                        router.visit(link.url, { preserveScroll: true })
                    "
                    v-html="link.label"
                />
            </nav>
        </div>
    </section>
</template>
