<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { Plus, Search, Trash2 } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { create, store } from '@/routes/inventory/adjustments';

type Location = { id: number; name: string; code?: string };
type Variant = { id: number; label: string; sku: string };
type AdjustmentLine = { product_variant_id: number | ''; quantity: string };

const props = defineProps<{ locations: Location[]; variants: Variant[] }>();
const form = useForm({
    operation_key: crypto.randomUUID(),
    location_id: props.locations[0]?.id ?? '',
    direction: 'increase',
    reason: 'physical_count',
    items: [] as AdjustmentLine[],
    reference: '',
    notes: '',
});
const productSearch = ref('');
const productPickerOpen = ref(false);
const confirming = ref(false);
const selectedVariants = computed(
    () => new Set(form.items.map((item) => Number(item.product_variant_id))),
);
const matchingVariants = computed(() => {
    const query = productSearch.value.trim().toLocaleLowerCase();

    return props.variants
        .filter(
            (variant) =>
                !selectedVariants.value.has(variant.id) &&
                `${variant.label} ${variant.sku}`
                    .toLocaleLowerCase()
                    .includes(query),
        )
        .slice(0, 8);
});
const selectedLocation = computed(() =>
    props.locations.find(
        (location) => location.id === Number(form.location_id),
    ),
);

watch(
    () => form.reason,
    (reason) => {
        if (reason === 'damaged') form.direction = 'decrease';
    },
);

function addProduct(variant: Variant): void {
    if (selectedVariants.value.has(variant.id)) return;
    form.items.push({ product_variant_id: variant.id, quantity: '1.000' });
    productSearch.value = '';
    productPickerOpen.value = false;
}

function removeItem(index: number): void {
    form.items.splice(index, 1);
}

function submit(): void {
    form.post(store.url(), {
        onSuccess: () => {
            form.reset();
            form.operation_key = crypto.randomUUID();
            productSearch.value = '';
            confirming.value = false;
        },
    });
}

defineOptions({
    layout: { breadcrumbs: [{ title: 'Adjustments', href: create() }] },
});
</script>

