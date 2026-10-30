<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Printer } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import DamagedReportTable from '@/components/reports/DamagedReportTable.vue';
import DamagedGroupTable from '@/components/reports/DamagedGroupTable.vue';
import type { DamageRow, DamageGroup, DamageSummary } from '@/types/damage';
import { damageMoney, damageQuantity } from '@/types/damage';
const props = defineProps<{
    rows: DamageRow[];
    groups: DamageGroup[];
    summary: DamageSummary;
    filters: Record<string, string | number>;
    business: { name: string; currency: string };
    showCosts: boolean;
    generatedAt: string;
    locations: { id: number; name: string }[];
    categories: { id: number; name: string }[];
    brands: { id: number; name: string }[];
}>();
function printReport(): void {
    window.print();
}
function filterName(
    key: string,
    options: { id: number; name: string }[],
): string {
    return (
        options.find((item) => item.id === Number(props.filters[key]))?.name ??
        'All'
    );
}
</script>
<template>
    <Head title="Print damaged stock report" />
    <main
        class="min-h-screen bg-slate-100 p-4 text-slate-950 print:bg-white print:p-0"
    >
        <nav class="mx-auto mb-4 flex max-w-7xl justify-between print:hidden">
            <Button as-child variant="outline"
                ><Link href="/reports/damaged-stock"
                    >Back to report</Link
                ></Button
            ><Button @click="printReport"><Printer /> Print report</Button>
        </nav>
        <article
            class="damage-print mx-auto max-w-7xl bg-white p-6 shadow-sm print:p-0 print:shadow-none"
        >
            <header class="mb-5 border-b pb-4">
                <p class="text-sm">{{ business.name }}</p>
                <h1 class="mt-1 text-2xl font-bold">Damaged stock report</h1>
                <p class="mt-1 text-xs">
                    Generated {{ generatedAt }} · Current damaged stock
                </p>
                <p class="mt-2 text-xs">
                    Location: {{ filterName('location_id', locations) }} ·
                    Category: {{ filterName('category_id', categories) }} ·
                    Brand: {{ filterName('brand_id', brands) }} · Model:
                    {{ filters.model_number || 'All' }} · Search:
                    {{ filters.search || 'All' }}
                </p>
            </header>
            <div class="mb-5 flex flex-wrap gap-6 text-sm">
                <p>
                    SKUs: <strong>{{ summary.skus }}</strong>
                </p>
                <p>
                    Quantity items:
                    <strong>{{ damageQuantity(summary.quantity) }}</strong>
                </p>
                <p>
                    IMEI devices: <strong>{{ summary.devices }}</strong>
                </p>
                <p v-if="showCosts">
                    Cost value:
                    <strong>{{
                        damageMoney(summary.value ?? '0', business.currency)
                    }}</strong>
                </p>
            </div>
            <h2 class="mb-2 font-semibold">
                {{ filters.group_by === 'brand' ? 'Brand' : 'Category' }}
                summary
            </h2>
            <DamagedGroupTable
                :groups="groups"
                :group-by="String(filters.group_by)"
                :show-costs="showCosts"
                :currency="business.currency"
            />
            <h2 class="mt-6 mb-2 font-semibold">
                Complete list · {{ rows.length }} SKU / location rows
            </h2>
            <DamagedReportTable
                :rows="rows"
                :show-costs="showCosts"
                :currency="business.currency"
            />
            <footer class="mt-5 border-t pt-3 text-xs">
                Repaired and written-off items are excluded. Quantity totals may
                combine different units; use the unit shown on each row.
                {{ business.name }}
            </footer>
        </article>
    </main>
</template>
<style scoped>
@page {
    size: A4 landscape;
    margin: 12mm;
}
@media print {
    :global(html),
    :global(body),
    :global(#app) {
        margin: 0 !important;
        padding: 0 !important;
        min-height: 0 !important;
        background: white !important;
    }
    .damage-print {
        max-width: none;
        color: #000;
    }
    :deep(table) {
        min-width: 0;
        table-layout: fixed;
    }
    :deep(thead) {
        background: #f1f5f9;
        color: #334155;
    }
    :deep(th) {
        white-space: normal;
    }
    :deep(td) {
        overflow-wrap: anywhere;
        color: #0f172a;
    }
    :deep(thead) {
        display: table-header-group;
    }
    :deep(tr) {
        break-inside: avoid;
    }
    :deep(th),
    :deep(td) {
        padding: 2.5mm 2mm;
        font-size: 9pt;
        border-bottom: 1px solid #cbd5e1;
    }
    :deep(.text-muted-foreground) {
        color: #475569;
    }
}
</style>
