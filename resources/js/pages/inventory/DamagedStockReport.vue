<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Printer } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Input } from '@/components/ui/input';
import ShiftPagination from '@/components/shifts/ShiftPagination.vue';
import { damageMoney, damageQuantity } from '@/types/damage';
import type { PageLink } from '@/types/shift';

type Row = {
    id: string;
    product: string;
    variant: string;
    sku: string;
    barcode: string | null;
    model_number: string | null;
    category: string;
    brand: string | null;
    location: string;
    unit: string;
    tracking_mode: string;
    quantity: string;
    devices: number;
    last_damage_at: string;
    value: string | null;
    retail_value: string;
    margin: string | null;
};
type Summary = {
    skus: number;
    locations: number;
    quantity: string;
    devices: number;
    value: string | null;
    retail_value: string;
    margin: string | null;
};
type Group = {
    name: string;
    skus: number;
    quantity: string;
    devices: number;
    value: string | null;
    retail_value: string;
    margin: string | null;
};
type Option = { id: number; name: string };
const props = defineProps<{
    rows:
        | Row[]
        | {
              data: Row[];
              links: PageLink[];
              total: number;
              from: number | null;
              to: number | null;
          };
    printMode: boolean;
    summary: Summary;
    byLocation: Group[];
    locations: Option[];
    categories: Option[];
    brands: Option[];
    filters: {
        search: string;
        location_id: number | string;
        category_id: number | string;
        brand_id: number | string;
        model_number: string;
        tracking_mode: string;
    };
    currency: string;
    canViewCosts: boolean;
    generatedAt: string;
}>();
const filters = ref({
    ...props.filters,
    location_id: String(props.filters.location_id || ''),
    category_id: String(props.filters.category_id || ''),
    brand_id: String(props.filters.brand_id || ''),
});
const reportUrl = '/inventory/reports/damaged-stock';
const reportRows = computed(() =>
    Array.isArray(props.rows) ? props.rows : props.rows.data,
);
const pagination = computed(() =>
    Array.isArray(props.rows) ? null : props.rows,
);
const printReport = (): void => {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(filters.value)) {
        if (value) params.set(key, value);
    }
    params.set('print', '1');
    window.open(`${reportUrl}?${params.toString()}`, '_blank', 'noopener');
};
const money = (value: string | null): string =>
    value === null ? 'Hidden' : damageMoney(value, props.currency);
let timer: ReturnType<typeof setTimeout> | undefined;
watch(
    filters,
    () => {
        if (props.printMode) return;
        if (timer) clearTimeout(timer);
        timer = setTimeout(
            () =>
                router.get(
                    reportUrl,
                    {
                        ...filters.value,
                        search: filters.value.search || undefined,
                        location_id: filters.value.location_id || undefined,
                        category_id: filters.value.category_id || undefined,
                        brand_id: filters.value.brand_id || undefined,
                        model_number: filters.value.model_number || undefined,
                        tracking_mode: filters.value.tracking_mode || undefined,
                    },
                    {
                        preserveState: true,
                        preserveScroll: true,
                        replace: true,
                        only: ['rows', 'summary', 'byLocation', 'filters'],
                    },
                ),
            250,
        );
    },
    { deep: true },
);
onMounted(() => {
    if (props.printMode) setTimeout(() => window.print(), 500);
});
onBeforeUnmount(() => {
    if (timer) clearTimeout(timer);
});
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Damaged stock report', href: reportUrl }],
    },
});
</script>

