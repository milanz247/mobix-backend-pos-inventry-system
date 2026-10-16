<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { create, store } from '@/routes/inventory/transfers';
type Location = { id: number; name: string; code: string };
type Variant = { id: number; label: string; sku: string };
type Device = { id: number; location_id: number; label: string };
const props = defineProps<{
    locations: Location[];
    variants: Variant[];
    devices: Device[];
}>();
const form = useForm({
    operation_key: crypto.randomUUID(),
    source_location_id: props.locations[0]?.id ?? '',
    destination_location_id: props.locations[1]?.id ?? '',
    items: [] as { product_variant_id: string | number; quantity: string }[],
    device_ids: [] as number[],
    reference: '',
    reason: 'Location transfer',
    notes: '',
});
const availableDevices = computed(() =>
    props.devices.filter(
        (device) => device.location_id === Number(form.source_location_id),
    ),
);
const confirming = ref(false);
const addItem = (): void => {
    form.items.push({ product_variant_id: '', quantity: '1' });
};
const submit = (): void =>
    form.post(store.url(), {
        onSuccess: () => {
            form.reset();
            form.operation_key = crypto.randomUUID();
            confirming.value = false;
        },
    });
defineOptions({
    layout: { breadcrumbs: [{ title: 'Stock transfers', href: create() }] },
});
</script>
<template>
    <Head title="Stock transfers" />
    <section class="page-shell">
        <Heading
            title="Stock transfers"
            description="Move quantity stock and exact serialized devices between authorized locations in one transaction."
        />
        <form
            class="surface-panel space-y-6 p-5"
            @submit.prevent="confirming ? submit() : (confirming = true)"
        >
            <div class="grid gap-4 md:grid-cols-2">
                <label class="grid gap-2 text-sm font-medium"
                    >Source location<select v-model="form.source_location_id">
                        <option
                            v-for="item in locations"
                            :key="item.id"
                            :value="item.id"
                        >
                            {{ item.name }}
                        </option>
                    </select></label
                ><label class="grid gap-2 text-sm font-medium"
                    >Destination location<select
                        v-model="form.destination_location_id"
                    >
                        <option
                            v-for="item in locations"
                            :key="item.id"
                            :value="item.id"
                        >
                            {{ item.name }}
                        </option></select
                    ><InputError :message="form.errors.destination_location_id"
                /></label>
            </div>
            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="font-semibold">Quantity items</h2>
                        <p class="text-sm text-muted-foreground">
                            Add only the quantity variants being transferred.
                        </p>
                    </div>
                    <Button type="button" variant="outline" @click="addItem"
                        >Add item</Button
                    >
                </div>
                <div
                    v-for="(item, index) in form.items"
                    :key="index"
                    class="grid gap-3 rounded-xl border p-3 sm:grid-cols-[1fr_10rem_auto]"
                >
                    <select v-model="item.product_variant_id">
                        <option value="">Choose quantity variant</option>
                        <option
                            v-for="variant in variants"
                            :key="variant.id"
                            :value="variant.id"
                        >
                            {{ variant.label }} · {{ variant.sku }}
                        </option></select
                    ><Input
                        v-model="item.quantity"
                        type="number"
                        min="0.001"
                        step="0.001"
                    /><Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        @click="form.items.splice(index, 1)"
                        >Remove</Button
                    >
                </div>
                <InputError :message="form.errors.items" />
            </div>
            <div class="space-y-2">
                <h2 class="font-semibold">Serialized devices</h2>
                <p class="text-sm text-muted-foreground">
                    Select exact devices currently in stock at the source.
                </p>
                <div
                    class="grid max-h-56 gap-2 overflow-y-auto rounded-xl border p-3 md:grid-cols-2"
                >
                    <label
                        v-for="device in availableDevices"
                        :key="device.id"
                        class="flex items-center gap-2 rounded-lg p-2 hover:bg-muted"
                        ><input
                            v-model="form.device_ids"
                            type="checkbox"
                            :value="device.id"
                        /><span class="text-sm">{{ device.label }}</span></label
                    >
                    <p
                        v-if="availableDevices.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No serialized devices available at this source.
                    </p>
                </div>
            </div>
            <div class="grid gap-4 md:grid-cols-2">
                <label class="grid gap-2 text-sm font-medium"
                    >Transfer reference<Input
                        v-model="form.reference"
                        required /><InputError
                        :message="form.errors.reference" /></label
                ><label class="grid gap-2 text-sm font-medium"
                    >Reason<Input v-model="form.reason" /></label
                ><label class="grid gap-2 text-sm font-medium md:col-span-2"
                    >Notes<textarea v-model="form.notes" rows="3" />
                </label>
            </div>
            <div
                v-if="confirming"
                class="rounded-xl border border-primary/30 bg-primary/5 p-4 text-sm"
            >
                The source and destination changes will commit together. Any
                validation failure rolls back the complete transfer.
            </div>
            <div class="flex justify-end gap-2">
                <Button
                    v-if="confirming"
                    type="button"
                    variant="outline"
                    @click="confirming = false"
                    >Back</Button
                ><Button
                    type="submit"
                    :disabled="form.processing || locations.length < 2"
                    >{{
                        confirming ? 'Confirm transfer' : 'Review transfer'
                    }}</Button
                >
            </div>
        </form>
    </section>
</template>
