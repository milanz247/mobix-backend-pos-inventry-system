<script setup lang="ts">
import { useVariantSearch } from '@/composables/useVariantSearch';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { store as openingStore } from '@/routes/inventory/opening';
import { index as inventoryIndex } from '@/routes/inventory';
import { store as receivingStore } from '@/routes/inventory/receiving';

type Location = { id: number; name: string; code: string };
type Variant = {
    id: number;
    label: string;
    sku: string;
    tracking_mode: string;
};
type Device = {
    imei: string;
    imei2: string;
    serial_number: string;
    condition: string;
    unit_cost: string;
    unit_selling_price: string;
};
const props = defineProps<{
    locations: Location[];
    variants: Variant[];
    opening: boolean;
    selectedLocationId?: number | null;
}>();
const makeDevice = (): Device => ({
    imei: '',
    imei2: '',
    serial_number: '',
    condition: 'new',
    unit_cost: '0.00',
    unit_selling_price: '',
});
const form = useForm({
    operation_key: crypto.randomUUID(),
    location_id: props.selectedLocationId ?? props.locations[0]?.id ?? '',
    product_variant_id: '',
    mode: 'quantity',
    quantity: '1',
    unit_cost: '',
    devices: [makeDevice()],
    reference: '',
    reason: props.opening ? 'Initial inventory setup' : '',
    notes: '',
});
const search = ref('');
const { variants, searchError } = useVariantSearch<Variant>(
    props.variants,
    search,
    false,
    true,
    () => [Number(form.product_variant_id)],
);
const selectedVariant = computed(() =>
    variants.value.find((item) => item.id === Number(form.product_variant_id)),
);
watch(selectedVariant, (variant) => {
    if (variant)
        form.mode =
            variant.tracking_mode === 'serialized' ? 'serialized' : 'quantity';
});
const reviewing = ref(false);
const error = (key: string): string | undefined =>
    (form.errors as Record<string, string>)[key];
