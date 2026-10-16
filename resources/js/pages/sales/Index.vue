<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';
import { Eye, RotateCcw } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
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
                    index.url(),
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
    layout: { breadcrumbs: [{ title: 'Sales history', href: index() }] },
});
</script>

<template>
    <Head title="Sales history" />
    <section class="page-shell space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="Sales history"
                description="Posted invoices, payments, and returns across your authorized locations."
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
        <div class="table-panel">
            <div class="overflow-x-auto">
                <table class="data-table min-w-[980px]">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Date / customer</th>
                            <th>Location / cashier</th>
                            <th>Lines</th>
                            <th>Total</th>
                            <th>Payment</th>
                            <th>Status</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="sale in sales.data" :key="sale.id">
                            <td class="font-semibold">
                                {{ sale.sale_number }}
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
                            <td>{{ sale.lines_count }}</td>
                            <td class="font-medium tabular-nums">
                                {{ money(sale.total) }}
                            </td>
                            <td>
                                <Badge variant="outline">{{
                                    sale.payment_status.replaceAll('_', ' ')
                                }}</Badge>
                            </td>
                            <td>
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
                            <td class="text-right">
                                <div class="flex justify-end gap-2">
                                    <Button
                                        as-child
                                        variant="outline"
                                        size="sm"
                                    >
                                        <Link :href="show(sale.id)">
                                            <Eye /> View
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
                                    >
                                        <Link :href="createReturn(sale.id)">
                                            <RotateCcw /> Return
                                        </Link>
                                    </Button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="sales.data.length === 0">
                            <td
                                colspan="8"
                                class="py-12 text-center text-muted-foreground"
                            >
                                No sales match the current search.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <nav
                class="flex flex-wrap justify-end gap-1 border-t p-3"
                aria-label="Sales pages"
            >
                <Link
                    v-for="link in sales.links"
                    :key="link.label"
                    :href="link.url ?? index.url()"
                    :class="[
                        'rounded px-2 py-1 text-sm',
                        link.active
                            ? 'bg-primary text-primary-foreground'
                            : 'text-muted-foreground',
                    ]"
                    >{{
                        link.label
                            .replace('&laquo; Previous', 'Previous')
                            .replace('Next &raquo;', 'Next')
                    }}</Link
                >
            </nav>
        </div>
    </section>
</template>
