<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ChevronDown, Eye, Pencil, Power, Search, X } from '@lucide/vue';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import Heading from '@/components/Heading.vue';
import RowActions from '@/components/management/RowActions.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useConfirmationDialog } from '@/composables/useConfirmationDialog';
import { create, edit, index, show } from '@/routes/products';
import { update as updateStatus } from '@/routes/products/status';
import type { Auth } from '@/types';

type Product = {
    id: number;
    name: string;
    product_type: string;
    status: string;
    category: string;
    brand: string | null;
    variants_count: number;
    primary_sku: string | null;
    lowest_variant_price: string | null;
    image_url: string | null;
};
type LinkItem = { url: string | null; label: string; active: boolean };
type Option = { value: string; label: string };
type RelatedOption = { id: number; name: string };
type Suggestion = { id: number; name: string; variant: string; sku: string };

const props = defineProps<{
    products: { data: Product[]; links: LinkItem[] };
    filters: {
        search?: string;
        variant?: string;
        category_id?: number | null;
        brand_id?: number | null;
        product_type?: string;
        condition?: string;
        status?: string;
    };
    suggestions: Suggestion[];
    categories: RelatedOption[];
    brands: RelatedOption[];
    productTypes: Option[];
    conditions: Option[];
    statuses: Option[];
    currency: string;
}>();

const auth = usePage<{ auth: Auth }>().props.auth;
const can = (permission: string): boolean =>
    auth.permissions.includes(permission);
const searchText = ref(props.filters.search ?? '');
const variantText = ref(props.filters.variant ?? '');
const activeFilters = reactive({
    category_id: props.filters.category_id ?? '',
    brand_id: props.filters.brand_id ?? '',
    product_type: props.filters.product_type ?? '',
    condition: props.filters.condition ?? '',
    status: props.filters.status ?? '',
});
const suggestionsOpen = ref(false);
const searching = ref(false);
const filtersOpen = ref(false);
let searchTimer: ReturnType<typeof setTimeout> | undefined;
let skipSearchWatch = false;
const hasAdvancedFilters = computed(() =>
    Boolean(
        activeFilters.category_id ||
        activeFilters.brand_id ||
        activeFilters.product_type ||
        activeFilters.condition ||
        activeFilters.status ||
        variantText.value,
    ),
);
const formatPrice = (price: string | null): string =>
    price === null
        ? '—'
        : new Intl.NumberFormat(undefined, {
              style: 'currency',
              currency: props.currency,
              maximumFractionDigits: 2,
          }).format(Number(price));

const {
    isOpen,
    title,
    description,
    confirmLabel,
    destructive,
    request,
    setOpen,
    confirm,
} = useConfirmationDialog();

function queryFilters(
    search = searchText.value,
): Record<string, string | number> {
    const query: Record<string, string | number> = {};

    if (search.trim()) query.search = search.trim();
    for (const key of [
        'category_id',
        'brand_id',
        'product_type',
        'condition',
        'status',
    ] as const) {
        const value = activeFilters[key];
        if (value) query[key] = value;
    }
    if (variantText.value.trim()) query.variant = variantText.value.trim();

    return query;
}

function visitProducts(search = searchText.value): void {
    router.get(index.url(), queryFilters(search), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['products', 'suggestions', 'filters'],
        onStart: () => {
            searching.value = true;
        },
        onFinish: () => {
            searching.value = false;
        },
    });
}

watch(searchText, (value) => {
    if (skipSearchWatch) return;
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => visitProducts(value), 250);
});

watch(variantText, () => {
    if (skipSearchWatch) return;
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => visitProducts(), 250);
});

watch(
    () => props.filters,
    (filters) => {
        Object.assign(activeFilters, {
            category_id: filters.category_id ?? '',
            brand_id: filters.brand_id ?? '',
            product_type: filters.product_type ?? '',
            condition: filters.condition ?? '',
            status: filters.status ?? '',
        });
        variantText.value = filters.variant ?? '';
    },
    { deep: true },
);

onBeforeUnmount(() => {
    if (searchTimer) clearTimeout(searchTimer);
});