<template>
    <Head title="Damaged stock report" />
    <section class="page-shell damaged-report">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="Damaged stock report"
                description="Location-level quantities and stock values for items awaiting repair, write-off, or further inspection."
            />
            <button
                v-if="!printMode"
                type="button"
                class="inline-flex h-9 items-center gap-2 rounded-lg bg-primary px-3 text-sm font-medium text-primary-foreground hover:opacity-90"
                @click="printReport"
            >
                <Printer class="size-4" />Print / Save PDF
            </button>
        </div>
        <div
            class="surface-panel grid gap-2 p-3 sm:grid-cols-2 xl:grid-cols-4 print:hidden"
        >
            <Input
                v-model="filters.search"
                placeholder="Product, SKU, barcode, or IMEI"
                aria-label="Search damaged stock"
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
            <Input
                v-model="filters.model_number"
                placeholder="Exact model number"
                aria-label="Model number"
            />
            <select v-model="filters.tracking_mode" aria-label="Stock type">
                <option value="">Quantity and serialized</option>
                <option value="quantity_based">Quantity items</option>
                <option value="serialized">IMEI devices</option>
            </select>
            <p class="flex items-center text-xs text-muted-foreground">
                Generated {{ new Date(generatedAt).toLocaleString() }}
            </p>
        </div>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4 print:grid-cols-4">
            <article class="surface-panel p-3">
                <p class="text-xs text-muted-foreground">Damaged stock lines</p>
                <p class="mt-1 text-xl font-semibold">{{ summary.skus }}</p>
            </article>
            <article class="surface-panel p-3">
                <p class="text-xs text-muted-foreground">
                    Items awaiting action
                </p>
                <p class="mt-1 text-xl font-semibold tabular-nums">
                    {{ damageQuantity(summary.quantity) }} quantity ·
                    {{ summary.devices }} IMEI
                </p>
            </article>
            <article class="surface-panel p-3">
                <p class="text-xs text-muted-foreground">Cost value</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">
                    {{ money(summary.value) }}
                </p>
            </article>
            <article class="surface-panel p-3">
                <p class="text-xs text-muted-foreground">
                    Selling value / potential margin
                </p>
                <p class="mt-1 text-xl font-semibold tabular-nums">
                    {{ money(summary.retail_value) }}
                </p>
                <p class="text-xs text-muted-foreground">
                    Potential margin {{ money(summary.margin) }}
                </p>
            </article>
        </div>
        <section v-if="byLocation.length" class="space-y-2">
            <div>
                <h2 class="font-semibold">Location summary</h2>
                <p class="text-sm text-muted-foreground">
                    Current damaged stock totals for each selected location.
                </p>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <article
                    v-for="group in byLocation"
                    :key="group.name"
                    class="surface-panel p-3"
                >
                    <h3 class="font-semibold">{{ group.name }}</h3>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ group.skus }} product variants ·
                        {{ damageQuantity(group.quantity) }} quantity ·
                        {{ group.devices }} devices
                    </p>
                    <dl class="mt-2 grid grid-cols-2 gap-2 text-sm">
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Cost value
                            </dt>
                            <dd class="font-medium">
                                {{ money(group.value) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Selling value
                            </dt>
                            <dd class="font-medium">
                                {{ money(group.retail_value) }}
                            </dd>
                        </div>
                    </dl>
                </article>
            </div>
        </section>
        <section class="space-y-2">
            <div class="flex items-end justify-between gap-3">
                <div>
                    <h2 class="font-semibold">Damaged stock details</h2>
                    <p class="text-sm text-muted-foreground">
                        Includes location, product identifiers, quantities, and
                        valuation.
                    </p>
                </div>
                <Link
                    href="/inventory/damaged"
                    class="text-sm text-primary underline print:hidden"
                    >Open damaged stock</Link
                >
            </div>
            <div class="table-panel">
                <div class="overflow-x-auto">
                    <table
                        class="data-table responsive-table damaged-report-table"
                    >
                        <thead>
                            <tr>
                                <th>Location</th>
                                <th>Product / identifiers</th>
                                <th>Category / brand</th>
                                <th>Type</th>
                                <th class="text-right">Damaged</th>
                                <th class="text-right">Cost value</th>
                                <th class="text-right">Selling value</th>
                                <th class="text-right">Potential margin</th>
                                <th>Last recorded</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in reportRows" :key="row.id">
                                <td data-label="Location">
                                    {{ row.location }}
                                </td>
                                <td data-label="Product / identifiers">
                                    <span class="font-medium"
                                        >{{ row.product }} ·
                                        {{ row.variant }}</span
                                    >
                                    <div
                                        class="font-mono text-xs text-muted-foreground"
                                    >
                                        SKU {{ row.sku }} · Barcode
                                        {{ row.barcode || '—'
                                        }}<span v-if="row.model_number">
                                            · Model {{ row.model_number }}</span
                                        >
                                    </div>
                                </td>
                                <td data-label="Category / brand">
                                    {{ row.category }}
                                    <div class="text-muted-foreground">
                                        {{ row.brand || 'Unbranded' }}
                                    </div>
                                </td>
                                <td data-label="Type">
                                    {{
                                        row.tracking_mode === 'serialized'
                                            ? 'IMEI devices'
                                            : 'Quantity stock'
                                    }}
                                </td>
                                <td
                                    data-label="Damaged"
                                    class="text-right tabular-nums"
                                >
                                    {{
                                        row.tracking_mode === 'serialized'
                                            ? row.devices
                                            : `${damageQuantity(row.quantity)} ${row.unit}`
                                    }}
                                </td>
                                <td
                                    data-label="Cost value"
                                    class="text-right tabular-nums"
                                >
                                    {{ money(row.value) }}
                                </td>
                                <td
                                    data-label="Selling value"
                                    class="text-right tabular-nums"
                                >
                                    {{ money(row.retail_value) }}
                                </td>
                                <td
                                    data-label="Potential margin"
                                    class="text-right tabular-nums"
                                >
                                    {{ money(row.margin) }}
                                </td>
                                <td data-label="Last recorded">
                                    {{
                                        new Date(
                                            row.last_damage_at,
                                        ).toLocaleString()
                                    }}
                                </td>
                            </tr>
                            <tr v-if="reportRows.length === 0">
                                <td
                                    colspan="9"
                                    class="py-10 text-center text-muted-foreground"
                                >
                                    No damaged stock matches these filters.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div
                v-if="pagination && !printMode"
                class="flex flex-wrap items-center justify-between gap-2"
            >
                <p class="text-sm text-muted-foreground">
                    Showing {{ pagination.from ?? 0 }}–{{
                        pagination.to ?? 0
                    }}
                    of {{ pagination.total }} damaged stock rows. Print opens
                    the complete filtered report.
                </p>
                <ShiftPagination :links="pagination.links" />
            </div>
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
    .damaged-report {
        display: block !important;
        padding: 0 !important;
    }
    .damaged-report > * {
        margin-bottom: 12px;
        break-inside: avoid;
    }
    .damaged-report-table {
        width: 100%;
        font-size: 8px !important;
    }
    .damaged-report-table th,
    .damaged-report-table td {
        padding: 4px !important;
    }
    .damaged-report-table tr {
        break-inside: avoid;
    }
}
</style>
