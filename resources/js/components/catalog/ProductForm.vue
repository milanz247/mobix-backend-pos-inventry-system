<script setup lang="ts">
import type { Auth } from '@/types';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Form, Link, usePage } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/products';

type Option = { value: string; label: string };
type RelatedOption = { id: number; name: string };
type ComponentOption = {
    id: number;
    name: string;
    sku: string;
    cost_price: string | null;
    product: string;
};
type ComponentInput = { component_variant_id: number | ''; quantity: string };
type VariantInput = {
    id: number | null;
    name: string;
    sku: string;
    barcode: string;
    model_number: string;
    storage_capacity: string;
    color: string;
    other_attributes: string;
    tracking_mode: string;
    unit: string;
    condition: string;
    cost_price: string;
    selling_price: string;
    minimum_selling_price: string;
    status: string;
    components: ComponentInput[];
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
    variants: ProductVariantData[];
};
type ProductVariantData = Omit<
    VariantInput,
    | 'barcode'
    | 'model_number'
    | 'storage_capacity'
    | 'color'
    | 'other_attributes'
    | 'condition'
    | 'minimum_selling_price'
> & {
    barcode: string | null;
    model_number: string | null;
    storage_capacity: string | null;
    color: string | null;
    other_attributes: string | null;
    condition: string | null;
    minimum_selling_price: string | null;
};

const props = defineProps<{
    action: string;
    method: 'post' | 'put';
    categories: RelatedOption[];
    brands: RelatedOption[];
    productTypes: Option[];
    conditions: Option[];
    trackingModes: Option[];
    statuses: Option[];
    componentVariants: ComponentOption[];
    currency: string;
    canViewCosts: boolean;
    product?: Product;
}>();

const productType = ref(props.product?.product_type ?? 'simple');
const variants = ref<VariantInput[]>(
    props.product?.variants.length
        ? props.product.variants.map((variant) => ({
              ...variant,
              barcode: variant.barcode ?? '',
              model_number: variant.model_number ?? '',
              storage_capacity: variant.storage_capacity ?? '',
              color: variant.color ?? '',
              other_attributes: variant.other_attributes ?? '',
              condition: variant.condition ?? '',
              minimum_selling_price: variant.minimum_selling_price ?? '',
              components: [...(variant.components ?? [])],
          }))
        : [newVariant()],
);
const imagePreview = ref<string | null>(props.product?.image_url ?? null);
let imageObjectUrl: string | null = null;
const isCombo = computed(() => productType.value === 'combo');
const isService = computed(() => productType.value === 'service');
const normalizedIdentifier = (value: string): string =>
    value.trim().toLocaleLowerCase();
const duplicateBarcodeAt = (index: number): boolean => {
    const value = normalizedIdentifier(variants.value[index]?.barcode ?? '');

    return (
        value !== '' &&
        variants.value.some(
            (variant, otherIndex) =>
                otherIndex !== index &&
                normalizedIdentifier(variant.barcode) === value,
        )
    );
};
const hasDuplicateBarcodes = computed(() =>
    variants.value.some((_, index) => duplicateBarcodeAt(index)),
);

function newVariant(): VariantInput {
    const serialized = productType.value === 'serialized';

    return {
        id: null,
        name: productType.value === 'simple' ? 'Default' : '',
        sku: '',
        barcode: '',
        model_number: '',
        storage_capacity: '',
        color: '',
        other_attributes: '',
        tracking_mode: serialized ? 'serialized' : 'quantity_based',
        unit: serialized ? 'piece' : isService.value ? 'service' : 'piece',
        condition: serialized ? 'new' : '',
        cost_price: '',
        selling_price: '',
        minimum_selling_price: '',
        status: 'active',
        components: [],
    };
}

function addVariant(): void {
    variants.value.push(newVariant());
}

function removeVariant(index: number): void {
    if (variants.value[index]?.id === null && variants.value.length > 1) {
        variants.value.splice(index, 1);
    }
}