function filterChanged(event: Event): void {
    const target = event.target;

    if (!(target instanceof HTMLSelectElement)) return;
    const key = target.name as keyof typeof activeFilters;
    activeFilters[key] = target.value;
    const nextQuery = queryFilters();

    router.get(index.url(), nextQuery, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['products', 'suggestions', 'filters'],
    });
}

function clearFilters(): void {
    if (searchTimer) clearTimeout(searchTimer);
    skipSearchWatch = true;
    searchText.value = '';
    variantText.value = '';
    Object.assign(activeFilters, {
        category_id: '',
        brand_id: '',
        product_type: '',
        condition: '',
        status: '',
    });
    nextTick(() => {
        skipSearchWatch = false;
    });
    router.get(
        index.url(),
        {},
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['products', 'suggestions', 'filters'],
        },
    );
}

function chooseSuggestion(suggestion: Suggestion): void {
    searchText.value = suggestion.sku || suggestion.name;
    suggestionsOpen.value = false;
}

function closeSuggestionsSoon(): void {
    window.setTimeout(() => {
        suggestionsOpen.value = false;
    }, 150);
}

function typeLabel(value: string): string {
    return (
        props.productTypes.find((type) => type.value === value)?.label ?? value
    );
}

function changeStatus(product: Product): void {
    const status = product.status === 'active' ? 'inactive' : 'active';
    const action = status === 'inactive' ? 'Deactivate' : 'Activate';

    request({
        title: `${action} ${product.name}?`,
        description:
            status === 'inactive'
                ? 'The product and its variants will remain saved, but they will no longer be available for new catalogue workflows.'
                : 'This product will be available in the catalogue again.',
        confirmLabel: action,
        destructive: status === 'inactive',
        onConfirm: () =>
            router.patch(
                updateStatus.url(product.id),
                { status },
                { preserveScroll: true },
            ),
    });
}

defineOptions({
    layout: { breadcrumbs: [{ title: 'Products', href: index() }] },
});
</script>

