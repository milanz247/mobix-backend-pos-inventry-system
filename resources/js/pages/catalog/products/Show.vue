<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Pencil } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { edit, index } from '@/routes/products';
import type { Auth } from '@/types';

type Option = { value: string; label: string };
type Component = {
    id: number;
    product: string;
    variant: string;
    sku: string;
    quantity: string;
    cost_price: string;
    status: string;
    product_status: string;
};
type Variant = {
    id: number;
    name: string;
    sku: string;
    barcode: string | null;
    model_number: string | null;
    storage_capacity: string | null;
    color: string | null;
    other_attributes: string | null;
    tracking_mode: string;
    unit: string;
    condition: string | null;
    cost_price: string;
    selling_price: string;
    minimum_selling_price: string | null;
    status: string;
    estimated_component_cost: string;
    components: Component[];
};
type Product = {
    id: number;
    name: string;
    product_type: string;
    description: string | null;
    image_url: string | null;
    status: string;
    category: string;
    brand: string | null;
    variants: Variant[];
};

const activityAllowed = (
    usePage().props.auth as { permissions: string[] }
).permissions.includes('product-activity.view');
const props = defineProps<{
    product: Product;
    productTypes: Option[];
    trackingModes: Option[];
    conditions: Option[];
    currency: string;
}>();

const auth = usePage<{ auth: Auth }>().props.auth;
const can = (permission: string): boolean =>
    auth.permissions.includes(permission);
const optionLabel = (options: Option[], value: string | null): string =>
    value === null
        ? '—'
        : (options.find((option) => option.value === value)?.label ?? value);
const formatPrice = (value: string | null): string =>
    value === null
        ? '—'
        : new Intl.NumberFormat(undefined, {
              style: 'currency',
              currency: props.currency,
              maximumFractionDigits: 2,
          }).format(Number(value));

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Products', href: index() },
            { title: 'Product details', href: index() },
        ],
    },
});
</script>

