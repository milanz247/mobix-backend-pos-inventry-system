<script setup lang="ts">
import { Head, Link, usePage, usePoll } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import ShiftLinks from '@/components/shifts/ShiftLinks.vue';
import ShiftSummaryPanel from '@/components/shifts/ShiftSummary.vue';
import ShiftPayments from '@/components/shifts/ShiftPayments.vue';
import ShiftPagination from '@/components/shifts/ShiftPagination.vue';
import { show } from '@/routes/shifts';
import { shiftDate, shiftMoney } from '@/lib/shift';
import type { Paginated, ShiftDetails } from '@/types/shift';
type Transaction = {
    id: number;
    reference: string | null;
    at: string;
    amount: string | null;
    method: string;
    actor: string;
    status: string;
    description: string | null;
    url: string | null;
};
defineProps<
    ShiftDetails & { section: string; transactions: Paginated<Transaction> }
>();
const page = usePage();
const loading = ref(false);
const sections = [
    { key: 'sales', label: 'Sales' },
    { key: 'payments', label: 'Payments' },
    { key: 'refunds', label: 'Refunds' },
    { key: 'cash_movements', label: 'Cash in/out' },
    { key: 'expenses', label: 'Expenses' },
    { key: 'drops', label: 'Cash drops' },
    { key: 'closing', label: 'Closing / reconciliation' },
];
usePoll(15000, {
    only: ['shift', 'summary', 'transactions', 'events', 'canManage'],
});
</script>
<template>
    <section class="page-shell space-y-6">
        <Head title="Current Shift report" />
        <Heading
            :title="
                shift.closed_at
                    ? 'Shift Report · ' + shift.reference
                    : 'Current Shift · ' + shift.reference
            "
            :description="`${summary.identity?.cashier ?? shift.cashier.name} · ${summary.identity?.location ?? shift.location.name} · ${shift.status.replaceAll('_', ' ')}`"
        />
        <ShiftLinks :shift="shift" :can-manage="canManage" />
        <p class="text-sm text-muted-foreground">
            Opened {{ shiftDate(summary.opened_at ?? shift.opened_at, timezone)
            }}<span v-if="shift.closed_at">
                · Closed
                {{
                    shiftDate(summary.closed_at ?? shift.closed_at, timezone)
                }}</span
            >
        </p>
        <div class="grid gap-5 lg:grid-cols-2">
            <ShiftSummaryPanel
                :summary="summary"
                :currency="currency"
            /><ShiftPayments :summary="summary" :currency="currency" />
        </div>
        <div v-if="shift.closed_at" class="grid gap-3 sm:grid-cols-3">
            <article class="surface-panel p-4">
                <p class="text-sm text-muted-foreground">Saved actual cash</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">
                    {{ shiftMoney(shift.actual_cash, currency) }}
                </p>
            </article>
            <article class="surface-panel p-4">
                <p class="text-sm text-muted-foreground">
                    {{
                        shift.variance === '0.00'
                            ? 'Balanced'
                            : shift.variance?.startsWith('-')
                              ? 'Cash shortage'
                              : 'Cash surplus'
                    }}
                </p>
                <p
                    class="mt-1 text-xl font-semibold tabular-nums"
                    :class="shift.variance !== '0.00' ? 'text-destructive' : ''"
                >
                    {{ shiftMoney(shift.variance, currency) }}
                </p>
            </article>
            <article class="surface-panel p-4">
                <p class="text-sm text-muted-foreground">Manager review</p>
                <p class="mt-1 font-medium">
                    {{
                        shift.approver_name ??
                        (shift.status === 'pending_approval'
                            ? 'Pending approval'
                            : 'No variance approval required')
                    }}
                </p>
            </article>
        </div>
        <section class="table-panel">
            <div class="border-b p-4">
                <h2 class="font-semibold">Individual transactions</h2>
                <p class="mt-1 text-xs leading-5 text-muted-foreground">
                    Sales were opened in this shift. Payments and refunds were
                    recorded in this shift and may relate to earlier sales. Each
                    cash movement is listed separately; reversal pairs cancel in
                    the drawer totals above. Newest entries appear first.
                </p>
            </div>
            <nav
                class="flex flex-wrap gap-2 border-b p-3"
                aria-label="Shift transactions"
            >
                <Link
                    v-for="tab in sections"
                    :key="tab.key"
                    :href="show(shift.id, { query: { section: tab.key } })"
                    preserve-scroll
                    class="rounded-lg px-3 py-2 text-sm font-medium"
                    :class="
                        section === tab.key
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-muted/50 hover:bg-accent'
                    "
                    :aria-current="section === tab.key ? 'page' : undefined"
                    @start="loading = true"
                    @finish="loading = false"
                    >{{ tab.label }}</Link
                >
                <Link
                    :href="show(shift.id)"
                    preserve-scroll
                    class="ml-auto rounded-lg px-3 py-2 text-sm text-muted-foreground hover:bg-accent"
                    >Reset view</Link
                >
            </nav>
            <p
                v-if="loading"
                role="status"
                class="p-3 text-sm text-muted-foreground"
            >
                Loading transactions…
            </p>
            <p
                v-for="(error, field) in page.props.errors"
                :key="field"
                role="alert"
                class="p-3 text-sm text-destructive"
            >
                {{ error }}
            </p>
            <div class="overflow-x-auto">
                <table class="data-table min-w-[850px]" :aria-busy="loading">
                    <thead>
                        <tr>
                            <th>Reference / description</th>
                            <th>Timestamp</th>
                            <th>Payment method</th>
                            <th>Cashier / actor</th>
                            <th class="text-right">Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="transaction in transactions.data"
                            :key="`${transaction.reference}-${transaction.at}`"
                        >
                            <td>
                                <Link
                                    v-if="transaction.url"
                                    :href="transaction.url"
                                    class="font-medium text-primary hover:underline"
                                    >{{ transaction.reference }}</Link
                                ><span v-else class="font-medium">{{
                                    transaction.reference
                                }}</span
                                ><span
                                    class="mt-1 block text-xs text-muted-foreground"
                                    >{{ transaction.description }}</span
                                >
                            </td>
                            <td class="text-xs">
                                {{ shiftDate(transaction.at, timezone) }}
                            </td>
                            <td class="capitalize">
                                {{ transaction.method.replaceAll('_', ' ') }}
                            </td>
                            <td>{{ transaction.actor }}</td>
                            <td class="text-right tabular-nums">
                                {{
                                    transaction.amount === null
                                        ? '—'
                                        : shiftMoney(
                                              transaction.amount,
                                              currency,
                                          )
                                }}
                            </td>
                            <td class="capitalize">
                                {{ transaction.status.replaceAll('_', ' ') }}
                            </td>
                        </tr>
                        <tr v-if="!transactions.data.length">
                            <td
                                colspan="6"
                                class="py-10 text-center text-muted-foreground"
                            >
                                No
                                {{
                                    sections
                                        .find((tab) => tab.key === section)
                                        ?.label.toLowerCase()
                                }}
                                records for this shift.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <ShiftPagination :links="transactions.links" />
        </section>
        <section
            v-if="
                shift.opening_note ||
                shift.closing_note ||
                shift.variance_reason
            "
            class="surface-panel space-y-3 p-4 text-sm"
        >
            <p v-if="shift.opening_note">
                <strong>Opening note:</strong> {{ shift.opening_note }}
            </p>
            <p v-if="shift.closing_note">
                <strong>Closing note:</strong> {{ shift.closing_note }}
            </p>
            <p v-if="shift.variance_reason">
                <strong>Variance reason:</strong> {{ shift.variance_reason }}
            </p>
        </section>
        <section class="surface-panel">
            <h2 class="border-b p-4 font-semibold">Audit trail</h2>
            <ol class="divide-y px-4">
                <li
                    v-for="event in events.data"
                    :key="event.id"
                    class="py-3 text-sm"
                >
                    <strong class="capitalize">{{
                        event.type.replaceAll('_', ' ')
                    }}</strong>
                    · {{ event.actor.name
                    }}<span class="block text-xs text-muted-foreground">{{
                        shiftDate(event.created_at, timezone)
                    }}</span
                    ><span v-if="event.details.reason" class="block text-xs">{{
                        event.details.reason
                    }}</span>
                </li>
            </ol>
            <ShiftPagination :links="events.links" />
        </section>
    </section>
</template>
