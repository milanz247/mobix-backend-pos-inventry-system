<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';
import { Eye, RotateCcw } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import ShiftPagination from '@/components/shifts/ShiftPagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { index, show } from '@/routes/sales';
import { create as createReturn } from '@/routes/sales/returns';
import { index as posIndex } from '@/routes/pos';
import type { Auth } from '@/types';

type Sale = {
    id: number;
    sale_number: string;
    source: string;
    posted_at: string;
    customer: string;
    cashier: string;
    location: string;
    lines_count: number;
    total: string;
    paid_total: string;
    outstanding: string;
    payment_status: string;
    status: string;
};
type PageLink = { url: string | null; label: string; active: boolean };
type Option = { id: number; name: string };
type Status = { value: string; label: string };

const props = defineProps<{
    sales: { data: Sale[]; links: PageLink[] };
    locations: Option[];
    cashiers: Option[];
    filters: Record<string, string>;
    paymentStatuses: Status[];
    statuses: Status[];
    currency: string;
    creditOnly?: boolean;
}>();
const auth = usePage<{ auth: Auth }>().props.auth;
const canProcessReturns = auth.permissions.includes('sale-returns.create');
const query = ref({
    search: props.filters.search ?? '',
    date_from: props.filters.date_from ?? '',
    date_to: props.filters.date_to ?? '',
    location_id: String(props.filters.location_id ?? ''),
    cashier_id: props.filters.cashier_id ?? '',
    payment_status: props.filters.payment_status ?? '',
    status: props.filters.status ?? '',
});
let timer: ReturnType<typeof setTimeout> | undefined;
watch(
    query,
    () => {
        if (timer) clearTimeout(timer);
        timer = setTimeout(
            () =>
                router.get(
                    props.creditOnly ? '/sales/credit' : index.url(),
                    {
                        search: query.value.search || undefined,
                        date_from: query.value.date_from || undefined,
                        date_to: query.value.date_to || undefined,
                        location_id: query.value.location_id || undefined,
                        cashier_id: query.value.cashier_id || undefined,
                        payment_status: query.value.payment_status || undefined,
                        status: query.value.status || undefined,
                    },
                    {
                        preserveState: true,
                        preserveScroll: true,
                        replace: true,
                        only: ['sales', 'filters'],
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
    const [whole = '0', fraction = '00'] = value.split('.', 2);
    return `${props.currency} ${BigInt(whole || '0').toLocaleString()}.${fraction.padEnd(2, '0').slice(0, 2)}`;
}
defineOptions({
    layout: { breadcrumbs: [{ title: 'POS & Sales', href: index() }] },
});
</script>

<template>
    <Head :title="creditOnly ? 'Credit sales' : 'Sales history'" />
    <section class="page-shell space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                :title="creditOnly ? 'Credit sales' : 'Sales history'"
                :description="
                    creditOnly
                        ? 'Search credit invoices, record customer payments and settle outstanding balances.'
                        : 'Posted invoices, payments, and returns across your authorized locations.'
                "
            />
            <Button as-child><Link :href="posIndex()">Open POS</Link></Button>
        </div>
        <div class="surface-panel grid gap-2 p-3 sm:grid-cols-2 xl:grid-cols-4">
            <Input
                v-model="query.search"
                class="xl:col-span-2"
                placeholder="Invoice, customer, SKU, barcode or IMEI"
                aria-label="Search sales"
            />
            <Input
                v-model="query.date_from"
                type="date"
                aria-label="From date"
            />
            <Input v-model="query.date_to" type="date" aria-label="To date" />
            <select
                v-model="query.location_id"
                class="h-10 rounded-md border border-input bg-background px-3 text-sm"
                aria-label="Filter by location"
            >
                <option value="">All accessible locations</option>
                <option
                    v-for="location in locations"
                    :key="location.id"
                    :value="String(location.id)"
                >
                    {{ location.name }}
                </option>
            </select>
            <select
                v-model="query.cashier_id"
                class="h-10 rounded-md border border-input bg-background px-3 text-sm"
                aria-label="Filter by cashier"
            >
                <option value="">All cashiers</option>
                <option
                    v-for="cashier in cashiers"
                    :key="cashier.id"
                    :value="String(cashier.id)"
                >
                    {{ cashier.name }}
                </option>
            </select>
            <select
                v-model="query.payment_status"
                class="h-10 rounded-md border border-input bg-background px-3 text-sm"
                aria-label="Filter by payment status"
            >
                <option value="">All payment statuses</option>
                <option
                    v-for="status in paymentStatuses"
                    :key="status.value"
                    :value="status.value"
                >
                    {{ status.label }}
                </option>
            </select>
            <select
                v-model="query.status"
                class="h-10 rounded-md border border-input bg-background px-3 text-sm"
                aria-label="Filter by sale status"
            >
                <option value="">All sale statuses</option>
                <option
                    v-for="status in statuses"
                    :key="status.value"
                    :value="status.value"
                >
                    {{ status.label }}
                </option>
            </select>
        </div>
        <div v-if="creditOnly" class="space-y-3 md:hidden">
            <article
                v-for="sale in sales.data"
                :key="sale.id"
                class="surface-panel space-y-3 p-4"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-semibold break-all">
                            {{ sale.sale_number }}
                        </p>
                        <p class="mt-1 text-sm">{{ sale.customer }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ sale.posted_at }} · {{ sale.location }}
                        </p>
                    </div>
                    <Badge variant="outline" class="shrink-0">{{
                        sale.payment_status.replaceAll('_', ' ')
                    }}</Badge>
                </div>
                <div
                    class="grid grid-cols-2 gap-3 rounded-lg bg-muted/50 p-3 text-sm"
                >
                    <div>
                        <p class="text-xs text-muted-foreground">Paid</p>
                        <p
                            class="min-w-32 font-medium whitespace-nowrap tabular-nums"
                        >
                            {{ money(sale.paid_total) }}
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-muted-foreground">Balance due</p>
                        <p
                            class="font-semibold tabular-nums"
                            :class="
                                sale.outstanding !== '0.00'
                                    ? 'text-amber-700 dark:text-amber-400'
                                    : ''
                            "
                        >
                            {{ money(sale.outstanding) }}
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Button as-child class="h-10 flex-1"
                        ><Link :href="show(sale.id)"
                            ><Eye />
                            {{
                                sale.payment_status === 'paid'
                                    ? 'View payments'
                                    : 'Payments / Settle'
                            }}</Link
                        ></Button
                    ><Button
                        v-if="canProcessReturns && sale.status !== 'returned'"
                        as-child
                        variant="outline"
                        class="h-10"
                        ><Link :href="createReturn(sale.id)"
                            >Return</Link
                        ></Button
                    >
                </div>
            </article>
            <p
                v-if="sales.data.length === 0"
                class="surface-panel p-8 text-center text-sm text-muted-foreground"
            >
                No credit invoices match the current search.
            </p>
        </div>
        <ShiftPagination
            v-if="creditOnly"
            class="md:hidden"
            :links="sales.links"
        />
        <div class="table-panel" :class="creditOnly ? 'hidden md:block' : ''">
            <div
                class="overflow-x-auto"
                :class="creditOnly ? 'hidden md:block' : ''"
            >
                <table
                    class="data-table table-auto"
                    :class="creditOnly ? 'min-w-[1060px]' : 'min-w-[1320px]'"
                >
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Date / customer</th>
                            <th>Location / cashier</th>
                            <th v-if="!creditOnly">Lines</th>
                            <th>Total</th>
                            <th>Paid / Balance due</th>
                            <th v-if="!creditOnly">Payment</th>
                            <th v-if="!creditOnly">Status</th>
                            <th class="min-w-68 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="sale in sales.data" :key="sale.id">
                            <td class="min-w-44 font-semibold">
                                {{ sale.sale_number }}
                                <div v-if="creditOnly" class="mt-2">
                                    <Badge variant="outline">{{
                                        sale.payment_status.replaceAll('_', ' ')
                                    }}</Badge>
                                </div>
                            </td>
                            <td>
                                <div>{{ sale.posted_at }}</div>
                                <div class="text-xs text-muted-foreground">
                                    {{ sale.customer }}
                                </div>
                            </td>
                            <td>
                                <div>{{ sale.location }}</div>
                                <div class="text-xs text-muted-foreground">
                                    {{ sale.cashier }} · {{ sale.source }}
                                </div>
                            </td>
                            <td v-if="!creditOnly">{{ sale.lines_count }}</td>
                            <td
                                class="min-w-32 font-medium whitespace-nowrap tabular-nums"
                            >
                                {{ money(sale.total) }}
                            </td>
                            <td class="min-w-40 tabular-nums">
                                <p
                                    class="text-xs whitespace-nowrap text-muted-foreground"
                                >
                                    Paid {{ money(sale.paid_total) }}
                                </p>
                                <p
                                    class="mt-1 font-semibold whitespace-nowrap"
                                    :class="
                                        creditOnly &&
                                        sale.outstanding !== '0.00'
                                            ? 'text-amber-700 dark:text-amber-400'
                                            : ''
                                    "
                                >
                                    Due {{ money(sale.outstanding) }}
                                </p>
                            </td>
                            <td v-if="!creditOnly">
                                <Badge variant="outline">{{
                                    sale.payment_status.replaceAll('_', ' ')
                                }}</Badge>
                            </td>
                            <td v-if="!creditOnly">
                                <Badge
                                    :variant="
                                        sale.status === 'returned'
                                            ? 'secondary'
                                            : sale.status ===
                                                'partially_returned'
                                              ? 'warning'
                                              : 'success'
                                    "
                                    >{{
                                        sale.status.replaceAll('_', ' ')
                                    }}</Badge
                                >
                            </td>
                            <td class="min-w-68 text-right align-middle">
                                <div
                                    class="flex items-center justify-end gap-2 py-1"
                                >
                                    <Button
                                        as-child
                                        :variant="
                                            creditOnly ? 'default' : 'outline'
                                        "
                                        size="sm"
                                        class="h-9 shrink-0 justify-center"
                                        :class="creditOnly ? 'w-40' : 'w-24'"
                                    >
                                        <Link :href="show(sale.id)">
                                            <Eye v-if="!creditOnly" />
                                            {{
                                                creditOnly
                                                    ? sale.payment_status ===
                                                      'paid'
                                                        ? 'View payments'
                                                        : 'Payments / Settle'
                                                    : 'View'
                                            }}
                                        </Link>
                                    </Button>
                                    <Button
                                        v-if="
                                            canProcessReturns &&
                                            sale.status !== 'returned'
                                        "
                                        as-child
                                        variant="secondary"
                                        size="sm"
                                        class="h-9 shrink-0 justify-center px-2"
                                        :class="creditOnly ? 'w-16' : 'w-24'"
                                    >
                                        <Link :href="createReturn(sale.id)">
                                            <RotateCcw v-if="!creditOnly" />
                                            Return
                                        </Link>
                                    </Button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="sales.data.length === 0">
                            <td
                                :colspan="creditOnly ? 6 : 9"
                                class="py-12 text-center text-muted-foreground"
                            >
                                No sales match the current search.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <ShiftPagination :links="sales.links" />
        </div>
    </section>
</template>

<style scoped>
.data-table th {
    white-space: normal;
}
.data-table td {
    vertical-align: middle;
}
</style>
