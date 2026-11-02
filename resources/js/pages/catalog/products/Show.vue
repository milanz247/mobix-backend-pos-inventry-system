<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Pencil } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import ShiftPagination from '@/components/shifts/ShiftPagination.vue';
import { edit, index } from '@/routes/products';
import type { Auth } from '@/types';
import type { PageLink } from '@/types/shift';

type Option = { value: string; label: string };
type Component = {
    id: number;
    product: string;
    variant: string;
    sku: string;
    quantity: string;
    cost_price: string | null;
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
    cost_price: string | null;
    selling_price: string;
    minimum_selling_price: string | null;
    status: string;
    estimated_component_cost: string | null;
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
type ActivityEntry = {
    id: number;
    action: string;
    subject_type: string;
    subject_id: number | null;
    actor: string;
    location: string;
    created_at: string;
    changes: { field: string; before: unknown; after: unknown }[];
};

const props = defineProps<{
    product: Product;
    productTypes: Option[];
    trackingModes: Option[];
    conditions: Option[];
    currency: string;
    canViewActivity: boolean;
    canManageStorefront: boolean;
    webListing: {
        status: string;
        slug: string;
        public_title: string | null;
        public_description: string | null;
        seo_title: string | null;
        seo_description: string | null;
    } | null;
    activity: {
        data: ActivityEntry[];
        links: PageLink[];
        total: number;
    } | null;
}>();

const publicationForm = useForm({
    status: props.webListing?.status ?? 'draft',
    slug:
        props.webListing?.slug ??
        props.product.name
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-|-$/g, ''),
    public_title: props.webListing?.public_title ?? '',
    public_description: props.webListing?.public_description ?? '',
    seo_title: props.webListing?.seo_title ?? '',
    seo_description: props.webListing?.seo_description ?? '',
});

const savePublication = () =>
    publicationForm.put(`/products/${props.product.id}/web-listing`);

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
const activityValue = (value: unknown): string =>
    value === null || value === undefined
        ? '—'
        : typeof value === 'string'
          ? value
          : JSON.stringify(value);
