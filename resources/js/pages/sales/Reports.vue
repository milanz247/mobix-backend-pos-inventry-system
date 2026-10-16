<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { reports } from '@/routes/sales';

type LocationOption = { id: number; name: string };
type DailyRow = { day: string; sales_count: number; gross_total: string };
type PaymentRow = { method: string; amount: string; change_given: string };
type ProductRow = {
    product_name: string;
    variant_name: string;
    sku: string;
    quantity_sold: string;
    gross_total: string;
};
const props = defineProps<{
    summary: {
        sales_count: number;
        gross_sales: string;
        returns: string;
        net_sales: string;
        payments_received: string;
        refunds: string;
        outstanding: string;
    };
    daily: DailyRow[];
    paymentMethods: PaymentRow[];
    topProducts: ProductRow[];
    locations: LocationOption[];
    filters: {
        date_from: string;
        date_to: string;
        location_id: number | string;
    };
    currency: string;
}>();
const dateFrom = ref(props.filters.date_from);
const dateTo = ref(props.filters.date_to);
const locationId = ref(String(props.filters.location_id || ''));

function applyFilters(): void {
    router.get(
        reports.url({
            query: {
                date_from: dateFrom.value || undefined,
                date_to: dateTo.value || undefined,
                location_id: locationId.value || undefined,
            },
        }),
        {},
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function money(value: string): string {
    const [whole = '0', fraction = '00'] = value.split('.', 2);
    return `${props.currency} ${BigInt(whole || '0').toLocaleString()}.${fraction.padEnd(2, '0').slice(0, 2)}`;
}

const summaryCards = [
    { label: 'Transactions', value: () => String(props.summary.sales_count) },
    { label: 'Gross sales', value: () => money(props.summary.gross_sales) },
    { label: 'Returns', value: () => money(props.summary.returns) },
    { label: 'Net sales', value: () => money(props.summary.net_sales) },
    {
        label: 'Payments received',
        value: () => money(props.summary.payments_received),
    },
    { label: 'Outstanding', value: () => money(props.summary.outstanding) },
];

defineOptions({
    layout: { breadcrumbs: [{ title: 'Sales reports', href: reports() }] },
});
</script>

<template>
    <Head title="Sales reports" />
    <section class="page-shell space-y-5">
        <Heading
            title="Sales reports"
            description="Posted transactions for locations you are authorized to access. Figures are based on the selected sale-date range."
        />
        <form
            class="surface-panel grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_1fr_auto] lg:items-end"
            @submit.prevent="applyFilters"
        >
            <label class="space-y-1 text-sm"
                ><span class="font-medium">From</span
                ><input
                    v-model="dateFrom"
                    type="date"
                    class="h-10 w-full rounded-md border border-input bg-background px-3"
            /></label>
            <label class="space-y-1 text-sm"
                ><span class="font-medium">To</span
                ><input
                    v-model="dateTo"
                    type="date"
                    class="h-10 w-full rounded-md border border-input bg-background px-3"
            /></label>
            <label class="space-y-1 text-sm"
                ><span class="font-medium">Location</span
                ><select
                    v-model="locationId"
                    class="h-10 w-full rounded-md border border-input bg-background px-3"
                >
                    <option value="">All accessible locations</option>
                    <option
                        v-for="location in locations"
                        :key="location.id"
                        :value="String(location.id)"
                    >
                        {{ location.name }}
                    </option>
                </select></label
            >
            <Button type="submit">Apply</Button>
        </form>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <article
                v-for="card in summaryCards"
                :key="card.label"
                class="surface-panel p-4"
            >
                <p class="text-sm text-muted-foreground">{{ card.label }}</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">
                    {{ card.value() }}
                </p>
            </article>
        </div>
        <p class="text-xs text-muted-foreground">
            Net sales = gross sales less returns. Payments received is the
            amount applied to invoices, less refunds; cash change is reported
            separately by payment method. Product rankings use original sold
            quantities before returns. Cost/profit is omitted until return-cost
            treatment is configured.
        </p>
        <div class="grid gap-4 xl:grid-cols-2">
            <section class="table-panel">
                <h2 class="border-b p-4 font-semibold">Daily sales</h2>
                <div class="overflow-x-auto">
                    <table class="data-table min-w-[520px]">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th class="text-right">Transactions</th>
                                <th class="text-right">Gross sales</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in daily" :key="row.day">
                                <td>{{ row.day }}</td>
                                <td class="text-right tabular-nums">
                                    {{ row.sales_count }}
                                </td>
                                <td class="text-right tabular-nums">
                                    {{ money(row.gross_total) }}
                                </td>
                            </tr>
                            <tr v-if="daily.length === 0">
                                <td
                                    colspan="3"
                                    class="py-8 text-center text-muted-foreground"
                                >
                                    No sales in this range.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
            <section class="table-panel">
                <h2 class="border-b p-4 font-semibold">Payments by method</h2>
                <div class="overflow-x-auto">
                    <table class="data-table min-w-[420px]">
                        <thead>
                            <tr>
                                <th>Method</th>
                                <th class="text-right">Applied</th>
                                <th class="text-right">Change</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in paymentMethods" :key="row.method">
                                <td class="capitalize">
                                    {{ row.method.replaceAll('_', ' ') }}
                                </td>
                                <td class="text-right tabular-nums">
                                    {{ money(row.amount) }}
                                </td>
                                <td class="text-right tabular-nums">
                                    {{ money(row.change_given) }}
                                </td>
                            </tr>
                            <tr v-if="paymentMethods.length === 0">
                                <td
                                    colspan="3"
                                    class="py-8 text-center text-muted-foreground"
                                >
                                    No payments in this range.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
            <section class="table-panel xl:col-span-2">
                <h2 class="border-b p-4 font-semibold">
                    Top products by quantity sold
                </h2>
                <div class="overflow-x-auto">
                    <table class="data-table min-w-[600px]">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>SKU</th>
                                <th class="text-right">Quantity sold</th>
                                <th class="text-right">Gross line total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in topProducts"
                                :key="`${row.sku}-${row.variant_name}`"
                            >
                                <td>
                                    {{ row.product_name }} ·
                                    {{ row.variant_name }}
                                </td>
                                <td>{{ row.sku }}</td>
                                <td class="text-right tabular-nums">
                                    {{ row.quantity_sold }}
                                </td>
                                <td class="text-right tabular-nums">
                                    {{ money(row.gross_total) }}
                                </td>
                            </tr>
                            <tr v-if="topProducts.length === 0">
                                <td
                                    colspan="4"
                                    class="py-8 text-center text-muted-foreground"
                                >
                                    No product sales in this range.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </section>
</template>
