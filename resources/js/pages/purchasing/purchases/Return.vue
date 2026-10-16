<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, RotateCcw } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useConfirmationDialog } from '@/composables/useConfirmationDialog';
import { index, show } from '@/routes/purchases';
import purchases from '@/routes/purchases';

type Location = { id: number; name: string; code: string };
type Device = {
    id: number;
    imei: string;
    condition: string;
    location_id: number;
    location_name: string;
};
type PurchaseLine = {
    id: number;
    product_variant_id: number;
    label: string;
    sku: string;
    tracking_mode: string;
    quantity: string;
    remaining_quantity: string;
    stock: Record<string, string>;
    devices: Device[];
};
const props = defineProps<{
    purchase: { id: number; invoice_number: string; lines: PurchaseLine[] };
    locations: Location[];
    returnNumber: string;
    currency: string;
}>();
const form = useForm({
    location_id: props.locations[0]?.id ?? '',
    return_number: props.returnNumber,
    operation_key: crypto.randomUUID(),
    return_date: new Date().toISOString().slice(0, 10),
    notes: '',
    lines: props.purchase.lines.map((line) => ({
        purchase_line_id: line.id,
        quantity: '',
        serialized_device_ids: [] as number[],
    })),
});
const confirmation = useConfirmationDialog();
const errors = computed(() => form.errors as Record<string, string>);
function error(path: string): string | undefined {
    return errors.value[path];
}
function devicesForLine(line: PurchaseLine): Device[] {
    return line.devices.filter(
        (device) => device.location_id === Number(form.location_id),
    );
}
function confirmPost(): void {
    const lines: Array<{
        purchase_line_id: number;
        quantity?: string;
        serialized_device_ids?: number[];
    }> = [];
    form.lines.forEach((line, index) => {
        const source = props.purchase.lines[index];
        if (!source) return;
        if (source.tracking_mode === 'serialized') {
            if (line.serialized_device_ids.length) {
                lines.push({
                    purchase_line_id: line.purchase_line_id,
                    serialized_device_ids: line.serialized_device_ids,
                });
            }

            return;
        }
        if (line.quantity) {
            lines.push({
                purchase_line_id: line.purchase_line_id,
                quantity: line.quantity,
            });
        }
    });
    confirmation.request({
        title: `Post return ${form.return_number}?`,
        description:
            'This immediately reduces location stock or marks the selected IMEI devices as returned to the supplier, and creates permanent movement history.',
        confirmLabel: 'Post return',
        destructive: true,
        onConfirm: () =>
            form
                .transform((data) => ({ ...data, lines }))
                .post(purchases.returns.store(props.purchase.id).url, {
                    preserveScroll: true,
                }),
    });
}
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Purchases', href: index() },
            { title: 'Return stock' },
        ],
    },
});
</script>