<template>
    <Head :title="product.name" />
    <section class="page-shell">
        <Link
            v-if="activityAllowed"
            :href="`/reports/product-activity?product_id=${product.id}`"
            class="w-fit rounded border px-3 py-2 text-sm text-primary"
            >View product activity</Link
        >
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="product.name"
                description="Catalogue details, sellable variants, pricing, and virtual bundle definition."
            />
            <div class="flex flex-wrap gap-2">
                <Button as-child variant="outline">
                    <Link :href="index()"><ArrowLeft />Products</Link>
                </Button>
                <Button
                    v-if="can('products.update') && can('variants.manage')"
                    as-child
                >
                    <Link :href="edit(product.id)"><Pencil />Edit product</Link>
                </Button>
            </div>
        </div>

        <div class="grid gap-5 lg:grid-cols-[18rem_minmax(0,1fr)]">
            <section
                class="surface-panel overflow-hidden p-4"
                aria-label="Product image"
            >
                <img
                    v-if="product.image_url"
                    :src="product.image_url"
                    :alt="`${product.name} image`"
                    class="aspect-square w-full rounded-lg bg-white object-contain p-3"
                />
                <div
                    v-else
                    class="grid aspect-square w-full place-items-center rounded-lg bg-muted text-4xl font-semibold text-muted-foreground"
                >
                    {{ product.name.slice(0, 2).toUpperCase() }}
                </div>
            </section>

            <section class="surface-panel p-5" aria-labelledby="overview-title">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="overview-title" class="text-base font-semibold">
                        Overview
                    </h2>
                    <Badge
                        :variant="
                            product.status === 'active'
                                ? 'success'
                                : 'secondary'
                        "
                    >
                        {{ product.status }}
                    </Badge>
                </div>
                <dl class="mt-5 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt
                            class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            Type
                        </dt>
                        <dd class="mt-1 font-medium">
                            {{
                                optionLabel(productTypes, product.product_type)
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt
                            class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            Category
                        </dt>
                        <dd class="mt-1 font-medium">{{ product.category }}</dd>
                    </div>
                    <div>
                        <dt
                            class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            Brand
                        </dt>
                        <dd class="mt-1 font-medium">
                            {{ product.brand || 'No brand' }}
                        </dd>
                    </div>
                    <div>
                        <dt
                            class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            Variants
                        </dt>
                        <dd class="mt-1 font-medium">
                            {{ product.variants.length }}
                        </dd>
                    </div>
                </dl>
                <div class="mt-5 border-t border-border/70 pt-5">
                    <h3 class="text-sm font-medium">Description</h3>
                    <p
                        class="mt-1 text-sm whitespace-pre-line text-muted-foreground"
                    >
                        {{
                            product.description ||
                            'No description has been added.'
                        }}
                    </p>
                </div>
            </section>
        </div>

        <section class="space-y-3" aria-labelledby="variants-title">
            <div>
                <h2 id="variants-title" class="text-lg font-semibold">
                    Sellable variants
                </h2>
                <p class="text-sm text-muted-foreground">
                    Default catalogue prices are references. Actual purchase and
                    serialized-device costs belong to future inventory records.
                </p>
            </div>

            <article
                v-for="variant in product.variants"
                :key="variant.id"
                class="surface-panel overflow-hidden"
            >
                <header
                    class="flex flex-wrap items-start justify-between gap-3 border-b border-border/70 px-4 py-3"
                >
                    <div>
                        <h3 class="font-semibold">{{ variant.name }}</h3>
                        <p class="text-sm text-muted-foreground">
                            SKU {{ variant.sku }}
                        </p>
                    </div>
                    <Badge
                        :variant="
                            variant.status === 'active'
                                ? 'success'
                                : 'secondary'
                        "
                    >
                        {{ variant.status }}
                    </Badge>
                </header>

                <dl class="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="text-xs text-muted-foreground">Tracking</dt>
                        <dd class="font-medium">
                            {{
                                optionLabel(
                                    trackingModes,
                                    variant.tracking_mode,
                                )
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Unit</dt>
                        <dd class="font-medium">{{ variant.unit }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Condition</dt>
                        <dd class="font-medium">
                            {{ optionLabel(conditions, variant.condition) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Barcode</dt>
                        <dd class="font-medium">
                            {{ variant.barcode || '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Model</dt>
                        <dd class="font-medium">
                            {{ variant.model_number || '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Storage</dt>
                        <dd class="font-medium">
                            {{ variant.storage_capacity || '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Colour</dt>
                        <dd class="font-medium">{{ variant.color || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            Default buying cost
                        </dt>
                        <dd class="font-medium">
                            {{ formatPrice(variant.cost_price) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            Selling price
                        </dt>
                        <dd class="font-medium">
                            {{ formatPrice(variant.selling_price) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            Minimum selling price
                        </dt>
                        <dd class="font-medium">
                            {{ formatPrice(variant.minimum_selling_price) }}
                        </dd>
                    </div>
                    <div
                        v-if="variant.other_attributes"
                        class="sm:col-span-2 lg:col-span-3"
                    >
                        <dt class="text-xs text-muted-foreground">
                            Other attributes
                        </dt>
                        <dd class="font-medium">
                            {{ variant.other_attributes }}
                        </dd>
                    </div>
                </dl>

                <div
                    v-if="variant.components.length"
                    class="border-t border-border/70 p-4"
                >
                    <div class="flex flex-wrap items-end justify-between gap-2">
                        <div>
                            <h4 class="font-medium">Bundle components</h4>
                            <p class="text-xs text-muted-foreground">
                                Estimated from current default component costs;
                                this is not accounting or actual purchase cost.
                            </p>
                        </div>
                        <div class="text-right">
                            <div class="text-xs text-muted-foreground">
                                Estimated component cost
                            </div>
                            <div class="font-semibold">
                                {{
                                    formatPrice(
                                        variant.estimated_component_cost,
                                    )
                                }}
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 overflow-x-auto">
                        <table class="data-table min-w-[640px]">
                            <thead>
                                <tr>
                                    <th>Component</th>
                                    <th>SKU</th>
                                    <th>Quantity</th>
                                    <th>Default cost</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="component in variant.components"
                                    :key="component.id"
                                >
                                    <td>
                                        {{ component.product }} ·
                                        {{ component.variant }}
                                    </td>
                                    <td>{{ component.sku }}</td>
                                    <td>{{ component.quantity }}</td>
                                    <td>
                                        {{ formatPrice(component.cost_price) }}
                                    </td>
                                    <td>
                                        <Badge
                                            :variant="
                                                component.status === 'active' &&
                                                component.product_status ===
                                                    'active'
                                                    ? 'success'
                                                    : 'destructive'
                                            "
                                        >
                                            {{
                                                component.status === 'active' &&
                                                component.product_status ===
                                                    'active'
                                                    ? 'active'
                                                    : 'unavailable'
                                            }}
                                        </Badge>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </article>
        </section>
    </section>
</template>
