<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Search, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
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
    destination_location_id: props.locations[1]?.id ?? 0,
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
const sameLocation = computed(
    () =>
        Number(form.source_location_id) ===
        Number(form.destination_location_id),
);
const selectedDeviceCount = computed(() => form.device_ids.length);
const search = ref('');
const pickerOpen = ref(false);
const selectedVariants = computed(
    () => new Set(form.items.map((item) => Number(item.product_variant_id))),
);
const matchingVariants = computed(() => {
    const q = search.value.trim().toLocaleLowerCase();
    return props.variants
        .filter(
            (variant) =>
                !selectedVariants.value.has(variant.id) &&
                `${variant.label} ${variant.sku}`
                    .toLocaleLowerCase()
                    .includes(q),
        )
        .slice(0, 8);
});
watch(
    () => form.source_location_id,
    (sourceId) => {
        form.device_ids = [];
        if (Number(form.destination_location_id) === Number(sourceId)) {
            form.destination_location_id =
                props.locations.find(
                    (location) => location.id !== Number(sourceId),
                )?.id ?? 0;
        }
    },
);
const confirming = ref(false);
const addItem = (variant: Variant): void => {
    if (selectedVariants.value.has(variant.id)) return;
    form.items.push({ product_variant_id: variant.id, quantity: '1' });
    search.value = '';
    pickerOpen.value = false;
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
    <section class="page-shell h-0 min-h-0 overflow-hidden">
        <Heading
            title="Transfer stock"
            description="Move quantity stock or exact serialized devices between active locations."
        />
        <form
            class="surface-panel flex min-h-0 flex-1 flex-col gap-3 overflow-hidden p-3 sm:p-4"
            @submit.prevent="confirming ? submit() : (confirming = true)"
        >
            <section class="shrink-0 space-y-2">
                <div>
                    <h2 class="font-semibold">Transfer route</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Choose different sending and receiving locations.
                    </p>
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="grid gap-2 text-sm font-medium"
                        >From location<select
                            v-model="form.source_location_id"
                            required
                        >
                            <option
                                v-for="item in locations"
                                :key="item.id"
                                :value="item.id"
                            >
                                {{ item.name }}
                            </option>
                        </select>
                        <InputError
                            :message="form.errors.source_location_id"
                        /> </label
                    ><label class="grid gap-2 text-sm font-medium"
                        >To location<select
                            v-model="form.destination_location_id"
                            required
                        >
                            <option
                                v-for="item in locations"
                                :key="item.id"
                                :value="item.id"
                                :disabled="
                                    item.id === Number(form.source_location_id)
                                "
                            >
                                {{ item.name }}
                            </option></select
                        ><InputError
                            :message="form.errors.destination_location_id"
                    /></label>
                </div>
            </section>
            <p
                v-if="sameLocation"
                class="rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm"
                role="alert"
            >
                Select two different locations to continue.
            </p>
            <div class="relative shrink-0">
                <Search
                    class="pointer-events-none absolute top-2.5 left-3 size-4 text-muted-foreground"
                />
                <Input
                    v-model="search"
                    class="pl-9"
                    placeholder="Search product name or SKU, then select to add"
                    autocomplete="off"
                    @focus="pickerOpen = true"
                    @input="pickerOpen = true"
                    @keydown.enter.prevent="
                        matchingVariants[0] && addItem(matchingVariants[0])
                    "
                />
                <div
                    v-if="pickerOpen && search.trim()"
                    class="absolute top-full z-30 mt-1 max-h-48 w-full overflow-y-auto rounded-lg border bg-popover p-1 shadow-lg"
                >
                    <button
                        v-for="variant in matchingVariants"
                        :key="variant.id"
                        type="button"
                        class="flex w-full items-center justify-between gap-3 rounded-md px-3 py-2 text-left text-sm hover:bg-accent"
                        @click="addItem(variant)"
                    >
                        <span class="truncate">{{ variant.label }}</span
                        ><span
                            class="shrink-0 font-mono text-xs text-muted-foreground"
                            >{{ variant.sku }}</span
                        >
                    </button>
                    <p
                        v-if="matchingVariants.length === 0"
                        class="px-3 py-2 text-sm text-muted-foreground"
                    >
                        No matching products.
                    </p>
                </div>
            </div>
            <div
                class="flex min-h-0 flex-1 flex-col gap-2 overflow-hidden rounded-xl border border-border/70 p-3"
            >
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h2 class="font-semibold">Quantity items</h2>
                        <p class="text-sm text-muted-foreground">
                            {{ form.items.length }} item lines
                        </p>
                    </div>
                    <span class="text-xs text-muted-foreground"
                        >{{ form.items.length }} products selected</span
                    >
                </div>
                <p
                    v-if="form.items.length === 0"
                    class="flex min-h-0 flex-1 items-center justify-center rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground"
                >
                    No quantity items added. You can transfer serialized devices
                    below instead.
                </p>
                <div
                    v-else
                    class="min-h-0 flex-1 space-y-2 overflow-y-auto overscroll-contain pr-1"
                >
                    <div
                        v-for="(item, index) in form.items"
                        :key="index"
                        class="grid gap-3 rounded-lg border border-border/70 bg-background/60 p-3 sm:grid-cols-[minmax(0,1fr)_9rem_auto]"
                    >
                        <div class="min-w-0 self-center">
                            <p class="truncate text-sm font-medium">
                                {{
                                    variants.find(
                                        (v) =>
                                            v.id ===
                                            Number(item.product_variant_id),
                                    )?.label
                                }}
                            </p>
                            <p class="font-mono text-xs text-muted-foreground">
                                {{
                                    variants.find(
                                        (v) =>
                                            v.id ===
                                            Number(item.product_variant_id),
                                    )?.sku
                                }}
                            </p>
                        </div>
                        <label class="grid gap-1.5 text-xs font-medium"
                            >Quantity<Input
                                v-model="item.quantity"
                                type="number"
                                min="0.001"
                                step="0.001"
                                required
                        /></label>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            class="self-end text-destructive"
                            aria-label="Remove product line"
                            @click="form.items.splice(index, 1)"
                            ><Trash2
                        /></Button>
                    </div>
                </div>
                <InputError :message="form.errors.items" />
            </div>
            <div
                class="shrink-0 space-y-2 rounded-xl border border-border/70 p-3"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 class="font-semibold">Serialized devices</h2>
                        <p class="text-sm text-muted-foreground">
                            Available at the selected source location.
                        </p>
                    </div>
                    <span
                        class="rounded-full bg-muted px-2.5 py-1 text-xs font-medium"
                    >
                        {{ selectedDeviceCount }} selected ·
                        {{ availableDevices.length }} available
                    </span>
                </div>
                <div
                    class="grid max-h-24 gap-2 overflow-y-auto rounded-lg border border-border/70 bg-background/60 p-2 md:grid-cols-2"
                >
                    <label
                        v-for="device in availableDevices"
                        :key="device.id"
                        class="flex cursor-pointer items-start gap-3 rounded-lg border border-transparent p-2.5 hover:border-border/70 hover:bg-muted/60"
                        ><input
                            v-model="form.device_ids"
                            type="checkbox"
                            :value="device.id"
                            class="mt-1 size-4 rounded border-input"
                        /><span class="text-sm">{{ device.label }}</span></label
                    >
                    <p
                        v-if="availableDevices.length === 0"
                        class="col-span-full p-2 text-sm text-muted-foreground"
                    >
                        No serialized devices available at this source.
                    </p>
                </div>
            </div>
            <div
                class="grid shrink-0 gap-2 border-t border-border/70 pt-2 md:grid-cols-2"
            >
                <label class="grid gap-2 text-sm font-medium"
                    >Transfer reference<Input
                        v-model="form.reference"
                        required /><InputError
                        :message="form.errors.reference" /></label
                ><label class="grid gap-2 text-sm font-medium"
                    >Reason<Input v-model="form.reason" /></label
                ><label class="grid gap-2 text-sm font-medium md:col-span-2"
                    >Notes<textarea v-model="form.notes" rows="1" />
                </label>
            </div>
            <div
                v-if="confirming"
                class="shrink-0 rounded-xl border border-primary/30 bg-primary/5 p-2 text-sm"
            >
                <p class="font-semibold">Review this transfer</p>
                <p class="mt-1">
                    Move {{ form.items.length }} quantity item lines and
                    {{ selectedDeviceCount }} serialized devices from
                    <strong>{{
                        locations.find(
                            (location) =>
                                location.id === Number(form.source_location_id),
                        )?.name
                    }}</strong>
                    to
                    <strong>{{
                        locations.find(
                            (location) =>
                                location.id ===
                                Number(form.destination_location_id),
                        )?.name
                    }}</strong
                    >. The transfer posts as one operation.
                </p>
            </div>
            <div class="flex justify-end gap-2 border-t border-border/70 pt-4">
                <Button
                    v-if="confirming"
                    type="button"
                    variant="outline"
                    @click="confirming = false"
                    >Back</Button
                ><Button
                    type="submit"
                    :disabled="
                        form.processing ||
                        locations.length < 2 ||
                        sameLocation ||
                        (form.items.length === 0 && selectedDeviceCount === 0)
                    "
                    >{{
                        confirming ? 'Confirm transfer' : 'Review transfer'
                    }}</Button
                >
            </div>
        </form>
    </section>
</template>