<template>
    <Head :title="`Return ${purchase.invoice_number}`" />
    <section class="page-shell space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="`Return purchase ${purchase.invoice_number}`"
                description="Select quantities or exact serialized devices to return to the supplier."
            /><Button as-child variant="outline"
                ><Link :href="show(purchase.id)"
                    ><ArrowLeft /> Invoice</Link
                ></Button
            >
        </div>
        <form class="space-y-5" @submit.prevent="confirmPost">
            <section class="surface-panel grid gap-4 p-5 md:grid-cols-3">
                <div class="grid gap-2">
                    <Label for="return-location"
                        >Return-from location
                        <span class="text-destructive">*</span></Label
                    ><select
                        id="return-location"
                        v-model="form.location_id"
                        required
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    >
                        <option disabled value="">
                            Select authorized location
                        </option>
                        <option
                            v-for="location in locations"
                            :key="location.id"
                            :value="location.id"
                        >
                            {{ location.name }} · {{ location.code }}
                        </option></select
                    ><InputError :message="form.errors.location_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="return-number"
                        >Return reference
                        <span class="text-destructive">*</span></Label
                    ><Input
                        id="return-number"
                        v-model="form.return_number"
                        required
                        maxlength="100"
                    /><InputError :message="form.errors.return_number" />
                </div>
                <div class="grid gap-2">
                    <Label for="return-date"
                        >Return date
                        <span class="text-destructive">*</span></Label
                    ><Input
                        id="return-date"
                        v-model="form.return_date"
                        type="date"
                        required
                    /><InputError :message="form.errors.return_date" />
                </div>
            </section>
            <section class="surface-panel space-y-4 p-5">
                <div>
                    <h2 class="font-semibold">Return items</h2>
                    <p class="text-sm text-muted-foreground">
                        Quantity returns are limited by both unreturned invoice
                        quantity and available stock at the selected location.
                        Phones must be selected by IMEI.
                    </p>
                </div>
                <InputError :message="form.errors.lines" />
                <article
                    v-for="(line, index) in purchase.lines"
                    :key="line.id"
                    class="space-y-3 rounded-xl border border-border/70 p-4"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div>
                            <h3 class="font-medium">{{ line.label }}</h3>
                            <p class="text-xs text-muted-foreground">
                                {{ line.sku }} ·
                                {{
                                    line.tracking_mode === 'serialized'
                                        ? 'Serialized'
                                        : `Quantity · ${line.remaining_quantity} unreturned`
                                }}
                            </p>
                        </div>
                        <span
                            v-if="line.tracking_mode === 'quantity_based'"
                            class="text-xs text-muted-foreground"
                            >Available at location:
                            {{
                                line.stock[Number(form.location_id)] ?? '0.000'
                            }}</span
                        >
                    </div>
                    <div
                        v-if="line.tracking_mode === 'quantity_based'"
                        class="grid max-w-xs gap-2"
                    >
                        <Label :for="`return-qty-${line.id}`"
                            >Return quantity</Label
                        ><Input
                            :id="`return-qty-${line.id}`"
                            v-model="form.lines[index].quantity"
                            type="number"
                            min="0.001"
                            step="0.001"
                            :max="line.remaining_quantity"
                        /><InputError
                            :message="error(`lines.${index}.quantity`)"
                        />
                    </div>
                    <div v-else class="space-y-2">
                        <p
                            v-if="devicesForLine(line).length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            No unreturned devices from this invoice are in the
                            selected location.
                        </p>
                        <label
                            v-for="device in devicesForLine(line)"
                            :key="device.id"
                            class="flex cursor-pointer items-center gap-3 rounded-md border border-border/60 p-3 text-sm"
                            ><input
                                v-model="
                                    form.lines[index].serialized_device_ids
                                "
                                type="checkbox"
                                :value="device.id"
                                class="size-4 accent-primary"
                            /><span
                                ><span class="font-medium tabular-nums"
                                    >IMEI {{ device.imei }}</span
                                ><span class="ml-2 text-muted-foreground"
                                    >{{ device.condition }} ·
                                    {{ device.location_name }}</span
                                ></span
                            ></label
                        >
                        <InputError
                            :message="
                                error(`lines.${index}.serialized_device_ids`)
                            "
                        />
                    </div>
                </article>
            </section>
            <section class="surface-panel grid gap-2 p-5">
                <Label for="return-notes">Notes</Label
                ><textarea
                    id="return-notes"
                    v-model="form.notes"
                    rows="3"
                    maxlength="2000"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                /><InputError :message="form.errors.notes" />
            </section>
            <div
                class="rounded-lg border border-warning/40 bg-warning/10 p-4 text-sm"
            >
                Posting this return immediately deducts the selected stock and
                writes permanent return movement history. Verify the location
                and items before posting.
            </div>
            <InputError
                :message="(form.errors as Record<string, string>).purchase"
            />
            <div class="flex justify-end">
                <Button
                    type="button"
                    :disabled="form.processing || !locations.length"
                    @click="confirmPost"
                    ><RotateCcw />
                    {{
                        form.processing
                            ? 'Posting return…'
                            : 'Post supplier return'
                    }}</Button
                >
            </div>
        </form>
        <ConfirmActionDialog
            :open="confirmation.isOpen.value"
            :title="confirmation.title.value"
            :description="confirmation.description.value"
            :confirm-label="confirmation.confirmLabel.value"
            :destructive="confirmation.destructive.value"
            @update:open="confirmation.setOpen"
            @confirm="confirmation.confirm"
        />
    </section>
</template>
