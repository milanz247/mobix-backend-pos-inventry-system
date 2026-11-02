<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useConfirmationDialog } from '@/composables/useConfirmationDialog';

type Product = {
    id: number;
    name: string;
    sku: string | null;
    category: string | null;
    brand: string | null;
    product_type: string;
    pos_status: string;
    image_url: string | null;
    published: boolean;
    reasons: string[];
    slug: string;
    updated_at: string | null;
};
type Filters = {
    search?: string;
    category?: string;
    brand?: string;
    product_type?: string;
    publication?: string;
};
const props = defineProps<{
    products: {
        data: Product[];
        total: number;
        links: { url: string | null; label: string; active: boolean }[];
    };
    filters: Filters;
    categories: { id: number; name: string }[];
    brands: { id: number; name: string }[];
    productTypes: string[];
}>();
const filters = ref({
    search: '',
    category: '',
    brand: '',
    product_type: '',
    publication: '',
    ...props.filters,
});
const applyFilters = () =>
    router.get('/storefront/products', filters.value, {
        preserveState: true,
        preserveScroll: true,
    });
const form = useForm({ status: '', slug: '' });
const selected = ref<Product | null>(null);
const slugs = ref<Record<number, string>>({});
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
function change(product: Product) {
    selected.value = product;
    form.clearErrors();
    const publishing = !product.published;
    request({
        title: `${publishing ? 'Publish' : 'Unpublish'} ${product.name}?`,
        description: publishing
            ? 'This product and its active variants will become public. Prices and stock remain managed by POS.'
            : 'This product will be hidden from future storefront reads. Existing orders and POS stock are unchanged.',
        confirmLabel: publishing ? 'Publish product' : 'Unpublish product',
        destructive: !publishing,
        onConfirm: () => {
            form.status = publishing ? 'published' : 'draft';
            form.slug = publishing
                ? (slugs.value[product.id] ?? product.slug)
                : product.slug;
            form.put(
                `/storefront/products/${product.id}${window.location.search}`,
                { preserveScroll: true },
            );
        },
    });
}
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Storefront Products', href: '/storefront/products' },
        ],
    },
});
</script>

