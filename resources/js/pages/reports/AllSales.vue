<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import ReportFilters from '@/components/reports/ReportFilters.vue';
import ReportSummary from '@/components/reports/ReportSummary.vue';
import ShiftPagination from '@/components/shifts/ShiftPagination.vue';
import { shiftDate, shiftMoney } from '@/lib/shift';
type Row = {
    id: number;
    invoice: string;
    date: string;
    customer: string;
    cashier: string;
    branch: string;
    credit: boolean;
    net_total: string;
    net_paid: string;
    balance: string;
    status: string;
};
defineProps<{
    rows: {
        data: Row[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    summary: {
        invoices: number;
        net_sales: string;
        net_paid: string;
        outstanding: string;
    };
    filters: Record<string, string | number>;
    currency: string;
    timezone: string;
    canViewSales: boolean;
    locations: { id: number; name: string }[];
    cashiers: { id: number; name: string }[];
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'All sales report', href: '/reports/all-sales' },
        ],
    },
});
</script>
<template>
    <Head title="All sales report" />
    <section class="page-shell">
        <Heading
            title="All sales report"
            description="All invoices in your branches. Filter by invoice date, cashier, branch or payment status."
        /><ReportSummary
            :items="[
                { label: 'Invoices', value: summary.invoices },
                {
                    label: 'Net invoice value',
                    value: shiftMoney(summary.net_sales, currency),
                },
                {
                    label: 'Net payments after refunds',
                    value: shiftMoney(summary.net_paid, currency),
                },
                {
                    label: 'Outstanding',
                    value: shiftMoney(summary.outstanding, currency),
                },
            ]"
        /><ReportFilters
            action="/reports/all-sales"
            :filters="filters"
            :fields="[
                { key: 'search', label: 'Invoice or customer', type: 'search' },
                { key: 'date_from', label: 'Invoice from', type: 'date' },
                { key: 'date_to', label: 'Invoice to', type: 'date' },
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
                    label: 'Cashier',
                    type: 'select',
                    options: cashiers.map((x) => ({
                        value: x.id,
                        label: x.name,
                    })),
                },
                {
                    key: 'payment_status',
                    label: 'Payment status',
                    type: 'select',
                    options: [
                        { value: 'paid', label: 'Paid' },
                        { value: 'unpaid', label: 'Unpaid' },
                        { value: 'partially_paid', label: 'Partially paid' },
                    ],
                },
            ]"
        />
        <div class="table-panel">
            <table class="data-table responsive-table">
                <thead>
                    <tr>
                        <th>Invoice / date</th>
                        <th>Customer</th>
                        <th>Cashier / branch</th>
                        <th>Net total</th>
                        <th>Net paid / due</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows.data" :key="row.id">
                        <td data-label="Invoice">
                            <div>
                                <p class="font-semibold">{{ row.invoice }}</p>
                                <p class="text-xs text-muted-foreground">
                                    {{ shiftDate(row.date, timezone) }}
                                </p>
                            </div>
                        </td>
                        <td data-label="Customer">
                            <div>
                                {{ row.customer }}
                                <p class="text-xs text-muted-foreground">
                                    {{ row.credit ? 'Credit bill' : 'Sale' }}
                                </p>
                            </div>
                        </td>
                        <td data-label="Cashier / branch">
                            <div>
                                {{ row.cashier }}
                                <p class="text-xs text-muted-foreground">
                                    {{ row.branch }}
                                </p>
                            </div>
                        </td>
                        <td data-label="Net total">
                            {{ shiftMoney(row.net_total, currency) }}
                        </td>
                        <td data-label="Paid / due">
                            <div>
                                {{ shiftMoney(row.net_paid, currency) }}
                                <p class="font-semibold">
                                    Due {{ shiftMoney(row.balance, currency) }}
                                </p>
                            </div>
                        </td>
                        <td data-label="Actions">
                            <Link
                                v-if="canViewSales"
                                :href="`/sales/${row.id}`"
                                class="rounded border px-3 py-2 text-sm text-primary"
                                >View invoice</Link
                            >
                        </td>
                    </tr>
                    <tr v-if="!rows.data.length">
                        <td
                            colspan="6"
                            class="py-10 text-center text-muted-foreground"
                        >
                            No invoices match these filters.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <ShiftPagination :links="rows.links" />
    </section>
</template>