const submit = (): void => {
    form.post((props.opening ? openingStore : receivingStore).url(), {
        onSuccess: () => {
            form.reset();
            form.operation_key = crypto.randomUUID();
            reviewing.value = false;
        },
    });
};
defineOptions({
    layout: { breadcrumbs: [{ title: 'Inventory', href: inventoryIndex() }] },
});
</script>
<template>
    <Head :title="opening ? 'Opening stock' : 'Stock receiving'" />
    <section class="page-shell">
        <Heading
            :title="opening ? 'Opening stock' : 'Stock receiving'"
            :description="
                opening
                    ? 'Post controlled initial stock with a permanent audit trail.'
                    : 'Receive manual stock before purchase management is introduced.'
            "
        />
        <form
            class="surface-panel space-y-6 p-5"
            @submit.prevent="reviewing ? submit() : (reviewing = true)"
        >
            <div class="grid gap-4 md:grid-cols-2">
                <label class="grid gap-2 text-sm font-medium"
                    >Destination location<select
                        v-model="form.location_id"
                        required
                    >
                        <option disabled value="">Choose location</option>
                        <option
                            v-for="item in locations"
                            :key="item.id"
                            :value="item.id"
                        >
                            {{ item.name }} ({{ item.code }})
                        </option></select
                    ><InputError :message="form.errors.location_id" /></label
                ><label class="grid gap-2 text-sm font-medium"
                    >Product variant<Input
                        v-model="search"
                        placeholder="Search product, SKU or barcode" /><span
                        v-if="searchError"
                        role="alert"
                        >{{ searchError }}</span
                    ><select v-model="form.product_variant_id" required>
                        <option disabled value="">Choose active variant</option>
                        <option
                            v-for="variant in variants"
                            :key="variant.id"
                            :value="variant.id"
                        >
                            {{ variant.label }} · {{ variant.sku }}
                        </option></select
                    ><InputError :message="form.errors.product_variant_id"
                /></label>
            </div>
            <div
                v-if="selectedVariant"
                class="rounded-lg border bg-muted/30 p-3 text-sm"
            >
                Tracking mode:
                <strong>{{
                    form.mode === 'serialized'
                        ? 'Serialized / IMEI'
                        : 'Quantity based'
                }}</strong>
            </div>
            <div
                v-if="form.mode === 'quantity'"
                class="grid gap-4 md:grid-cols-2"
            >
                <label class="grid gap-2 text-sm font-medium"
                    >Received quantity<Input
                        v-model="form.quantity"
                        type="number"
                        min="0.001"
                        step="0.001"
                        required /><InputError
                        :message="form.errors.quantity" /></label
                ><label class="grid gap-2 text-sm font-medium"
                    >Unit cost (optional)<Input
                        v-model="form.unit_cost"
                        type="number"
                        min="0"
                        step="0.01" /><InputError
                        :message="form.errors.unit_cost"
                /></label>
            </div>
            <div v-else class="space-y-3">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="font-semibold">Individual devices</h2>
                        <p class="text-sm text-muted-foreground">
                            Each IMEI is validated across both IMEI slots.
                        </p>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        @click="form.devices.push(makeDevice())"
                        >Add device</Button
                    >
                </div>
                <div
                    v-for="(device, index) in form.devices"
                    :key="index"
                    class="grid gap-3 rounded-xl border p-4 md:grid-cols-3"
                >
                    <label class="grid gap-1 text-sm font-medium"
                        >IMEI 1<Input
                            v-model="device.imei"
                            inputmode="numeric"
                            required /><InputError
                            :message="error(`devices.${index}.imei`)" /></label
                    ><label class="grid gap-1 text-sm font-medium"
                        >IMEI 2 (optional)<Input
                            v-model="device.imei2"
                            inputmode="numeric" /><InputError
                            :message="error(`devices.${index}.imei2`)" /></label
                    ><label class="grid gap-1 text-sm font-medium"
                        >Serial number<Input
                            v-model="device.serial_number" /></label
                    ><label class="grid gap-1 text-sm font-medium"
                        >Condition<select v-model="device.condition">
                            <option value="new">New</option>
                            <option value="used">Used</option>
                            <option value="refurbished">Refurbished</option>
                        </select></label
                    ><label class="grid gap-1 text-sm font-medium"
                        >Unit cost<Input
                            v-model="device.unit_cost"
                            type="number"
                            min="0"
                            step="0.01"
                            required /></label
                    ><label class="grid gap-1 text-sm font-medium"
                        >Selling price (optional)<Input
                            v-model="device.unit_selling_price"
                            type="number"
                            min="0"
                            step="0.01"
                    /></label>
                    <div class="md:col-span-3">
                        <Button
                            v-if="form.devices.length > 1"
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="text-destructive"
                            @click="form.devices.splice(index, 1)"
                            >Remove device</Button
                        >
                    </div>
                </div>
                <InputError :message="form.errors.devices" />
            </div>
            <div class="grid gap-4 md:grid-cols-2">
                <label class="grid gap-2 text-sm font-medium"
                    >Reference (optional)<Input
                        v-model="form.reference"
                        maxlength="100" /></label
                ><label class="grid gap-2 text-sm font-medium"
                    >Reason
                    <span
                        v-if="!opening"
                        class="font-normal text-muted-foreground"
                        >(optional)</span
                    ><Input
                        v-model="form.reason"
                        :required="opening"
                        maxlength="80" /><InputError
                        :message="form.errors.reason" /></label
                ><label class="grid gap-2 text-sm font-medium md:col-span-2"
                    >Notes<textarea
                        v-model="form.notes"
                        rows="3"
                        maxlength="2000"
                    />
                </label>
            </div>
            <div
                v-if="reviewing"
                class="rounded-xl border border-primary/30 bg-primary/5 p-4"
            >
                <h2 class="font-semibold">Confirm posting</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    This will post
                    {{
                        form.mode === 'quantity'
                            ? `${form.quantity} units`
                            : `${form.devices.length} device(s)`
                    }}
                    and create immutable audit history. Posted history cannot be
                    edited.
                </p>
            </div>
            <div class="flex justify-end gap-2">
                <Button
                    v-if="reviewing"
                    type="button"
                    variant="outline"
                    @click="reviewing = false"
                    >Back</Button
                ><Button
                    type="submit"
                    :disabled="form.processing || !selectedVariant"
                    >{{
                        reviewing ? 'Confirm and post' : 'Review stock'
                    }}</Button
                >
            </div>
        </form>
    </section>
</template>