<template>
    <Head title="Storefront Products" />
    <section class="page-shell space-y-6">
        <Heading
            title="Storefront Products"
            description="Individually publish existing POS products to Ecommerce."
        />
        <p class="text-sm text-muted-foreground">
            POS activation does not publish a product. Publication covers the
            parent product and all active variants, using existing prices and
            advisory stock. Missing images use the storefront placeholder.
        </p>
        <form
            class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
            @submit.prevent="applyFilters"
        >
            <label class="text-sm"
                >Search name, SKU or barcode<Input
                    v-model="filters.search"
                    maxlength="100"
                    placeholder="Search existing products"
            /></label>
            <label class="text-sm"
                >Category<select
                    v-model="filters.category"
                    class="mt-1 w-full rounded-md border bg-background p-2"
                >
                    <option value="">All categories</option>
                    <option
                        v-for="item in categories"
                        :key="item.id"
                        :value="String(item.id)"
                    >
                        {{ item.name }}
                    </option>
                </select></label
            >
            <label class="text-sm"
                >Brand<select
                    v-model="filters.brand"
                    class="mt-1 w-full rounded-md border bg-background p-2"
                >
                    <option value="">All brands</option>
                    <option
                        v-for="item in brands"
                        :key="item.id"
                        :value="String(item.id)"
                    >
                        {{ item.name }}
                    </option>
                </select></label
            >
            <label class="text-sm"
                >Product type<select
                    v-model="filters.product_type"
                    class="mt-1 w-full rounded-md border bg-background p-2"
                >
                    <option value="">All types</option>
                    <option
                        v-for="type in productTypes"
                        :key="type"
                        :value="type"
                    >
                        {{ type }}
                    </option>
                </select></label
            >
            <label class="text-sm"
                >Ecommerce status<select
                    v-model="filters.publication"
                    class="mt-1 w-full rounded-md border bg-background p-2"
                >
                    <option value="">All statuses</option>
                    <option value="published">Published</option>
                    <option value="unpublished">Not Published</option>
                    <option value="ineligible">Ineligible</option>
                </select></label
            >
            <div class="flex items-end gap-2">
                <Button type="submit">Apply filters</Button
                ><Button as-child variant="outline"
                    ><Link href="/storefront/products">Clear</Link></Button
                >
            </div>
        </form>
        <div
            v-if="form.hasErrors"
            role="alert"
            class="rounded-md border border-destructive p-4 text-sm text-destructive"
        >
            <p v-for="(error, field) in form.errors" :key="field">
                {{ selected?.name }}: {{ error }}
            </p>
        </div>
        <p class="text-sm text-muted-foreground">
            {{ products.total }} products
        </p>
        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="p-3">Image</th>
                        <th class="p-3">Product / SKU</th>
                        <th class="p-3">Category / Brand</th>
                        <th class="p-3">Type</th>
                        <th class="p-3">POS Status</th>
                        <th class="p-3">Ecommerce Status</th>
                        <th class="p-3">Storefront Action</th>
                        <th class="p-3">Updated At</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="product in products.data"
                        :key="product.id"
                        class="border-t align-top"
                    >
                        <td class="p-3">
                            <img
                                v-if="product.image_url"
                                :src="product.image_url"
                                alt=""
                                class="size-14 rounded object-contain"
                            /><span v-else class="text-muted-foreground"
                                >No image</span
                            >
                        </td>
                        <td class="p-3">
                            <p class="font-medium">{{ product.name }} <Link :href="`/storefront/products/${product.id}/gallery`" class="ml-2 text-xs underline">Photos</Link></p>
                            <p class="text-muted-foreground">
                                {{ product.sku ?? 'No SKU' }}
                            </p>
                        </td>
                        <td class="p-3">
                            {{ product.category }}
                            <p class="text-muted-foreground">
                                {{ product.brand ?? 'No brand' }}
                            </p>
                        </td>
                        <td class="p-3 capitalize">
                            {{ product.product_type }}
                        </td>
                        <td class="p-3 capitalize">{{ product.pos_status }}</td>
                        <td class="p-3">
                            <p class="font-medium">
                                {{
                                    product.reasons.length
                                        ? 'Ineligible'
                                        : product.published
                                          ? 'Published'
                                          : 'Not Published'
                                }}
                            </p>
                            <p
                                v-if="
                                    product.reasons.length && product.published
                                "
                                class="text-xs"
                            >
                                Publication saved; hidden while ineligible.
                            </p>
                            <p
                                v-for="reason in product.reasons"
                                :key="reason"
                                class="mt-1 text-xs text-muted-foreground"
                            >
                                {{ reason }}
                            </p>
                        </td>
                        <td class="min-w-48 space-y-2 p-3">
                            <label
                                v-if="
                                    !product.published &&
                                    !product.reasons.length
                                "
                                class="block text-xs"
                                >Public slug<Input
                                    :model-value="
                                        slugs[product.id] ?? product.slug
                                    "
                                    maxlength="180"
                                    @update:model-value="
                                        slugs[product.id] = String($event)
                                    " /></label
                            ><Button
                                size="sm"
                                :variant="
                                    product.published ? 'outline' : 'default'
                                "
                                :disabled="
                                    form.processing ||
                                    (!product.published &&
                                        product.reasons.length > 0)
                                "
                                @click="change(product)"
                                >{{
                                    product.published ? 'Unpublish' : 'Publish'
                                }}</Button
                            >
                        </td>
                        <td class="p-3 text-xs">
                            {{
                                product.updated_at
                                    ? new Date(
                                          product.updated_at,
                                      ).toLocaleString()
                                    : 'Never published'
                            }}
                        </td>
                    </tr>
                    <tr v-if="!products.data.length">
                        <td
                            colspan="8"
                            class="p-8 text-center text-muted-foreground"
                        >
                            No matching POS products.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <nav aria-label="Product pages" class="flex flex-wrap gap-2">
            <template v-for="(link, index) in products.links" :key="index"
                ><Button
                    v-if="link.url"
                    as-child
                    size="sm"
                    :variant="link.active ? 'default' : 'outline'"
                    ><Link
                        :href="link.url"
                        preserve-scroll
                        v-html="link.label" /></Button
                ><span
                    v-else
                    class="px-3 py-2 text-sm text-muted-foreground"
                    v-html="link.label"
            /></template>
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
