<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import ReportFilters from '@/components/reports/ReportFilters.vue';
import ShiftPagination from '@/components/shifts/ShiftPagination.vue';
import { expenses } from '@/routes/reports';
import { shiftDate, shiftMoney } from '@/lib/shift';
import type { Paginated } from '@/types/shift';
type Option = { id: number; name: string };
defineProps<{
    rows: Paginated<{
        id: number;
        reference: string;
        created_at: string;
        location: string;
        category: string | null;
        reason: string;
        amount: string;
        source: string;
        creator: string;
        shift: string;
        status: string;
        url: string;
    }>;
    summary: { total_expenses: string; cash_expenses: string; records: number };
    locations: Option[];
    users: Option[];
    filters: Record<string, string | number>;
    currency: string;
    timezone: string;
}>();
</script>
<template>
    <section class="page-shell space-y-5">
        <Head title="Expenses report" /><Heading
            title="Expenses"
            description="Posted cash-drawer expenses from authorized shifts, with original records and reversal details retained."
        />
        <ReportFilters
            :action="expenses().url"
            :filters="filters"
            :fields="[
                { key: 'date_from', label: 'From', type: 'date' },
                { key: 'date_to', label: 'To', type: 'date' },
                {
                    key: 'location_id',
                    label: 'Location',
                    type: 'select',
                    emptyLabel: 'All accessible locations',
                    options: locations.map((item) => ({
                        value: item.id,
                        label: item.name,
                    })),
                },
                {
                    key: 'user_id',
                    label: 'Created by',
                    type: 'select',
                    options: users.map((item) => ({
                        value: item.id,
                        label: item.name,
                    })),
                },
                { key: 'shift_reference', label: 'Shift reference' },
                {
                    key: 'source',
                    label: 'Payment source',
                    type: 'select',
                    options: [{ value: 'cash_drawer', label: 'Cash drawer' }],
                },
                {
                    key: 'status',
                    label: 'Status',
                    type: 'select',
                    options: [
                        { value: 'completed', label: 'Completed' },
                        { value: 'reversed', label: 'Reversed' },
                    ],
                },
                {
                    key: 'sort',
                    label: 'Sort by',
                    type: 'select',
                    emptyLabel: 'Newest first',
                    options: [
                        { value: 'oldest', label: 'Oldest first' },
                        { value: 'amount', label: 'Largest amount' },
                    ],
                },
            ]"
        />
        <div class="grid gap-3 sm:grid-cols-3">
            <article class="surface-panel p-4">
                <p class="text-sm text-muted-foreground">Net expenses</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">
                    {{ shiftMoney(summary.total_expenses, currency) }}
                </p>
            </article>
            <article class="surface-panel p-4">
                <p class="text-sm text-muted-foreground">
                    Cash affecting drawers
                </p>
                <p class="mt-1 text-xl font-semibold tabular-nums">
                    {{ shiftMoney(summary.cash_expenses, currency) }}
                </p>
            </article>
            <article class="surface-panel p-4">
                <p class="text-sm text-muted-foreground">Matching records</p>
                <p class="mt-1 text-xl font-semibold">{{ summary.records }}</p>
            </article>
        </div>
        <p class="text-xs leading-5 text-muted-foreground">
            Reversed expenses contribute zero to these totals. Each original
            expense appears once; its reversal can be inspected in the detail
            view. Dates refer to the original expense. The application currently
            supports cash-drawer expenses only, without expense categories or
            non-cash expense records.
        </p>
        <section class="table-panel">
            <div class="overflow-x-auto">
                <table class="data-table min-w-[1150px]">
                    <thead>
                        <tr>
                            <th>Reference / time</th>
                            <th>Location</th>
                            <th>Category</th>
                            <th>Description</th>
                            <th class="text-right">Amount</th>
                            <th>Payment source</th>
                            <th>Creator</th>
                            <th>Shift</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows.data" :key="row.id">
                            <td>
                                <Link
                                    :href="row.url"
                                    class="font-medium text-primary hover:underline"
                                    >{{ row.reference }}</Link
                                ><span
                                    class="block text-xs text-muted-foreground"
                                    >{{
                                        shiftDate(row.created_at, timezone)
                                    }}</span
                                >
                            </td>
                            <td>{{ row.location }}</td>
                            <td>{{ row.category ?? '—' }}</td>
                            <td>{{ row.reason }}</td>
                            <td class="text-right tabular-nums">
                                {{ shiftMoney(row.amount, currency) }}
                            </td>
                            <td>{{ row.source }}</td>
                            <td>{{ row.creator }}</td>
                            <td class="text-xs">{{ row.shift }}</td>
                            <td class="capitalize">{{ row.status }}</td>
                        </tr>
                        <tr v-if="!rows.data.length">
                            <td
                                colspan="9"
                                class="py-10 text-center text-muted-foreground"
                            >
                                No expenses match these filters.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <ShiftPagination :links="rows.links" />
        </section>
    </section>
</template>
