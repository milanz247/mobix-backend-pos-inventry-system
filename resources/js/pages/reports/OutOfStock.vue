<script setup lang="ts">
import ExportCsv from '@/components/reports/ExportCsv.vue';
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import ReportFilters from '@/components/reports/ReportFilters.vue';
import ShiftPagination from '@/components/shifts/ShiftPagination.vue';
import { outOfStock } from '@/routes/reports';
import { shiftDate } from '@/lib/shift';
import type { Paginated } from '@/types/shift';
type Option = { id: number; name: string };
defineProps<{
    rows: Paginated<{
        id: number;
        product: string;
        variant: string;
        sku: string;
        barcode: string | null;
        product_type: string;
        category: string;
        brand: string | null;
        location: string;
        available_quantity: string;
        stock_status: string;
        catalogue_status: string;
        last_movement_at: string | null;
        url: string | null;
    }>;
    summary: { zero_stock: number; inconsistent: number };
    locations: Option[];
    categories: Option[];
    brands: Option[];
    productTypes: { value: string; label: string }[];
    filters: Record<string, string | number>;
    timezone: string;
    generatedAt: string;
}>();
</script>
<template>
    <section class="page-shell space-y-5">
        <Head title="Out-of-Stock Products" /><Heading
            title="Out-of-Stock Products"
            description="Current location balances and eligible devices, including variants with no stock record. Negative balances and invalid bundle configurations are flagged separately."
        />
        <ExportCsv />
        <ReportFilters
            :action="outOfStock().url"
            :filters="filters"
            :fields="[
                {
                    key: 'location_id',
                    label: 'Location',
                    type: 'select',
                    emptyLabel: 'Default authorized location',
                    options: locations.map((item) => ({
                        value: item.id,
                        label: item.name,
                    })),
                },
                {
                    key: 'category_id',
                    label: 'Category',
                    type: 'select',
                    options: categories.map((item) => ({
                        value: item.id,
                        label: item.name,
                    })),
                },
                {
                    key: 'brand_id',
                    label: 'Brand',
                    type: 'select',
                    options: brands.map((item) => ({
                        value: item.id,
                        label: item.name,
                    })),
                },
                {
                    key: 'product_type',
                    label: 'Product type',
                    type: 'select',
                    options: productTypes,
                },
                {
                    key: 'search',
                    label: 'Product, SKU or barcode',
                    type: 'search',
                },
                {
                    key: 'stock_status',
                    label: 'Stock status',
                    type: 'select',
                    options: [
                        { value: 'out_of_stock', label: 'Zero stock' },
                        {
                            value: 'inconsistent',
                            label: 'Needs review / negative',
                        },
                    ],
                },
                {
                    key: 'sort',
                    label: 'Sort by',
                    type: 'select',
                    emptyLabel: 'SKU',
                    options: [
                        { value: 'quantity', label: 'Available quantity' },
                        { value: 'last_movement', label: 'Last movement' },
                    ],
                },
            ]"
        />
        <div class="grid gap-3 sm:grid-cols-2">
            <article class="surface-panel p-4">
                <p class="text-sm text-muted-foreground">Zero-stock variants</p>
                <p class="mt-1 text-2xl font-semibold">
                    {{ summary.zero_stock }}
                </p>
            </article>
            <article class="surface-panel p-4">
                <p class="text-sm text-muted-foreground">
                    Stock / component inconsistencies
                </p>
                <p class="mt-1 text-2xl font-semibold text-destructive">
                    {{ summary.inconsistent }}
                </p>
            </article>
        </div>
        <p class="text-xs text-muted-foreground">
            Current stock as of {{ shiftDate(generatedAt, timezone) }}.
            Historical as-of reconstruction is not available. Bundle quantities
            are component-constrained capacities, not additional physical stock.
            Inactive catalogue variants remain visible in this report.
        </p>
        <section class="table-panel">
            <div class="overflow-x-auto">
                <table class="data-table min-w-[1150px]">
                    <thead>
                        <tr>
                            <th>Product / variant</th>
                            <th>SKU / barcode</th>
                            <th>Type</th>
                            <th>Category / brand</th>
                            <th>Location</th>
                            <th class="text-right">Available</th>
                            <th>Status</th>
                            <th>Last stock movement</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows.data" :key="row.id">
                            <td>
                                <Link
                                    v-if="row.url"
                                    :href="row.url"
                                    class="font-medium text-primary hover:underline"
                                    >{{ row.product }}</Link
                                >
                                <span v-else class="font-medium">{{
                                    row.product
                                }}</span
                                ><span
                                    class="block text-xs text-muted-foreground"
                                    >{{ row.variant }} ·
                                    {{ row.catalogue_status }}</span
                                >
                            </td>
                            <td>
                                {{ row.sku
                                }}<span
                                    class="block text-xs text-muted-foreground"
                                    >{{ row.barcode ?? '—' }}</span
                                >
                            </td>
                            <td class="capitalize">{{ row.product_type }}</td>
                            <td>
                                {{ row.category
                                }}<span
                                    class="block text-xs text-muted-foreground"
                                    >{{ row.brand ?? '—' }}</span
                                >
                            </td>
                            <td>{{ row.location }}</td>
                            <td class="text-right tabular-nums">
                                {{ row.available_quantity }}
                            </td>
                            <td
                                :class="
                                    row.stock_status === 'inconsistent'
                                        ? 'font-medium text-destructive'
                                        : ''
                                "
                            >
                                {{
                                    row.stock_status === 'inconsistent'
                                        ? 'Needs review / negative'
                                        : 'Out of stock'
                                }}
                            </td>
                            <td class="text-xs">
                                {{ shiftDate(row.last_movement_at, timezone) }}
                            </td>
                        </tr>
                        <tr v-if="!rows.data.length">
                            <td
                                colspan="8"
                                class="py-10 text-center text-muted-foreground"
                            >
                                No out-of-stock products or stock
                                inconsistencies match these filters.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <ShiftPagination :links="rows.links" />
        </section>
    </section>
</template>
