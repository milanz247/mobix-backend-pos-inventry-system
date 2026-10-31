<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import ReportFilters from '@/components/reports/ReportFilters.vue';
import ReportSummary from '@/components/reports/ReportSummary.vue';
import ShiftPagination from '@/components/shifts/ShiftPagination.vue';
import { shiftDate, shiftMoney } from '@/lib/shift';
type Row = {
    id: string | number;
    reference: string;
    at: string;
    staff: string | null;
    branch: string | null;
    description: string | null;
    type: string;
    quantity: string | null;
    amount: string;
    sale_id: number;
};
const props = defineProps<{
    rows: {
        data: Row[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    summary: Record<string, string | number>;
    section: string;
    filters: Record<string, string | number>;
    currency: string;
    timezone: string;
    locations: { id: number; name: string }[];
    cashiers: { id: number; name: string }[];
    canViewSales: boolean;
}>();
const labels: Record<string, string> = {
    invoices: 'New invoices',
    gross_sales: 'Sales issued (tax included)',
    returns: 'Returns / replacement credits',
    net_sales: 'Net sales after returns',
    collections: 'Payments collected',
    refunds: 'Refunds paid',
    net_collections: 'Net collections',
};
const tabs = [
    ['sales', 'Sales'],
    ['items', 'Sold items'],
    ['payments', 'Payments'],
    ['returns', 'Returns'],
    ['refunds', 'Refunds'],
];
const tabUrl = (section: string) =>
    '/reports/daily-sales?' +
    new URLSearchParams({
        ...Object.fromEntries(
            Object.entries(props.filters).map(([k, v]) => [k, String(v)]),
        ),
        section,
    }).toString();
const cards = computed(() =>
    Object.entries(props.summary).map(([key, value]) => ({
        label: labels[key],
        value:
            key === 'invoices'
                ? value
                : shiftMoney(String(value), props.currency),
    })),
);
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Daily sales', href: '/reports/daily-sales' }],
    },
});
</script>
<template>
    <Head title="Daily sales" />
    <section class="page-shell">
        <Heading
            title="Daily sales"
            :description="`Sales and collections for ${filters.date}, using ${timezone}. Later payments and returns appear on the day they happen.`"
        /><ReportSummary :items="cards" /><ReportFilters
            :preserve="['section']"
            action="/reports/daily-sales"
            :filters="filters"
            :fields="[
                { key: 'date', label: 'Shop day', type: 'date' },
                {
                    key: 'location_id',
                    label: 'Branch',
                    type: 'select',
                    options: locations.map((x) => ({
                        value: x.id,
                        label: x.name,
                    })),
                },
                {
                    key: 'cashier_id',
                    label: 'Staff member',
                    type: 'select',
                    options: cashiers.map((x) => ({
                        value: x.id,
                        label: x.name,
                    })),
                },
            ]"
        />
        <nav class="flex flex-wrap gap-2" aria-label="Daily details">
            <Link
                v-for="[key, label] in tabs"
                :key="key"
                :href="tabUrl(key)"
                class="rounded-md border px-4 py-2 text-sm"
                :class="
                    section === key
                        ? 'bg-primary text-primary-foreground'
                        : 'hover:bg-muted'
                "
                >{{ label }}</Link
            >
        </nav>
        <div class="table-panel">
            <table class="data-table responsive-table">
                <thead>
                    <tr>
                        <th>Reference / time</th>
                        <th>Details</th>
                        <th>Staff / branch</th>
                        <th>Quantity</th>
                        <th class="text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows.data" :key="row.id">
                        <td data-label="Reference">
                            <div>
                                <Link
                                    v-if="canViewSales"
                                    :href="`/sales/${row.sale_id}`"
                                    class="font-semibold text-primary"
                                    >{{ row.reference }}</Link
                                ><span v-else class="font-semibold">{{
                                    row.reference
                                }}</span>
                                <p class="text-xs text-muted-foreground">
                                    {{ shiftDate(row.at, timezone) }}
                                </p>
                            </div>
                        </td>
                        <td data-label="Details">
                            <div>
                                <p>{{ row.description || '—' }}</p>
                                <p class="text-xs text-muted-foreground">
                                    {{ row.type.replaceAll('_', ' ') }}
                                </p>
                            </div>
                        </td>
                        <td data-label="Staff / branch">
                            <div>
                                {{ row.staff || '—' }}
                                <p class="text-xs text-muted-foreground">
                                    {{ row.branch }}
                                </p>
                            </div>
                        </td>
                        <td data-label="Quantity">{{ row.quantity ?? '—' }}</td>
                        <td
                            data-label="Amount"
                            class="text-right font-semibold"
                        >
                            {{ shiftMoney(row.amount, currency) }}
                        </td>
                    </tr>
                    <tr v-if="!rows.data.length">
                        <td
                            colspan="5"
                            class="py-10 text-center text-muted-foreground"
                        >
                            No {{ section }} for this shop day.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <ShiftPagination :links="rows.links" />
        <p class="text-xs text-muted-foreground">
            Sales values include tax. Collections include payments on earlier
            invoices. Replacement issues and matching return credits cancel in
            net sales.
        </p>
    </section>
</template>
