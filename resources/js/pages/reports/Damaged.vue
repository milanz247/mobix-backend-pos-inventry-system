<script setup lang="ts">
import ExportCsv from '@/components/reports/ExportCsv.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Printer, ArrowLeft } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import ReportFilters from '@/components/reports/ReportFilters.vue';
import DamagedReportTable from '@/components/reports/DamagedReportTable.vue';
import DamagedGroupTable from '@/components/reports/DamagedGroupTable.vue';
import ShiftPagination from '@/components/shifts/ShiftPagination.vue';
import { Button } from '@/components/ui/button';
import type { Paginated } from '@/types/shift';
import type { DamageRow, DamageSummary, DamageGroup } from '@/types/damage';
import { damageMoney, damageQuantity } from '@/types/damage';
type Option = { id: number; name: string };
const props = defineProps<{
    rows: Paginated<DamageRow>;
    summary: DamageSummary;
    groups: DamageGroup[];
    categoryGroups: Paginated<DamageGroup>;
    branchGroups: Paginated<DamageGroup>;
    view: 'items' | 'category' | 'branch';
    filters: Record<string, string | number>;
    locations: Option[];
    categories: Option[];
    brands: Option[];
    models: string[];
    business: { name: string; currency: string };
    showCosts: boolean;
    canPrint: boolean;
    generatedAt: string;
}>();
const viewUrl = (view: string) =>
    '/reports/damaged-stock?' +
    new URLSearchParams({
        ...Object.fromEntries(
            Object.entries(props.filters).map(([k, v]) => [k, String(v)]),
        ),
        view,
    }).toString();
const printUrl = computed(
    () =>
        '/reports/damaged-stock/print?' +
        new URLSearchParams(
            Object.entries(props.filters)
                .filter(([, value]) => value !== '' && value !== null)
                .map(([key, value]) => [key, String(value)]),
        ).toString(),
);
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Damaged stock report', href: '/reports/damaged-stock' },
        ],
    },
});
</script>
<template>
    <Head title="Damaged stock report" />
    <section class="page-shell space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="Damaged stock report"
                description="Current faulty stock, grouped by SKU and location. Filter by category, brand or model and print the complete list."
            />
            <ExportCsv />
            <div class="flex gap-2">
                <Button as-child variant="outline"
                    ><Link href="/inventory/damaged"
                        ><ArrowLeft /> Manage stock</Link
                    ></Button
                ><Button v-if="canPrint" as-child
                    ><a :href="printUrl" target="_blank" rel="noopener"
                        ><Printer /> Print report</a
                    ></Button
                >
            </div>
        </div>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <article class="surface-panel p-4">
                <p class="text-sm text-muted-foreground">Damaged SKUs</p>
                <p class="mt-2 text-2xl font-semibold">{{ summary.skus }}</p>
            </article>
            <article class="surface-panel p-4">
                <p class="text-sm text-muted-foreground">Quantity items</p>
                <p class="mt-2 text-2xl font-semibold">
                    {{ damageQuantity(summary.quantity) }}
                </p>
            </article>
            <article class="surface-panel p-4">
                <p class="text-sm text-muted-foreground">IMEI devices</p>
                <p class="mt-2 text-2xl font-semibold">{{ summary.devices }}</p>
            </article>
            <article class="surface-panel p-4">
                <p class="text-sm text-muted-foreground">
                    {{ showCosts ? 'Damaged cost value' : 'Locations' }}
                </p>
                <p class="mt-2 text-2xl font-semibold">
                    {{
                        showCosts
                            ? damageMoney(
                                  summary.value ?? '0',
                                  business.currency,
                              )
                            : summary.locations
                    }}
                </p>
            </article>
        </div>
        <ReportFilters
            :preserve="['view']"
            action="/reports/damaged-stock"
            :filters="filters"
            :fields="[
                {
                    key: 'search',
                    label: 'Product, SKU, barcode or model',
                    type: 'search',
                },
                {
                    key: 'location_id',
                    label: 'Location',
                    type: 'select',
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
                    key: 'model_number',
                    label: 'Model number',
                    type: 'select',
                    options: models.map((model) => ({
                        value: model,
                        label: model,
                    })),
                },
                {
                    key: 'tracking_mode',
                    label: 'Stock type',
                    type: 'select',
                    options: [
                        { value: 'quantity_based', label: 'Quantity items' },
                        { value: 'serialized', label: 'IMEI devices' },
                    ],
                },
            ]"
        />
        <nav class="flex flex-wrap gap-2" aria-label="Damaged stock views">
            <Link
                v-for="item in [
                    { key: 'items', label: 'Item view' },
                    { key: 'category', label: 'Category view' },
                    { key: 'branch', label: 'Branch view' },
                ]"
                :key="item.key"
                :href="viewUrl(item.key)"
                class="rounded border px-4 py-2 text-sm"
                :class="
                    view === item.key
                        ? 'bg-primary text-primary-foreground'
                        : 'hover:bg-muted'
                "
                >{{ item.label }}</Link
            >
        </nav>
        <div v-if="view === 'category'" class="table-panel">
            <DamagedGroupTable
                :groups="categoryGroups.data"
                group-by="category"
                :show-costs="showCosts"
                :currency="business.currency"
            /><ShiftPagination :links="categoryGroups.links" />
        </div>
        <div v-else-if="view === 'branch'" class="table-panel">
            <DamagedGroupTable
                :groups="branchGroups.data"
                group-by="branch"
                :show-costs="showCosts"
                :currency="business.currency"
            /><ShiftPagination :links="branchGroups.links" />
        </div>
        <div v-else class="table-panel">
            <div class="flex justify-between gap-3 border-b px-4 py-3">
                <h2 class="font-semibold">Damaged items</h2>
                <span class="text-xs text-muted-foreground"
                    >{{ rows.total }} item / branch rows</span
                >
            </div>
            <DamagedReportTable
                :rows="rows.data"
                :show-costs="showCosts"
                :currency="business.currency"
            /><ShiftPagination :links="rows.links" />
        </div>
        <p class="text-xs text-muted-foreground">
            As of {{ generatedAt }}. Repaired and written-off quantities are
            excluded. Quantity totals may include different units; each row
            shows its unit. Print includes every matching row.
        </p>
    </section>
</template>
