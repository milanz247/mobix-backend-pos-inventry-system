<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import ReportFilters from '@/components/reports/ReportFilters.vue';
import ReportSummary from '@/components/reports/ReportSummary.vue';
import ShiftPagination from '@/components/shifts/ShiftPagination.vue';
import { shiftDate, shiftMoney } from '@/lib/shift';
type Row = {
    id: number;
    reference: string;
    cashier: string;
    branch: string;
    opened_at: string;
    closed_at: string | null;
    status: string;
    net_sales: string;
    collections: string;
    refunds: string;
    expected: string | null;
    counted: string | null;
    variance: string | null;
};
defineProps<{
    rows: {
        data: Row[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    summary: {
        shifts: number;
        net_sales: string;
        collections: string;
        refunds: string;
        variance: string;
    };
    filters: Record<string, string | number>;
    locations: { id: number; name: string }[];
    cashiers: { id: number; name: string }[];
    currency: string;
    timezone: string;
}>();
</script>
<template>
    <Head title="Cashier shift sales" />
    <section class="page-shell">
        <Heading
            title="Cashier shift sales"
            description="Sales, collections, refunds and drawer differences for each shift. Dates filter when the shift opened."
        /><ReportSummary
            :items="[
                { label: 'Shifts', value: summary.shifts },
                {
                    label: 'Net sales',
                    value: shiftMoney(summary.net_sales, currency),
                },
                {
                    label: 'Collections / refunds',
                    value: `${shiftMoney(summary.collections, currency)} / ${shiftMoney(summary.refunds, currency)}`,
                },
                {
                    label: 'Drawer difference',
                    value: shiftMoney(summary.variance, currency),
                },
            ]"
        /><ReportFilters
            action="/reports/shift-sales"
            :filters="filters"
            :fields="[
                { key: 'date_from', label: 'Opened from', type: 'date' },
                { key: 'date_to', label: 'Opened to', type: 'date' },
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
                    key: 'status',
                    label: 'Status',
                    type: 'select',
                    options: [
                        'open',
                        'closed',
                        'pending_approval',
                        'approved',
                    ].map((x) => ({ value: x, label: x.replaceAll('_', ' ') })),
                },
            ]"
        />
        <div class="table-panel">
            <table class="data-table responsive-table">
                <thead>
                    <tr>
                        <th>Shift / cashier</th>
                        <th>Time / status</th>
                        <th>Net sales</th>
                        <th>Collections / refunds</th>
                        <th>Drawer</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows.data" :key="row.id">
                        <td data-label="Shift">
                            <div>
                                <p class="font-semibold">{{ row.reference }}</p>
                                <p>{{ row.cashier }}</p>
                                <p class="text-xs text-muted-foreground">
                                    {{ row.branch }}
                                </p>
                            </div>
                        </td>
                        <td data-label="Time / status">
                            <div>
                                <p class="text-xs">
                                    {{ shiftDate(row.opened_at, timezone) }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        row.closed_at
                                            ? shiftDate(row.closed_at, timezone)
                                            : 'Still open'
                                    }}
                                </p>
                                <p class="text-xs">
                                    {{ row.status.replaceAll('_', ' ') }}
                                </p>
                            </div>
                        </td>
                        <td data-label="Net sales">
                            {{ shiftMoney(row.net_sales, currency) }}
                        </td>
                        <td data-label="Collections">
                            <div>
                                {{ shiftMoney(row.collections, currency) }}
                                <p class="text-xs text-muted-foreground">
                                    Refunds
                                    {{ shiftMoney(row.refunds, currency) }}
                                </p>
                            </div>
                        </td>
                        <td data-label="Drawer">
                            <div class="text-xs">
                                <p>
                                    Expected
                                    {{
                                        row.expected === null
                                            ? '—'
                                            : shiftMoney(row.expected, currency)
                                    }}
                                </p>
                                <p>
                                    Counted
                                    {{
                                        row.counted === null
                                            ? '—'
                                            : shiftMoney(row.counted, currency)
                                    }}
                                </p>
                                <p class="font-semibold">
                                    Difference
                                    {{
                                        row.variance === null
                                            ? '—'
                                            : shiftMoney(row.variance, currency)
                                    }}
                                </p>
                            </div>
                        </td>
                        <td data-label="Actions">
                            <Link
                                :href="`/shifts/${row.id}`"
                                class="rounded border px-3 py-2 text-sm text-primary"
                                >View shift</Link
                            >
                        </td>
                    </tr>
                    <tr v-if="!rows.data.length">
                        <td
                            colspan="6"
                            class="py-10 text-center text-muted-foreground"
                        >
                            No shifts match these filters.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <ShiftPagination :links="rows.links" />
    </section>
</template>
