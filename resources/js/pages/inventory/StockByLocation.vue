<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { index } from '@/routes/inventory/locations';

type Option = { id: number; name: string; code?: string };
type Stock = {
    id: number;
    product: string;
    variant: string;
    sku: string;
    barcode: string | null;
    product_type: string;
    category: string;
    brand: string | null;
    tracking_mode: string;
    quantity_on_hand: string;
    serialized_in_stock: number;
};
type PageLink = { url: string | null; label: string; active: boolean };
const props = defineProps<{
    stocks: { data: Stock[]; links: PageLink[] };
    locations: Option[];
    selectedLocation: Option;
    categories: Option[];
    brands: Option[];
    filters: {
        location_id: number;
        search: string;
        tracking_mode: string;
        category_id: number | null;
        brand_id: number | null;
    };
}>();
const search = ref(props.filters.search);
const locationId = ref(String(props.filters.location_id));
const tracking = ref(props.filters.tracking_mode);
const category = ref(
    props.filters.category_id ? String(props.filters.category_id) : '',
);
const brand = ref(props.filters.brand_id ? String(props.filters.brand_id) : '');
let timer: ReturnType<typeof setTimeout> | undefined;
watch([search, locationId, tracking, category, brand], () => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(
        () =>
            router.get(
                index.url(),
                {
                    search: search.value || undefined,
                    location_id: locationId.value,
                    tracking_mode: tracking.value || undefined,
                    category_id: category.value || undefined,
                    brand_id: brand.value || undefined,
                },
                {
                    preserveState: true,
                    replace: true,
                    only: ['stocks', 'filters', 'selectedLocation'],
                },
            ),
        250,
    );
});
onBeforeUnmount(() => {
    if (timer) clearTimeout(timer);
});
defineOptions({
    layout: { breadcrumbs: [{ title: 'Stock by location', href: index() }] },
});
</script>
<template>
    <Head title="Stock by location" />
    <section class="page-shell">
        <Heading
            title="Stock by location"
            :description="`Current catalogue stock at ${selectedLocation.name}.`"
        />
        <div class="surface-panel grid gap-2 p-2 md:grid-cols-5">
            <Input
                v-model="search"
                placeholder="Search product, SKU or barcode"
                aria-label="Search inventory"
            /><select v-model="locationId" aria-label="Location">
                <option
                    v-for="item in locations"
                    :key="item.id"
                    :value="item.id"
                >
                    {{ item.name }}
                </option></select
            ><select v-model="tracking">
                <option value="">All tracking types</option>
                <option value="quantity_based">Quantity based</option>
                <option value="serialized">Serialized</option></select
            ><select v-model="category">
                <option value="">All categories</option>
                <option
                    v-for="item in categories"
                    :key="item.id"
                    :value="item.id"
                >
                    {{ item.name }}
                </option></select
            ><select v-model="brand">
                <option value="">All brands</option>
                <option v-for="item in brands" :key="item.id" :value="item.id">
                    {{ item.name }}
                </option>
            </select>
        </div>
        <div class="table-panel">
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Product / variant</th>
                            <th>Category</th>
                            <th>Tracking</th>
                            <th class="text-right">On hand</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="stock in stocks.data" :key="stock.id">
                            <td>
                                <div class="font-medium">
                                    {{ stock.product }}
                                </div>
                                <div class="text-muted-foreground">
                                    {{ stock.variant }} · {{ stock.sku
                                    }}<span v-if="stock.barcode">
                                        · {{ stock.barcode }}</span
                                    >
                                </div>
                            </td>
                            <td>
                                {{ stock.category }}
                                <div class="text-muted-foreground">
                                    {{ stock.brand || 'No brand' }}
                                </div>
                            </td>
                            <td>
                                <Badge variant="secondary">{{
                                    stock.product_type.replaceAll('_', ' ')
                                }}</Badge>
                                <div class="mt-1 text-xs text-muted-foreground">
                                    {{
                                        stock.tracking_mode === 'serialized'
                                            ? 'Serialized / IMEI'
                                            : 'Quantity based'
                                    }}
                                </div>
                            </td>
                            <td
                                class="text-right text-base font-semibold tabular-nums"
                            >
                                {{
                                    stock.tracking_mode === 'serialized'
                                        ? stock.serialized_in_stock
                                        : stock.quantity_on_hand
                                }}
                            </td>
                        </tr>
                        <tr v-if="stocks.data.length === 0">
                            <td
                                colspan="4"
                                class="py-10 text-center text-muted-foreground"
                            >
                                No stock matches these filters.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <nav
                class="flex flex-wrap justify-end gap-1 border-t p-3"
                aria-label="Pagination"
            >
                <Link
                    v-for="link in stocks.links"
                    :key="link.label"
                    :href="link.url || '#'"
                    :class="[
                        'rounded-md px-3 py-1.5 text-sm',
                        link.active
                            ? 'bg-primary text-primary-foreground'
                            : 'hover:bg-muted',
                        !link.url && 'pointer-events-none opacity-40',
                    ]"
                    ><span v-html="link.label"
                /></Link>
            </nav>
        </div>
    </section>
</template>