const activityField = (field: string): string =>
    field.replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase());

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

        <section class="surface-panel p-5" aria-labelledby="storefront-title">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 id="storefront-title" class="text-lg font-semibold">
                        Storefront publication
                    </h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        POS status and website publication are separate.
                    </p>
                </div>
                <Badge
                    :variant="
                        webListing?.status === 'published'
                            ? 'success'
                            : 'secondary'
                    "
                    >{{ webListing?.status ?? 'draft' }}</Badge
                >
            </div>
            <form
                v-if="canManageStorefront"
                class="mt-5 grid gap-4 sm:grid-cols-2"
                @submit.prevent="savePublication"
            >
                <div>
                    <label
                        for="web-status"
                        class="mb-1 block text-sm font-medium"
                        >Publication status</label
                    >
                    <select
                        id="web-status"
                        v-model="publicationForm.status"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    >
                        <option value="draft">Draft</option>
                        <option value="published">Published</option>
                    </select>
                    <p
                        v-if="publicationForm.errors.status"
                        class="mt-1 text-sm text-destructive"
                    >
                        {{ publicationForm.errors.status }}
                    </p>
                </div>
                <div>
                    <label for="web-slug" class="mb-1 block text-sm font-medium"
                        >Public slug</label
                    >
                    <input
                        id="web-slug"
                        v-model="publicationForm.slug"
                        maxlength="180"
                        required
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    />
                    <p
                        v-if="publicationForm.errors.slug"
                        class="mt-1 text-sm text-destructive"
                    >
                        {{ publicationForm.errors.slug }}
                    </p>
                </div>
                <div>
                    <label
                        for="web-title"
                        class="mb-1 block text-sm font-medium"
                        >Public title (optional)</label
                    >
                    <input
                        id="web-title"
                        v-model="publicationForm.public_title"
                        maxlength="180"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    />
                    <p
                        v-if="publicationForm.errors.public_title"
                        class="mt-1 text-sm text-destructive"
                    >
                        {{ publicationForm.errors.public_title }}
                    </p>
                </div>
                <div>
                    <label
                        for="web-seo-title"
                        class="mb-1 block text-sm font-medium"
                        >SEO title (optional)</label
                    >
                    <input
                        id="web-seo-title"
                        v-model="publicationForm.seo_title"
                        maxlength="180"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    />
                    <p
                        v-if="publicationForm.errors.seo_title"
                        class="mt-1 text-sm text-destructive"
                    >
                        {{ publicationForm.errors.seo_title }}
                    </p>
                </div>
                <div class="sm:col-span-2">
                    <label
                        for="web-description"
                        class="mb-1 block text-sm font-medium"
                        >Public description (optional)</label
                    >
                    <textarea
                        id="web-description"
                        v-model="publicationForm.public_description"
                        maxlength="10000"
                        rows="3"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    />
                    <p
                        v-if="publicationForm.errors.public_description"
                        class="mt-1 text-sm text-destructive"
                    >
                        {{ publicationForm.errors.public_description }}
                    </p>
                </div>
                <div class="sm:col-span-2">
                    <label
                        for="web-seo-description"
                        class="mb-1 block text-sm font-medium"
                        >SEO description (optional)</label
                    >
                    <textarea
                        id="web-seo-description"
                        v-model="publicationForm.seo_description"
                        maxlength="320"
                        rows="2"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    />
                    <p
                        v-if="publicationForm.errors.seo_description"
                        class="mt-1 text-sm text-destructive"
                    >
                        {{ publicationForm.errors.seo_description }}
                    </p>
                </div>
                <div class="sm:col-span-2">
                    <Button type="submit" :disabled="publicationForm.processing"
                        >Save storefront listing</Button
                    >
                </div>
            </form>
            <div v-else class="mt-4 text-sm text-muted-foreground">
                <p>
                    Public title: {{ webListing?.public_title || product.name }}
                </p>
                <p>Public slug: {{ webListing?.slug || 'Not set' }}</p>
                <p>
                    Public description:
                    {{
                        webListing?.public_description ||
                        product.description ||
                        'Not set'
                    }}
                </p>
                <p>SEO title: {{ webListing?.seo_title || 'Not set' }}</p>
                <p>
                    SEO description:
                    {{ webListing?.seo_description || 'Not set' }}
                </p>
            </div>
            <div
                v-if="canManageStorefront"
                class="mt-5 border-t border-border/70 pt-5 text-sm"
            >
                <h3 class="font-semibold">Public listing preview</h3>
                <p class="mt-2 text-lg font-semibold">
                    {{ publicationForm.public_title || product.name }}
                </p>
                <p class="mt-1 whitespace-pre-line text-muted-foreground">
                    {{
                        publicationForm.public_description ||
                        product.description ||
                        'No public description.'
                    }}
                </p>
                <p class="mt-2 text-xs text-muted-foreground">
                    /shop/{{ publicationForm.slug || 'slug' }} · SEO:
                    {{
                        publicationForm.seo_title ||
                        publicationForm.public_title ||
                        product.name
                    }}
                </p>
            </div>
        </section>

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
        <section
            v-if="canViewActivity && activity"
            class="space-y-3"
            aria-labelledby="product-activity-title"
        >
            <div>
                <h2 id="product-activity-title" class="text-lg font-semibold">
                    Product activity
                </h2>
                <p class="text-sm text-muted-foreground">
                    Catalogue and variant changes, with the staff member and
                    active location recorded. Stock transactions remain in
                    Inventory movement history.
                </p>
            </div>
            <div v-if="activity.data.length" class="space-y-2">
                <article
                    v-for="entry in activity.data"
                    :key="entry.id"
                    class="surface-panel p-4"
                >
                    <header class="flex flex-wrap justify-between gap-2">
                        <div class="font-medium">
                            {{ entry.action }} {{ entry.subject_type }}
                            <span
                                v-if="entry.subject_type === 'variant'"
                                class="text-muted-foreground"
                                >#{{ entry.subject_id }}</span
                            >
                        </div>
                        <time
                            class="text-sm text-muted-foreground"
                            :datetime="entry.created_at"
                        >
                            {{ new Date(entry.created_at).toLocaleString() }}
                        </time>
                    </header>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ entry.actor }} · {{ entry.location }}
                    </p>
                    <div
                        v-if="entry.changes.length"
                        class="mt-3 overflow-x-auto"
                    >
                        <table class="data-table min-w-[520px]">
                            <thead>
                                <tr>
                                    <th>Field</th>
                                    <th>Before</th>
                                    <th>After</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="change in entry.changes"
                                    :key="change.field"
                                >
                                    <td>{{ activityField(change.field) }}</td>
                                    <td class="max-w-64 break-words">
                                        {{ activityValue(change.before) }}
                                    </td>
                                    <td class="max-w-64 break-words">
                                        {{ activityValue(change.after) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="mt-3 text-sm text-muted-foreground">
                        No tracked fields changed.
                    </p>
                </article>
                <ShiftPagination :links="activity.links" />
            </div>
            <div v-else class="surface-panel p-4 text-sm text-muted-foreground">
                No product activity has been recorded since activity history was
                enabled.
            </div>
        </section>
    </section>
</template>
