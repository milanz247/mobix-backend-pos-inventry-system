<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Download, Printer } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Input } from '@/components/ui/input';

type Row = {
    location: string;
    product: string;
    variant: string;
    sku: string;
    category: string;
    brand: string;
    tracking: string;
    quantity: number;
    unit_cost: number;
    unit_selling_price: number;
    cost_value: number;
    retail_value: number;
    margin: number;
};
type Option = { id: number; name: string };
const props = defineProps<{
    rows: Row[];
    canViewCosts: boolean;
    totals: {
        quantity: number;
        cost: number | null;
        retail: number;
        products: number;
    };
    locationSummary: {
        location: string;
        quantity: number;
        cost: number | null;
        retail: number;
        products: number;
    }[];
    pagination: { previous: string | null; next: string | null; total: number };
    locations: Option[];
    categories: Option[];
    brands: Option[];
    filters: {
        search: string;
        location_id: number | null;
        category_id: number | null;
        brand_id: number | null;
    };
    currency: string;
    generatedAt: string;
}>();
const filters = ref({
    ...props.filters,
    location_id: props.filters.location_id
        ? String(props.filters.location_id)
        : '',
    category_id: props.filters.category_id
        ? String(props.filters.category_id)
        : '',
    brand_id: props.filters.brand_id ? String(props.filters.brand_id) : '',
});
const reportUrl = '/inventory/reports/stock-overview';
const excelUrl = computed(() => {
    const query = new URLSearchParams();
    for (const [key, value] of Object.entries(filters.value))
        if (value) query.set(key, String(value));
    return `/inventory/reports/stock-overview/excel${query.size ? `?${query.toString()}` : ''}`;
});
const pdfUrl = computed(() => {
    const query = new URLSearchParams();
    for (const [key, value] of Object.entries(filters.value))
        if (value) query.set(key, String(value));
    return `/inventory/reports/stock-overview/pdf${query.size ? `?${query.toString()}` : ''}`;
});
const formatMoney = (value: number | null): string =>
    new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: props.currency,
        maximumFractionDigits: 2,
    }).format(value ?? 0);