<template>
    <Head title="Stock adjustments" />
    <section class="page-shell h-0 min-h-0 overflow-hidden">
        <Heading
            class="shrink-0"
            variant="small"
            title="Adjust stock"
            description="Add or remove counted stock with a recorded reason."
        />

        <form
            class="surface-panel flex min-h-0 flex-1 flex-col gap-3 overflow-hidden p-3 sm:p-4"
            @submit.prevent="confirming ? submit() : (confirming = true)"
        >
            <div class="grid shrink-0 gap-3 sm:grid-cols-3">
                <div class="grid gap-1.5">
                    <Label for="adjust-location">Active location</Label>
                    <select
                        id="adjust-location"
                        v-model="form.location_id"
                        required
                    >
                        <option
                            v-for="location in locations"
                            :key="location.id"
                            :value="location.id"
                        >
                            {{ location.name }}
                        </option>
                    </select>
                    <InputError :message="form.errors.location_id" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="adjust-direction">Adjustment type</Label>
                    <select id="adjust-direction" v-model="form.direction">
                        <option value="increase">Increase stock</option>
                        <option value="decrease">Decrease stock</option>
                    </select>
                    <InputError :message="form.errors.direction" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="adjust-reason">Reason</Label>
                    <select id="adjust-reason" v-model="form.reason">
                        <option value="physical_count">Physical count</option>
                        <option value="damaged">Damaged</option>
                        <option value="missing">Missing stock</option>
                        <option value="data_correction">Data correction</option>
                        <option value="other">Other</option>
                    </select>
                    <InputError :message="form.errors.reason" />
                </div>
            </div>

            <div class="relative shrink-0">
                <Label for="adjust-product-search" class="sr-only"
                    >Search products</Label
                >
                <Search
                    class="pointer-events-none absolute top-2.5 left-3 size-4 text-muted-foreground"
                />
                <Input
                    id="adjust-product-search"
                    v-model="productSearch"
                    class="pl-9 text-sm"
                    autocomplete="off"
                    placeholder="Search product name or SKU, then select to add"
                    :disabled="variants.length === 0"
                    @focus="productPickerOpen = true"
                    @input="productPickerOpen = true"
                    @keydown.enter.prevent="
                        matchingVariants[0] && addProduct(matchingVariants[0])
                    "
                    @keydown.esc="productPickerOpen = false"
                />
                <div
                    v-if="productPickerOpen && productSearch.trim()"
                    class="absolute top-full z-30 mt-1 max-h-52 w-full overflow-y-auto rounded-lg border bg-popover p-1 shadow-lg"
                    role="listbox"
                    aria-label="Matching products"
                >
                    <button
                        v-for="variant in matchingVariants"
                        :key="variant.id"
                        type="button"
                        class="flex w-full items-center justify-between gap-3 rounded-md px-3 py-2 text-left text-sm hover:bg-accent"
                        role="option"
                        @click="addProduct(variant)"
                    >
                        <span class="min-w-0 truncate">{{
                            variant.label
                        }}</span>
                        <span
                            class="shrink-0 font-mono text-xs text-muted-foreground"
                            >{{ variant.sku }}</span
                        >
                    </button>
                    <p
                        v-if="matchingVariants.length === 0"
                        class="px-3 py-2 text-sm text-muted-foreground"
                    >
                        No matching products available.
                    </p>
                </div>
            </div>
            <InputError :message="form.errors.items" />

            <div
                class="flex min-h-0 flex-1 flex-col gap-2 overflow-hidden rounded-xl border border-border/70 p-3"
            >
                <div class="flex shrink-0 items-center justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold">
                            Products to adjust
                        </h2>
                        <p class="text-xs text-muted-foreground">
                            {{ form.items.length }} selected
                        </p>
                    </div>
                    <span
                        class="rounded-full bg-muted px-2.5 py-1 text-xs font-medium text-muted-foreground"
                    >
                        {{
                            form.direction === 'increase'
                                ? 'Stock in'
                                : 'Stock out'
                        }}
                    </span>
                </div>
                <div
                    v-if="form.items.length === 0"
                    class="flex min-h-0 flex-1 items-center justify-center rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground"
                >
                    Search and select a product above to add it here.
                </div>
                <div
                    v-else
                    class="min-h-0 flex-1 space-y-2 overflow-y-auto overscroll-contain pr-1"
                >
                    <div
                        v-for="(item, index) in form.items"
                        :key="Number(item.product_variant_id)"
                        class="grid items-center gap-3 rounded-lg border border-border/70 bg-background/60 p-2.5 sm:grid-cols-[minmax(0,1fr)_9rem_auto]"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">
                                {{
                                    variants.find(
                                        (variant) =>
                                            variant.id ===
                                            Number(item.product_variant_id),
                                    )?.label
                                }}
                            </p>
                            <p class="font-mono text-xs text-muted-foreground">
                                {{
                                    variants.find(
                                        (variant) =>
                                            variant.id ===
                                            Number(item.product_variant_id),
                                    )?.sku
                                }}
                            </p>
                        </div>
                        <label class="grid gap-1 text-xs font-medium">
                            Quantity
                            <Input
                                v-model="item.quantity"
                                type="number"
                                min="0.001"
                                step="0.001"
                                required
                                class="text-sm"
                            />
                        </label>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            class="self-end text-destructive"
                            :aria-label="`Remove ${variants.find((variant) => variant.id === Number(item.product_variant_id))?.label}`"
                            @click="removeItem(index)"
                        >
                            <Trash2 />
                        </Button>
                    </div>
                </div>
            </div>

            <div class="grid shrink-0 gap-2 sm:grid-cols-2">
                <label class="grid gap-1 text-xs font-medium">
                    Reference (optional)
                    <Input v-model="form.reference" class="text-sm" />
                </label>
                <label class="grid gap-1 text-xs font-medium">
                    Notes (optional)
                    <Input v-model="form.notes" class="text-sm" />
                </label>
            </div>

            <div
                v-if="confirming"
                class="shrink-0 rounded-lg border border-warning/30 bg-warning/5 px-3 py-2 text-xs"
            >
                Review: {{ form.direction === 'increase' ? 'add' : 'remove' }}
                {{ form.items.length }} products at
                {{ selectedLocation?.name }} for
                {{ form.reason.replaceAll('_', ' ') }}. A decrease cannot make
                stock negative.
            </div>

            <div
                class="flex shrink-0 justify-end gap-2 border-t border-border/70 pt-3"
            >
                <Button
                    v-if="confirming"
                    type="button"
                    variant="outline"
                    @click="confirming = false"
                    >Back</Button
                >
                <Button
                    type="submit"
                    :disabled="
                        form.processing ||
                        locations.length === 0 ||
                        variants.length === 0 ||
                        form.items.length === 0
                    "
                    >{{
                        confirming ? 'Confirm adjustment' : 'Review adjustment'
                    }}</Button
                >
            </div>
        </form>
    </section>
</template>
