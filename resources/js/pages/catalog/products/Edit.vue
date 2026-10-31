<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import ProductForm from '@/components/catalog/ProductForm.vue';
import { index, update } from '@/routes/products';

type Option = { value: string; label: string };
type RelatedOption = { id: number; name: string };
type ComponentOption = {
    id: number;
    name: string;
    sku: string;
    cost_price: string | null;
    product: string;
};
type VariantInput = {
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
    components: { component_variant_id: number; quantity: string }[];
};
type Product = {
    id: number;
    name: string;
    category_id: number;
    brand_id: number | null;
    product_type: string;
    description: string | null;
    image_url: string | null;
    status: string;
    variants: VariantInput[];
};

defineProps<{
    product: Product;
    categories: RelatedOption[];
    brands: RelatedOption[];
    productTypes: Option[];
    conditions: Option[];
    trackingModes: Option[];
    statuses: Option[];
    componentVariants: ComponentOption[];
    currency: string;
    canViewCosts: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Edit product', href: index() }] },
});
</script>

<template>
    <Head :title="`Edit ${product.name}`" />
    <section class="page-shell">
        <Heading
            title="Edit product"
            description="Update product details, variant pricing, and bundle components. Stock and IMEI records belong to future inventory workflows."
        />
        <ProductForm
            :action="update.url(product.id)"
            method="put"
            :product="product"
            :categories="categories"
            :brands="brands"
            :product-types="productTypes"
            :conditions="conditions"
            :tracking-modes="trackingModes"
            :statuses="statuses"
            :component-variants="componentVariants"
            :currency="currency"
            :can-view-costs="canViewCosts"
        />
    </section>
</template>