function addComponent(variant: VariantInput): void {
    const available = props.componentVariants.find(
        (option) =>
            !variant.components.some(
                (component) => component.component_variant_id === option.id,
            ),
    );

    if (available) {
        variant.components.push({
            component_variant_id: available.id,
            quantity: '1',
        });
    }
}

function selectedImage(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (imageObjectUrl !== null) {
        URL.revokeObjectURL(imageObjectUrl);
        imageObjectUrl = null;
    }

    if (file) {
        imageObjectUrl = URL.createObjectURL(file);
    }

    imagePreview.value = imageObjectUrl ?? props.product?.image_url ?? null;
}

function scaledDecimal(value: string, scale: number): bigint {
    const [whole = '0', fraction = ''] = value.split('.', 2);
    const digits = `${whole || '0'}${fraction.padEnd(scale, '0').slice(0, scale)}`;

    return /^\d+$/.test(digits) ? BigInt(digits) : 0n;
}

function estimatedCost(variant: VariantInput): string {
    const total = variant.components.reduce((sum, component) => {
        const item = props.componentVariants.find(
            (option) => option.id === Number(component.component_variant_id),
        );

        return item
            ? sum +
                  scaledDecimal(item.cost_price ?? '0', 2) *
                      scaledDecimal(component.quantity || '0', 3)
            : sum;
    }, 0n);
    const value = total.toString().padStart(6, '0');

    return `${value.slice(0, -5)}.${value.slice(-5)}`;
}

function formatCurrency(value: string): string {
    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: props.currency,
        maximumFractionDigits: 2,
    }).format(Number(value));
}

function componentLabel(option: ComponentOption): string {
    return `${option.product} · ${option.name} · ${option.sku}`;
}

watch(productType, (type) => {
    if (type === 'serialized') {
        variants.value.forEach((variant) => {
            variant.tracking_mode = 'serialized';
            variant.condition ||= 'new';
        });
    } else if (type === 'simple' || type === 'combo' || type === 'service') {
        variants.value.forEach((variant) => {
            variant.tracking_mode = 'quantity_based';
            variant.condition = '';
            if (type === 'service' && variant.unit === 'piece') {
                variant.unit = 'service';
            } else if (type !== 'service' && variant.unit === 'service') {
                variant.unit = 'piece';
            }
        });
    }
});

onBeforeUnmount(() => {
    if (imageObjectUrl !== null) URL.revokeObjectURL(imageObjectUrl);
});
const canChangeStatus = usePage<{
    auth: Auth;
}>().props.auth.permissions.includes('products.deactivate');
</script>