<template>
    <Head title="Products" />
    <section class="page-shell">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Products"
                description="Manage product details, sellable variants and bundle definitions."
            />
            <Button
                v-if="can('products.create') && can('variants.manage')"
                as-child
            >
                <Link :href="create()">Create product</Link>
            </Button>
        </div>

        <div
            class="relative rounded-xl border border-border/70 bg-card p-3 shadow-sm sm:p-4"
        >
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                <div class="relative min-w-0 flex-1">
                    <Search
                        class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        v-model="searchText"
                        name="search"
                        role="combobox"
                        aria-autocomplete="list"
                        aria-controls="product-search-suggestions"
                        :aria-expanded="
                            suggestionsOpen && searchText.trim().length >= 2
                        "
                        placeholder="Search products, variants, SKU or barcode…"
                        class="pr-10 pl-9"
                        autocomplete="off"
                        @focus="suggestionsOpen = true"
                        @keydown.esc="suggestionsOpen = false"
                        @blur="closeSuggestionsSoon"
                    />
                    <span
                        v-if="searching"
                        class="absolute top-1/2 right-3 -translate-y-1/2 text-xs text-muted-foreground"
                        role="status"
                        >Searching…</span
                    >
                    <button
                        v-else-if="searchText"
                        type="button"
                        class="absolute top-1/2 right-3 -translate-y-1/2 rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        aria-label="Clear search"
                        @click="clearFilters"
                    >
                        <X class="size-4" />
                    </button>

                    <div
                        v-if="suggestionsOpen && searchText.trim().length >= 2"
                        id="product-search-suggestions"
                        role="listbox"
                        aria-label="Product search suggestions"
                        class="absolute inset-x-0 top-[calc(100%+0.4rem)] z-20 overflow-hidden rounded-lg border border-border bg-popover shadow-lg"
                    >
                        <button
                            v-for="suggestion in suggestions"
                            :key="suggestion.id"
                            type="button"
                            role="option"
                            class="flex w-full items-center justify-between gap-4 px-3 py-2 text-left text-sm hover:bg-muted focus-visible:bg-muted focus-visible:outline-none"
                            @mousedown.prevent="chooseSuggestion(suggestion)"
                        >
                            <span class="min-w-0 truncate font-medium"
                                >{{ suggestion.name }}
                                <span
                                    v-if="suggestion.variant"
                                    class="font-normal text-muted-foreground"
                                    >· {{ suggestion.variant }}</span
                                ></span
                            >
                            <span
                                class="shrink-0 text-xs text-muted-foreground"
                                >{{ suggestion.sku }}</span
                            >
                        </button>
                        <p
                            v-if="suggestions.length === 0 && !searching"
                            class="px-3 py-3 text-sm text-muted-foreground"
                        >
                            No matching products.
                        </p>
                    </div>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="shrink-0 justify-between"
                    :aria-expanded="filtersOpen"
                    @click="filtersOpen = !filtersOpen"
                >
                    <span
                        >Filters<span
                            v-if="hasAdvancedFilters"
                            class="ml-1.5 rounded-full bg-primary/10 px-1.5 py-0.5 text-xs text-primary"
                            >active</span
                        ></span
                    >
                    <ChevronDown
                        class="ml-2 size-4 transition-transform"
                        :class="filtersOpen ? 'rotate-180' : ''"
                    />
                </Button>
                <Button
                    v-if="hasAdvancedFilters || searchText"
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="shrink-0"
                    @click="clearFilters"
                    >Clear</Button
                >
            </div>

            <div
                v-if="filtersOpen"
                class="mt-3 grid gap-2 border-t border-border/70 pt-3 sm:grid-cols-2 lg:grid-cols-5"
            >
                <Input
                    v-model="variantText"
                    name="variant"
                    placeholder="Variant / model / storage / colour"
                    aria-label="Search variant details"
                    class="sm:col-span-2 lg:col-span-5"
                />
                <select
                    name="category_id"
                    :value="activeFilters.category_id"
                    aria-label="Filter by category"
                    class="w-full"
                    @change="filterChanged"
                >
                    <option value="">All categories</option>
                    <option
                        v-for="category in categories"
                        :key="category.id"
                        :value="category.id"
                    >
                        {{ category.name }}
                    </option>
                </select>
                <select
                    name="brand_id"
                    :value="activeFilters.brand_id"
                    aria-label="Filter by brand"
                    class="w-full"
                    @change="filterChanged"
                >
                    <option value="">All brands</option>
                    <option
                        v-for="brand in brands"
                        :key="brand.id"
                        :value="brand.id"
                    >
                        {{ brand.name }}
                    </option>
                </select>
                <select
                    name="product_type"
                    :value="activeFilters.product_type"
                    aria-label="Filter by product type"
                    class="w-full"
                    @change="filterChanged"
                >
                    <option value="">All product types</option>
                    <option
                        v-for="type in productTypes"
                        :key="type.value"
                        :value="type.value"
                    >
                        {{ type.label }}
                    </option>
                </select>
                <select
                    name="condition"
                    :value="activeFilters.condition"
                    aria-label="Filter by condition"
                    class="w-full"
                    @change="filterChanged"
                >
                    <option value="">All conditions</option>
                    <option
                        v-for="condition in conditions"
                        :key="condition.value"
                        :value="condition.value"
                    >
                        {{ condition.label }}
                    </option>
                </select>
                <select
                    name="status"
                    :value="activeFilters.status"
                    aria-label="Filter by status"
                    class="w-full"
                    @change="filterChanged"
                >
                    <option value="">All statuses</option>
                    <option
                        v-for="status in statuses"
                        :key="status.value"
                        :value="status.value"
                    >
                        {{ status.label }}
                    </option>
                </select>
            </div>
        </div>

        <div class="table-panel">
            <table class="data-table min-w-[760px]">
                <thead>
                    <tr>
                        <th scope="col">Product</th>
                        <th scope="col">Category / Brand</th>
                        <th scope="col">Type / Variants</th>
                        <th scope="col">From price</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="product in products.data" :key="product.id">
                        <td>
                            <div class="flex min-w-0 items-center gap-3">
                                <img
                                    v-if="product.image_url"
                                    :src="product.image_url"
                                    :alt="`${product.name} image`"
                                    class="size-10 shrink-0 rounded-md border border-border bg-white object-contain p-1"
                                />
                                <div
                                    v-else
                                    class="grid size-10 shrink-0 place-items-center rounded-md bg-muted text-xs font-semibold text-muted-foreground"
                                >
                                    {{ product.name.slice(0, 2).toUpperCase() }}
                                </div>
                                <div class="min-w-0">
                                    <Link
                                        :href="show(product.id)"
                                        class="block truncate font-medium hover:text-primary hover:underline"
                                    >
                                        {{ product.name }}
                                    </Link>
                                    <div
                                        class="truncate text-xs text-muted-foreground"
                                    >
                                        {{ product.primary_sku || 'No SKU' }}
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div>{{ product.category }}</div>
                            <div class="text-muted-foreground">
                                {{ product.brand || 'No brand' }}
                            </div>
                        </td>
                        <td>
                            <div>{{ typeLabel(product.product_type) }}</div>
                            <div class="text-muted-foreground">
                                {{ product.variants_count }} active
                                {{
                                    product.variants_count === 1
                                        ? 'variant'
                                        : 'variants'
                                }}
                            </div>
                        </td>
                        <td>{{ formatPrice(product.lowest_variant_price) }}</td>
                        <td>
                            <Badge
                                :variant="
                                    product.status === 'active'
                                        ? 'success'
                                        : 'secondary'
                                "
                                >{{ product.status }}</Badge
                            >
                        </td>
                        <td class="text-right">
                            <RowActions
                                v-if="
                                    can('products.view') ||
                                    can('products.update') ||
                                    can('products.deactivate')
                                "
                                :label="product.name"
                            >
                                <Button
                                    v-if="can('products.view')"
                                    as-child
                                    variant="outline"
                                    size="icon-sm"
                                    class="row-action"
                                >
                                    <Link
                                        :href="show(product.id)"
                                        :aria-label="`View ${product.name}`"
                                        :title="`View ${product.name}`"
                                    >
                                        <Eye aria-hidden="true" />
                                    </Link>
                                </Button>
                                <Button
                                    v-if="
                                        can('products.update') &&
                                        can('variants.manage')
                                    "
                                    as-child
                                    variant="outline"
                                    size="icon-sm"
                                    class="row-action"
                                >
                                    <Link
                                        :href="edit(product.id)"
                                        :aria-label="`Edit ${product.name}`"
                                        :title="`Edit ${product.name}`"
                                        ><Pencil aria-hidden="true"
                                    /></Link>
                                </Button>
                                <Button
                                    v-if="can('products.deactivate')"
                                    type="button"
                                    variant="outline"
                                    size="icon-sm"
                                    class="row-action"
                                    :aria-label="`${product.status === 'active' ? 'Deactivate' : 'Activate'} ${product.name}`"
                                    :title="`${product.status === 'active' ? 'Deactivate' : 'Activate'} ${product.name}`"
                                    @click="changeStatus(product)"
                                    ><Power aria-hidden="true"
                                /></Button>
                            </RowActions>
                        </td>
                    </tr>
                    <tr v-if="products.data.length === 0">
                        <td
                            colspan="6"
                            class="py-12 text-center text-muted-foreground"
                        >
                            No products found. Try a shorter search or clear a
                            filter.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav class="flex flex-wrap gap-2" aria-label="Pagination">
            <Button
                v-for="link in products.links"
                :key="link.label"
                size="sm"
                :variant="link.active ? 'default' : 'outline'"
                :disabled="!link.url"
                as-child
            >
                <Link
                    :href="link.url ?? '#'"
                    preserve-scroll
                    v-html="link.label"
                />
            </Button>
        </nav>

        <ConfirmActionDialog
            :open="isOpen"
            :title="title"
            :description="description"
            :confirm-label="confirmLabel"
            :destructive="destructive"
            @update:open="setOpen"
            @confirm="confirm"
        />
    </section>
</template>