const printReport = (): void => {
    window.open(pdfUrl.value, '_blank', 'noopener');
};
const quantityTotal = computed(() => props.totals.quantity);
const costTotal = computed(() => props.totals.cost ?? 0);
const retailTotal = computed(() => props.totals.retail);
const marginTotal = computed(
    () => props.totals.retail - (props.totals.cost ?? 0),
);
const locationTotals = computed(() => props.locationSummary);
let timer: ReturnType<typeof setTimeout> | undefined;
watch(
    filters,
    () => {
        if (timer) clearTimeout(timer);
        timer = setTimeout(
            () =>
                router.get(
                    reportUrl,
                    {
                        ...filters.value,
                        location_id: filters.value.location_id || undefined,
                        category_id: filters.value.category_id || undefined,
                        brand_id: filters.value.brand_id || undefined,
                        search: filters.value.search || undefined,
                    },
                    {
                        preserveState: true,
                        replace: true,
                        only: [
                            'rows',
                            'filters',
                            'totals',
                            'locationSummary',
                            'pagination',
                            'generatedAt',
                        ],
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
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Stock overview report', href: reportUrl }],
    },
});
</script>

<template>
    <Head title="Stock overview report" />
    <section class="page-shell stock-report-page">
        <div
            class="flex flex-wrap items-start justify-between gap-3 print:hidden"
        >
            <Heading
                title="Stock overview report"
                description="Quantity stock uses current catalogue cost estimates; serialized stock uses recorded device costs. This is not an accounting inventory valuation."
            />
            <div class="flex shrink-0 gap-2">
                <a
                    :href="excelUrl"
                    class="inline-flex h-9 items-center gap-2 rounded-lg border px-3 text-sm font-medium hover:bg-muted"
                    ><Download class="size-4" />Download Excel</a
                >
                <a
                    :href="pdfUrl"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex h-9 items-center gap-2 rounded-lg border px-3 text-sm font-medium hover:bg-muted"
                    ><Download class="size-4" />Print-ready PDF view</a
                >
                <button
                    type="button"
                    class="inline-flex h-9 items-center gap-2 rounded-lg bg-primary px-3 text-sm font-medium text-primary-foreground hover:opacity-90"
                    @click="printReport"
                >
                    <Printer class="size-4" />Print / Save PDF
                </button>
            </div>
        </div>
        <div
            class="surface-panel grid gap-2 p-3 sm:grid-cols-2 xl:grid-cols-5 print:hidden"
        >
            <Input
                v-model="filters.search"
                class="sm:col-span-2 xl:col-span-1"
                placeholder="Search product, SKU, barcode"
                aria-label="Search products"
            />
            <select v-model="filters.location_id" aria-label="Location">
                <option value="">All authorized locations</option>
                <option
                    v-for="location in locations"
                    :key="location.id"
                    :value="String(location.id)"
                >
                    {{ location.name }}
                </option>
            </select>
            <select v-model="filters.category_id" aria-label="Category">
                <option value="">All categories</option>
                <option
                    v-for="category in categories"
                    :key="category.id"
                    :value="String(category.id)"
                >
                    {{ category.name }}
                </option>
            </select>
            <select v-model="filters.brand_id" aria-label="Brand">
                <option value="">All brands</option>
                <option
                    v-for="brand in brands"
                    :key="brand.id"
                    :value="String(brand.id)"
                >
                    {{ brand.name }}
                </option>
            </select>
            <p class="flex items-center text-xs text-muted-foreground">
                Generated {{ new Date(generatedAt).toLocaleString() }}
            </p>
        </div>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4 print:grid-cols-4">
            <article class="surface-panel p-3">
                <p class="text-xs text-muted-foreground">Units on hand</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">
                    {{
                        quantityTotal.toLocaleString(undefined, {
                            maximumFractionDigits: 3,
                        })
                    }}
                </p>
            </article>
            <article v-if="canViewCosts" class="surface-panel p-3">
                <p class="text-xs text-muted-foreground">Stock cost value</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">
                    {{ formatMoney(costTotal) }}
                </p>
            </article>
            <article class="surface-panel p-3">
                <p class="text-xs text-muted-foreground">Stock selling value</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">
                    {{ formatMoney(retailTotal) }}
                </p>
            </article>
            <article v-if="canViewCosts" class="surface-panel p-3">
                <p class="text-xs text-muted-foreground">Potential margin</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">
                    {{ formatMoney(marginTotal) }}
                </p>
            </article>
        </div>
        <section v-if="locationTotals.length > 1" class="space-y-2">
            <div>
                <h2 class="text-base font-semibold">Location summary</h2>
                <p class="text-sm text-muted-foreground">
                    Stock quantity and value at each authorized location.
                </p>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <article
                    v-for="location in locationTotals"
                    :key="location.location"
                    class="surface-panel p-3"
                >
                    <h3 class="font-semibold">{{ location.location }}</h3>
                    <p class="mt-2 text-xs text-muted-foreground">
                        {{ location.products }} stock lines ·
                        {{
                            location.quantity.toLocaleString(undefined, {
                                maximumFractionDigits: 3,
                            })
                        }}
                        units
                    </p>
                    <dl class="mt-2 grid grid-cols-2 gap-2 text-sm">
                        <div v-if="canViewCosts">
                            <dt class="text-xs text-muted-foreground">
                                Cost value
                            </dt>
                            <dd class="font-medium tabular-nums">
                                {{ formatMoney(location.cost) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Selling value
                            </dt>
                            <dd class="font-medium tabular-nums">
                                {{ formatMoney(location.retail) }}
                            </dd>
                        </div>
                    </dl>
                </article>
            </div>
        </section>
        <section class="space-y-2">
            <div class="flex items-end justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold">Stock details</h2>
                    <p class="text-sm text-muted-foreground">
                        {{ pagination.total }} location and product lines · unit
                        costs are weighted averages for serialized devices.
                    </p>
                </div>
                <p class="hidden text-xs text-muted-foreground print:block">
                    Generated {{ new Date(generatedAt).toLocaleString() }}
                </p>
            </div>
            <div class="table-panel">
                <div class="overflow-x-auto">
                    <table
                        class="data-table responsive-table stock-report-table"
                    >
                        <thead>
                            <tr>
                                <th>Location</th>
                                <th>Product / SKU</th>
                                <th>Category / brand</th>
                                <th>Tracking</th>
                                <th class="text-right">Qty</th>
                                <th v-if="canViewCosts" class="text-right">
                                    Unit cost
                                </th>
                                <th class="text-right">Unit price</th>
                                <th v-if="canViewCosts" class="text-right">
                                    Cost value
                                </th>
                                <th class="text-right">Selling value</th>
                                <th v-if="canViewCosts" class="text-right">
                                    Margin
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(row, index) in rows"
                                :key="`${row.location}-${row.sku}-${index}`"
                            >
                                <td data-label="Location">
                                    {{ row.location }}
                                </td>
                                <td data-label="Product / SKU">
                                    <span class="font-medium">{{
                                        row.product
                                    }}</span
                                    ><span v-if="row.variant !== 'Default'">
                                        · {{ row.variant }}</span
                                    >
                                    <div
                                        class="font-mono text-xs text-muted-foreground"
                                    >
                                        {{ row.sku }}
                                    </div>
                                </td>
                                <td data-label="Category / brand">
                                    {{ row.category }}
                                    <div class="text-muted-foreground">
                                        {{ row.brand }}
                                    </div>
                                </td>
                                <td data-label="Tracking">
                                    {{ row.tracking }}
                                </td>
                                <td
                                    data-label="Qty"
                                    class="text-right tabular-nums"
                                >
                                    {{
                                        row.quantity.toLocaleString(undefined, {
                                            maximumFractionDigits: 3,
                                        })
                                    }}
                                </td>
                                <td
                                    v-if="canViewCosts"
                                    data-label="Unit cost"
                                    class="text-right tabular-nums"
                                >
                                    {{ formatMoney(row.unit_cost) }}
                                </td>
                                <td
                                    data-label="Unit price"
                                    class="text-right tabular-nums"
                                >
                                    {{ formatMoney(row.unit_selling_price) }}
                                </td>
                                <td
                                    v-if="canViewCosts"
                                    data-label="Cost value"
                                    class="text-right tabular-nums"
                                >
                                    {{ formatMoney(row.cost_value) }}
                                </td>
                                <td
                                    data-label="Selling value"
                                    class="text-right tabular-nums"
                                >
                                    {{ formatMoney(row.retail_value) }}
                                </td>
                                <td
                                    v-if="canViewCosts"
                                    data-label="Margin"
                                    class="text-right tabular-nums"
                                >
                                    {{ formatMoney(row.margin) }}
                                </td>
                            </tr>
                            <tr v-if="rows.length === 0">
                                <td
                                    colspan="10"
                                    class="py-10 text-center text-muted-foreground"
                                >
                                    No stock matches these filters.
                                </td>
                            </tr>
                        </tbody>
                        <tfoot
                            v-if="rows.length && canViewCosts"
                            class="font-semibold"
                        >
                            <tr>
                                <td colspan="4">Total</td>
                                <td class="text-right tabular-nums">
                                    {{
                                        quantityTotal.toLocaleString(
                                            undefined,
                                            { maximumFractionDigits: 3 },
                                        )
                                    }}
                                </td>
                                <td colspan="2"></td>
                                <td class="text-right tabular-nums">
                                    {{ formatMoney(costTotal) }}
                                </td>
                                <td class="text-right tabular-nums">
                                    {{ formatMoney(retailTotal) }}
                                </td>
                                <td class="text-right tabular-nums">
                                    {{ formatMoney(marginTotal) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <nav class="flex gap-3 print:hidden" aria-label="Report pages">
                <Link v-if="pagination.previous" :href="pagination.previous"
                    >Previous</Link
                ><Link v-if="pagination.next" :href="pagination.next"
                    >Next</Link
                >
            </nav>
        </section>
    </section>
</template>

<style>
@media print {
    @page {
        size: landscape;
        margin: 10mm;
    }
    body {
        background: white !important;
        color: #111827 !important;
    }
    .stock-report-page {
        display: block !important;
        padding: 0 !important;
    }
    .stock-report-page > * {
        margin-bottom: 12px;
        break-inside: avoid;
    }
    .stock-report-table {
        width: 100%;
        font-size: 8px !important;
    }
    .stock-report-table th,
    .stock-report-table td {
        padding: 4px !important;
    }
    .stock-report-table tr {
        break-inside: avoid;
    }
}
</style>