<template>
    <Form
        :action="action"
        :method="method"
        class="form-section space-y-7"
        v-slot="{ errors, processing, progress }"
    >
        <section class="space-y-5" aria-labelledby="product-details-title">
            <div class="border-b border-border/70 pb-3">
                <h2 id="product-details-title" class="text-base font-semibold">
                    Product details
                </h2>
                <p class="text-sm text-muted-foreground">
                    Enter shared details once. Sellable configurations and
                    prices belong to variants.
                </p>
            </div>

            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="name">Product name</Label>
                    <Input
                        id="name"
                        name="name"
                        required
                        maxlength="180"
                        :default-value="product?.name"
                        autocomplete="off"
                    />
                    <InputError :message="errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="product_type">Product type</Label>
                    <select
                        id="product_type"
                        name="product_type"
                        v-model="productType"
                        required
                        class="w-full"
                    >
                        <option
                            v-for="type in productTypes"
                            :key="type.value"
                            :value="type.value"
                        >
                            {{ type.label }}
                        </option>
                    </select>
                    <InputError :message="errors.product_type" />
                </div>
                <div class="grid gap-2">
                    <Label for="category_id">Category</Label>
                    <select
                        id="category_id"
                        name="category_id"
                        :value="product?.category_id ?? ''"
                        required
                        class="w-full"
                    >
                        <option value="" disabled>Select a category</option>
                        <option
                            v-for="category in categories"
                            :key="category.id"
                            :value="category.id"
                        >
                            {{ category.name }}
                        </option>
                    </select>
                    <InputError :message="errors.category_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="brand_id">Brand (optional)</Label>
                    <select
                        id="brand_id"
                        name="brand_id"
                        :value="product?.brand_id ?? ''"
                        class="w-full"
                    >
                        <option value="">No brand</option>
                        <option
                            v-for="brand in brands"
                            :key="brand.id"
                            :value="brand.id"
                        >
                            {{ brand.name }}
                        </option>
                    </select>
                    <InputError :message="errors.brand_id" />
                </div>
                <div class="grid gap-2 sm:col-span-2 lg:col-span-3">
                    <Label for="description">Description (optional)</Label>
                    <textarea
                        id="description"
                        name="description"
                        rows="3"
                        maxlength="10000"
                        :value="product?.description ?? ''"
                        class="w-full"
                    />
                    <InputError :message="errors.description" />
                </div>
                <div class="grid gap-2">
                    <Label for="status">Product status</Label>
                    <select
                        id="status"
                        name="status"
                        :disabled="!!product && !canChangeStatus"
                        :value="product?.status ?? 'active'"
                        required
                        class="w-full"
                    >
                        <option
                            v-for="status in statuses"
                            :key="status.value"
                            :value="status.value"
                        >
                            {{ status.label }}
                        </option>
                    </select>
                    <input
                        v-if="product && !canChangeStatus"
                        type="hidden"
                        name="status"
                        :value="product.status"
                    />
                    <InputError :message="errors.status" />
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="image">Product image (optional)</Label>
                    <div class="flex flex-wrap items-center gap-4">
                        <img
                            v-if="imagePreview"
                            :src="imagePreview"
                            alt="Product image preview"
                            class="size-16 rounded-md border border-border bg-white object-contain p-1"
                        />
                        <div
                            v-else
                            class="grid size-16 place-items-center rounded-md border border-dashed border-border text-xs text-muted-foreground"
                        >
                            No image
                        </div>
                        <input
                            id="image"
                            name="image"
                            type="file"
                            accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                            class="block min-w-0 flex-1 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-muted file:px-3 file:py-2 file:text-sm file:font-medium"
                            @change="selectedImage"
                        />
                    </div>
                    <InputError :message="errors.image" />
                </div>
            </div>
        </section>

        <section class="space-y-5" aria-labelledby="variants-title">
            <div
                class="flex flex-wrap items-start justify-between gap-3 border-b border-border/70 pb-3"
            >
                <div>
                    <h2 id="variants-title" class="text-base font-semibold">
                        Sellable variants
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Every variant receives a secure SKU automatically and
                        has its own barcode, tracking mode and default prices.
                    </p>
                </div>
                <Button
                    v-if="productType === 'variable'"
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="addVariant"
                        >Add variant</Button
                >
            </div>
            <InputError :message="errors.variants" />

            <article
                v-for="(variant, variantIndex) in variants"
                :key="variant.id ?? `new-${variantIndex}`"
                class="rounded-xl border border-border/70 bg-background p-4 sm:p-5"
            >
                <div class="mb-4 flex items-center justify-between gap-3">
                    <h3 class="text-sm font-semibold">
                        {{
                            isCombo
                                ? 'Bundle variant'
                                : productType === 'simple'
                                  ? 'Default variant'
                                  : `Variant ${variantIndex + 1}`
                        }}
                    </h3>
                    <Button
                        v-if="variant.id"
                        type="button"
                        size="sm"
                        :variant="variant.status === 'active' ? 'outline' : 'secondary'"
                        @click="variant.status = variant.status === 'active' ? 'inactive' : 'active'"
                    >
                        {{ variant.status === 'active' ? 'Deactivate variant' : 'Reactivate variant' }}
                    </Button>
                    <Button
                        v-if="
                            productType !== 'combo' &&
                            variant.id === null &&
                            variants.length > 1
                        "
                        type="button"
                        variant="ghost"
                        size="sm"
                        @click="removeVariant(variantIndex)"
                        >Remove</Button
                    >
                    <input
                        v-if="variant.id"
                        type="hidden"
                        :name="`variants[${variantIndex}][id]`"
                        :value="variant.id"
                    />
                </div>

                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="grid gap-2">
                        <Label :for="`variant-name-${variantIndex}`"
                            >Variant label</Label
                        >
                        <Input
                            :id="`variant-name-${variantIndex}`"
                            :name="`variants[${variantIndex}][name]`"
                            v-model="variant.name"
                            maxlength="150"
                            required
                            placeholder="e.g. 128 GB · Black"
                        />
                        <InputError
                            :message="errors[`variants.${variantIndex}.name`]"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label :for="`variant-sku-${variantIndex}`">SKU</Label>
                        <Input
                            v-if="variant.id"
                            :id="`variant-sku-${variantIndex}`"
                            :model-value="variant.sku"
                            readonly
                            aria-readonly="true"
                            class="bg-muted/60 font-mono"
                        />
                        <div
                            v-else
                            :id="`variant-sku-${variantIndex}`"
                            class="flex min-h-9 items-center rounded-md border border-dashed border-border bg-muted/40 px-3 font-mono text-sm text-muted-foreground"
                            role="status"
                        >
                            Generated securely after saving
                        </div>
                        <p class="text-xs text-muted-foreground">
                            SKUs are assigned by the system and cannot be
                            changed manually.
                        </p>
                    </div>
                    <div class="grid gap-2">
                        <Label :for="`variant-barcode-${variantIndex}`"
                            >Barcode (optional)</Label
                        >
                        <Input
                            :id="`variant-barcode-${variantIndex}`"
                            :name="`variants[${variantIndex}][barcode]`"
                            v-model="variant.barcode"
                            maxlength="64"
                            autocomplete="off"
                        />
                        <InputError
                            :message="
                                errors[`variants.${variantIndex}.barcode`]
                            "
                        />
                        <p
                            v-if="duplicateBarcodeAt(variantIndex)"
                            class="text-sm text-destructive"
                        >
                            This barcode is duplicated in the form.
                        </p>
                    </div>
                    <div class="grid gap-2">
                        <Label :for="`variant-status-${variantIndex}`"
                            >Variant status</Label
                        >
                        <select
                            :id="`variant-status-${variantIndex}`"
                            :name="`variants[${variantIndex}][status]`"
                            v-model="variant.status"
                            class="w-full"
                        >
                            <option
                                v-for="status in statuses"
                                :key="status.value"
                                :value="status.value"
                            >
                                {{ status.label }}
                            </option>
                        </select>
                    </div>
                    <div class="grid gap-2">
                        <Label :for="`model-${variantIndex}`"
                            >Model number (optional)</Label
                        >
                        <Input
                            :id="`model-${variantIndex}`"
                            :name="`variants[${variantIndex}][model_number]`"
                            v-model="variant.model_number"
                            maxlength="150"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label :for="`storage-${variantIndex}`"
                            >Storage (optional)</Label
                        >
                        <Input
                            :id="`storage-${variantIndex}`"
                            :name="`variants[${variantIndex}][storage_capacity]`"
                            v-model="variant.storage_capacity"
                            maxlength="40"
                            placeholder="128 GB"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label :for="`color-${variantIndex}`"
                            >Colour (optional)</Label
                        >
                        <Input
                            :id="`color-${variantIndex}`"
                            :name="`variants[${variantIndex}][color]`"
                            v-model="variant.color"
                            maxlength="80"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label :for="`tracking-${variantIndex}`"
                            >Tracking mode</Label
                        >
                        <select
                            :id="`tracking-${variantIndex}`"
                            :name="`variants[${variantIndex}][tracking_mode]`"
                            v-model="variant.tracking_mode"
                            :disabled="
                                productType === 'simple' ||
                                productType === 'serialized' ||
                                isCombo
                            "
                            class="w-full"
                        >
                            <option
                                v-for="mode in trackingModes"
                                :key="mode.value"
                                :value="mode.value"
                            >
                                {{ mode.label }}
                            </option>
                        </select>
                        <input
                            v-if="
                                productType === 'simple' ||
                                productType === 'serialized' ||
                                isCombo
                            "
                            type="hidden"
                            :name="`variants[${variantIndex}][tracking_mode]`"
                            :value="variant.tracking_mode"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label :for="`unit-${variantIndex}`">Unit</Label>
                        <Input
                            :id="`unit-${variantIndex}`"
                            :name="`variants[${variantIndex}][unit]`"
                            v-model="variant.unit"
                            list="product-unit-suggestions"
                            maxlength="24"
                            required
                            placeholder="piece"
                        />
                        <datalist id="product-unit-suggestions">
                            <option v-for="unit in ['piece', 'pair', 'set', 'box', 'pack', 'meter', 'ml', 'gram', 'kg', 'hour', 'service']" :key="unit" :value="unit" />
                        </datalist>
                        <InputError
                            :message="errors[`variants.${variantIndex}.unit`]"
                        />
                    </div>
                    <div
                        v-if="variant.tracking_mode === 'serialized'"
                        class="grid gap-2"
                    >
                        <Label :for="`condition-${variantIndex}`"
                            >Condition</Label
                        >
                        <select
                            :id="`condition-${variantIndex}`"
                            :name="`variants[${variantIndex}][condition]`"
                            v-model="variant.condition"
                            class="w-full"
                        >
                            <option value="" disabled>Select condition</option>
                            <option
                                v-for="condition in conditions"
                                :key="condition.value"
                                :value="condition.value"
                            >
                                {{ condition.label }}
                            </option>
                        </select>
                        <InputError
                            :message="
                                errors[`variants.${variantIndex}.condition`]
                            "
                        />
                    </div>
                    <input
                        v-else
                        type="hidden"
                        :name="`variants[${variantIndex}][condition]`"
                        value=""
                    />
                    <div v-if="canViewCosts" class="grid gap-2">
                        <Label :for="`cost-${variantIndex}`"
                            >Default buying / cost price</Label
                        >
                        <Input
                            :id="`cost-${variantIndex}`"
                            :name="`variants[${variantIndex}][cost_price]`"
                            v-model="variant.cost_price"
                            type="number"
                            min="0"
                            max="9999999999.99"
                            step="0.01"
                            inputmode="decimal"
                            required
                        />
                        <InputError
                            :message="
                                errors[`variants.${variantIndex}.cost_price`]
                            "
                        />
                    </div>
                    <div v-else class="grid gap-2">
                        <Label>Buying cost</Label>
                        <div class="rounded-md border border-dashed bg-muted/30 px-3 py-2 text-sm text-muted-foreground">
                            Cost is managed by a user with cost access.
                        </div>
                    </div>
                    <div class="grid gap-2">
                        <Label :for="`selling-${variantIndex}`"
                            >Selling price</Label
                        >
                        <Input
                            :id="`selling-${variantIndex}`"
                            :name="`variants[${variantIndex}][selling_price]`"
                            v-model="variant.selling_price"
                            type="number"
                            min="0"
                            max="9999999999.99"
                            step="0.01"
                            inputmode="decimal"
                            required
                        />
                        <InputError
                            :message="
                                errors[`variants.${variantIndex}.selling_price`]
                            "
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label :for="`minimum-${variantIndex}`"
                            >Minimum selling price (optional)</Label
                        >
                        <Input
                            :id="`minimum-${variantIndex}`"
                            :name="`variants[${variantIndex}][minimum_selling_price]`"
                            v-model="variant.minimum_selling_price"
                            type="number"
                            min="0"
                            max="9999999999.99"
                            step="0.01"
                            inputmode="decimal"
                        />
                        <InputError
                            :message="
                                errors[
                                    `variants.${variantIndex}.minimum_selling_price`
                                ]
                            "
                        />
                    </div>
                    <div class="grid gap-2 sm:col-span-2 xl:col-span-4">
                        <Label :for="`other-attributes-${variantIndex}`"
                            >Other variant details (optional)</Label
                        >
                        <Input
                            :id="`other-attributes-${variantIndex}`"
                            :name="`variants[${variantIndex}][other_attributes]`"
                            v-model="variant.other_attributes"
                            maxlength="1000"
                            placeholder="e.g. 5G, dual SIM, global version"
                        />
                    </div>
                </div>

                <section
                    v-if="isCombo"
                    class="mt-6 space-y-4 border-t border-border/70 pt-5"
                    :aria-labelledby="`components-title-${variantIndex}`"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div>
                            <h4
                                :id="`components-title-${variantIndex}`"
                                class="text-sm font-semibold"
                            >
                                Bundle components
                            </h4>
                            <p class="text-sm text-muted-foreground">
                                This bundle has no separate stock. Future
                                checkout will consume these component variants.
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            :disabled="
                                variant.components.length >=
                                componentVariants.length
                            "
                            @click="addComponent(variant)"
                            >Add component</Button
                        >
                    </div>
                    <InputError
                        :message="errors[`variants.${variantIndex}.components`]"
                    />
                    <div
                        v-for="(
                            component, componentIndex
                        ) in variant.components"
                        :key="componentIndex"
                        class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_10rem_auto]"
                    >
                        <div class="grid gap-2">
                            <Label
                                :for="`component-${variantIndex}-${componentIndex}`"
                                >Component variant</Label
                            >
                            <select
                                :id="`component-${variantIndex}-${componentIndex}`"
                                :name="`variants[${variantIndex}][components][${componentIndex}][component_variant_id]`"
                                v-model="component.component_variant_id"
                                class="w-full"
                                required
                            >
                                <option value="" disabled>
                                    Select component
                                </option>
                                <option
                                    v-for="option in componentVariants"
                                    :key="option.id"
                                    :value="option.id"
                                    :disabled="
                                        variant.components.some(
                                            (other, otherIndex) =>
                                                otherIndex !== componentIndex &&
                                                Number(
                                                    other.component_variant_id,
                                                ) === option.id,
                                        )
                                    "
                                >
                                    {{ componentLabel(option) }}
                                </option>
                            </select>
                            <InputError
                                :message="
                                    errors[
                                        `variants.${variantIndex}.components.${componentIndex}.component_variant_id`
                                    ]
                                "
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label
                                :for="`quantity-${variantIndex}-${componentIndex}`"
                                >Quantity</Label
                            >
                            <Input
                                :id="`quantity-${variantIndex}-${componentIndex}`"
                                :name="`variants[${variantIndex}][components][${componentIndex}][quantity]`"
                                v-model="component.quantity"
                                type="number"
                                min="0.001"
                                max="9999999.999"
                                step="0.001"
                                required
                            />
                            <InputError
                                :message="
                                    errors[
                                        `variants.${variantIndex}.components.${componentIndex}.quantity`
                                    ]
                                "
                            />
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="self-end"
                            @click="
                                variant.components.splice(componentIndex, 1)
                            "
                            >Remove</Button
                        >
                    </div>
                    <div class="rounded-lg bg-muted/60 px-4 py-3 text-sm">
                        <template v-if="canViewCosts">
                        Estimated component cost:
                        <span class="font-semibold">{{
                            formatCurrency(estimatedCost(variant))
                        }}</span>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Estimate uses current default variant costs; it is
                            not actual purchase cost or accounting cost.
                        </p>
                        </template>
                        <p v-else class="text-sm text-muted-foreground">Component costs are hidden for your account.</p>
                    </div>
                </section>
            </article>
        </section>

        <div
            v-if="progress"
            class="text-sm text-muted-foreground"
            role="status"
        >
            Uploading… {{ progress.percentage }}%
        </div>
        <div class="flex flex-wrap gap-3 border-t border-border/70 pt-6">
            <Button :disabled="processing || hasDuplicateBarcodes">{{
                product ? 'Save product' : 'Create product'
            }}</Button>
            <Button variant="outline" as-child
                ><Link :href="index()">Cancel</Link></Button
            >
        </div>
    </Form>
</template>
