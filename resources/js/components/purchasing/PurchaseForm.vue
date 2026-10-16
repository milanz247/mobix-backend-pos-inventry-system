<script setup lang="ts">
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as purchasesIndex } from '@/routes/purchases';

type Option = { id: number; name: string };
type Location = Option & { code: string };
type Variant = {
    id: number;
    label: string;
    sku: string;
    tracking_mode: 'quantity_based' | 'serialized';
    cost_price: string;
    selling_price: string;
};
type DeviceInput = {
    imei: string;
    imei2: string;
    serial_number: string;
    condition: string;
    unit_cost: string;
    unit_selling_price: string;
};
type SavedDeviceInput = Omit<
    DeviceInput,
    'imei2' | 'serial_number' | 'unit_selling_price'
> & {
    imei2: string | null;
    serial_number: string | null;
    unit_selling_price: string | null;
};
type LineInput = {
    product_variant_id: number | '';
    quantity: string;
    unit_cost: string;
    discount_amount: string;
    devices: DeviceInput[];
};
type PurchaseLine = {
    product_variant_id: number;
    tracking_mode: string;
    quantity: string;
    unit_cost: string;
    discount_amount: string;
    devices: SavedDeviceInput[];
};
const props = defineProps<{
    action: string;
    method: 'post' | 'put';
    suppliers: Option[];
    locations: Location[];
    variants: Variant[];
    currency: string;
    purchase?: {
        supplier: { id: number };
        location: { id: number };
        invoice_number: string;
        invoice_date: string;
        notes: string | null;
        lines: PurchaseLine[];
    };
}>();
const makeDevice = (): DeviceInput => ({
    imei: '',
    imei2: '',
    serial_number: '',
    condition: 'new',
    unit_cost: '',
    unit_selling_price: '',
});
const makeLine = (): LineInput => ({
    product_variant_id: '',
    quantity: '1.000',
    unit_cost: '',
    discount_amount: '0.00',
    devices: [],
});
const form = useForm({
    supplier_id: props.purchase?.supplier.id ?? '',
    location_id: props.purchase?.location.id ?? props.locations[0]?.id ?? '',
    invoice_number: props.purchase?.invoice_number ?? '',
    invoice_date:
        props.purchase?.invoice_date ?? new Date().toISOString().slice(0, 10),
    notes: props.purchase?.notes ?? '',
    lines: props.purchase?.lines.length
        ? props.purchase.lines.map((line) => ({
              product_variant_id: line.product_variant_id,
              quantity: line.quantity,
              unit_cost:
                  line.tracking_mode === 'quantity_based' ? line.unit_cost : '',
              discount_amount: line.discount_amount,
              devices: line.devices.map((device) => ({
                  ...device,
                  imei2: device.imei2 ?? '',
                  serial_number: device.serial_number ?? '',
                  unit_selling_price: device.unit_selling_price ?? '',
              })),
          }))
        : [makeLine()],
});
const selectedVariant = (line: LineInput): Variant | undefined =>
    props.variants.find(
        (variant) => variant.id === Number(line.product_variant_id),
    );
const isSerialized = (line: LineInput): boolean =>
    selectedVariant(line)?.tracking_mode === 'serialized';

function addLine(): void {
    form.lines.push(makeLine());
}
function addDevice(line: LineInput): void {
    const variant = selectedVariant(line);
    line.devices.push({
        ...makeDevice(),
        unit_cost: variant?.cost_price ?? '',
        unit_selling_price: variant?.selling_price ?? '',
    });
}
function selectVariant(line: LineInput, id: number | ''): void {
    line.product_variant_id = id;
    const variant = props.variants.find((option) => option.id === Number(id));
    if (variant?.tracking_mode === 'serialized') {
        line.quantity = '1.000';
        line.devices = line.devices.length
            ? line.devices
            : [
                  {
                      ...makeDevice(),
                      unit_cost: variant.cost_price,
                      unit_selling_price: variant.selling_price,
                  },
              ];
        line.unit_cost = '';
    } else {
        line.devices = [];
        line.unit_cost = variant?.cost_price ?? '';
    }
}
function scaled(
    value: string | number | null | undefined,
    scale: number,
): bigint {
    const match = String(value ?? '')
        .trim()
        .match(/^([0-9]+)(?:\.([0-9]*))?$/);
    if (!match) return 0n;
    return BigInt(
        match[1] + (match[2] ?? '').padEnd(scale, '0').slice(0, scale),
    );
}
function lineSubtotal(line: LineInput): bigint {
    if (isSerialized(line))
        return line.devices.reduce(
            (sum, device) => sum + scaled(device.unit_cost, 2),
            0n,
        );
    return (
        (scaled(line.unit_cost, 2) * scaled(line.quantity, 3) + 500n) / 1000n
    );
}
function lineTotal(line: LineInput): bigint {
    return lineSubtotal(line) > scaled(line.discount_amount, 2)
        ? lineSubtotal(line) - scaled(line.discount_amount, 2)
        : 0n;
}
function formatMinor(value: bigint): string {
    const digits = value.toString().padStart(3, '0');
    const whole = BigInt(digits.slice(0, -2)).toLocaleString();
    return `${props.currency} ${whole}.${digits.slice(-2)}`;
}
const total = computed(() =>
    form.lines.reduce((sum, line) => sum + lineTotal(line), 0n),
);
const subtotal = computed(() =>
    form.lines.reduce((sum, line) => sum + lineSubtotal(line), 0n),
);
const errors = computed(() => form.errors as Record<string, string>);
function error(key: string): string | undefined {
    return errors.value[key];
}
function submit(): void {
    if (props.method === 'post')
        form.post(props.action, { preserveScroll: true });
    else form.put(props.action, { preserveScroll: true });
}
</script>

<template>
    <form class="space-y-5" @submit.prevent="submit">
        <section class="surface-panel space-y-5 p-5 sm:p-6">
            <div>
                <h2 class="font-semibold">Purchase details</h2>
                <p class="text-sm text-muted-foreground">
                    Save this invoice as a draft. Stock changes only after it is
                    posted.
                </p>
            </div>
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div class="grid gap-2">
                    <Label for="purchase-supplier"
                        >Supplier <span class="text-destructive">*</span></Label
                    ><select
                        id="purchase-supplier"
                        v-model="form.supplier_id"
                        required
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    >
                        <option disabled value="">Select supplier</option>
                        <option
                            v-for="supplier in suppliers"
                            :key="supplier.id"
                            :value="supplier.id"
                        >
                            {{ supplier.name }}
                        </option></select
                    ><InputError :message="form.errors.supplier_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="purchase-location"
                        >Destination location
                        <span class="text-destructive">*</span></Label
                    ><select
                        id="purchase-location"
                        v-model="form.location_id"
                        required
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    >
                        <option disabled value="">Select location</option>
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
                    <Label for="purchase-invoice"
                        >Invoice / reference no.
                        <span class="text-destructive">*</span></Label
                    ><Input
                        id="purchase-invoice"
                        v-model="form.invoice_number"
                        required
                        maxlength="100"
                    /><InputError :message="form.errors.invoice_number" />
                </div>
                <div class="grid gap-2">
                    <Label for="purchase-date"
                        >Invoice date
                        <span class="text-destructive">*</span></Label
                    ><Input
                        id="purchase-date"
                        v-model="form.invoice_date"
                        type="date"
                        required
                    /><InputError :message="form.errors.invoice_date" />
                </div>
            </div>
        </section>

        <section class="surface-panel space-y-5 p-5 sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="font-semibold">Invoice lines</h2>
                    <p class="text-sm text-muted-foreground">
                        Costs and discounts are saved with the invoice; current
                        catalogue defaults are not changed.
                    </p>
                </div>
                <Button type="button" variant="outline" @click="addLine"
                    ><Plus /> Add item</Button
                >
            </div>
            <InputError :message="form.errors.lines" />
            <div
                v-for="(line, index) in form.lines"
                :key="index"
                class="space-y-4 rounded-xl border border-border/70 bg-muted/10 p-4"
            >
                <div
                    class="grid gap-4 md:grid-cols-2 xl:grid-cols-[minmax(15rem,2fr)_repeat(3,minmax(7rem,1fr))_auto]"
                >
                    <div class="grid gap-2">
                        <Label :for="`purchase-variant-${index}`"
                            >Product variant</Label
                        ><select
                            :id="`purchase-variant-${index}`"
                            :value="line.product_variant_id"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            required
                            @change="
                                selectVariant(
                                    line,
                                    Number(
                                        ($event.target as HTMLSelectElement)
                                            .value,
                                    ) || '',
                                )
                            "
                        >
                            <option disabled value="">
                                Select active variant
                            </option>
                            <option
                                v-for="variant in variants"
                                :key="variant.id"
                                :value="variant.id"
                                :disabled="
                                    form.lines.some(
                                        (other, otherIndex) =>
                                            otherIndex !== index &&
                                            Number(other.product_variant_id) ===
                                                variant.id,
                                    )
                                "
                            >
                                {{ variant.label }} · {{ variant.sku }}
                            </option></select
                        ><InputError
                            :message="
                                error(`lines.${index}.product_variant_id`)
                            "
                        />
                    </div>
                    <div v-if="!isSerialized(line)" class="grid gap-2">
                        <Label :for="`purchase-qty-${index}`">Quantity</Label
                        ><Input
                            :id="`purchase-qty-${index}`"
                            v-model="line.quantity"
                            type="number"
                            min="0.001"
                            step="0.001"
                            required
                        /><InputError
                            :message="error(`lines.${index}.quantity`)"
                        />
                    </div>
                    <div v-if="!isSerialized(line)" class="grid gap-2">
                        <Label :for="`purchase-cost-${index}`">Unit cost</Label
                        ><Input
                            :id="`purchase-cost-${index}`"
                            v-model="line.unit_cost"
                            type="number"
                            min="0"
                            step="0.01"
                            required
                        /><InputError
                            :message="error(`lines.${index}.unit_cost`)"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label :for="`purchase-discount-${index}`"
                            >Line discount</Label
                        ><Input
                            :id="`purchase-discount-${index}`"
                            v-model="line.discount_amount"
                            type="number"
                            min="0"
                            step="0.01"
                        /><InputError
                            :message="error(`lines.${index}.discount_amount`)"
                        />
                    </div>
                    <div
                        class="flex items-end justify-between gap-2 xl:justify-end"
                    >
                        <div class="pb-2 text-sm">
                            <span class="text-muted-foreground"
                                >Line total</span
                            >
                            <div class="font-semibold">
                                {{ formatMinor(lineTotal(line)) }}
                            </div>
                        </div>
                        <Button
                            v-if="form.lines.length > 1"
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            class="text-destructive"
                            :aria-label="`Remove item ${index + 1}`"
                            @click="form.lines.splice(index, 1)"
                            ><Trash2
                        /></Button>
                    </div>
                </div>
                <div
                    v-if="isSerialized(line)"
                    class="space-y-3 border-t border-border/60 pt-4"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <div>
                            <h3 class="text-sm font-semibold">
                                Serialized devices
                            </h3>
                            <p class="text-xs text-muted-foreground">
                                Each phone is received by its exact IMEI. The
                                device cost is recorded separately.
                            </p>
                        </div>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            @click="addDevice(line)"
                            ><Plus /> Add device</Button
                        >
                    </div>
                    <InputError :message="error(`lines.${index}.devices`)" />
                    <div
                        v-for="(device, deviceIndex) in line.devices"
                        :key="deviceIndex"
                        class="grid gap-3 rounded-lg border border-border/60 bg-background p-3 sm:grid-cols-2 xl:grid-cols-4"
                    >
                        <div class="grid gap-1">
                            <Label :for="`imei-${index}-${deviceIndex}`"
                                >IMEI 1</Label
                            ><Input
                                :id="`imei-${index}-${deviceIndex}`"
                                v-model="device.imei"
                                required
                                inputmode="numeric"
                                maxlength="32"
                            /><InputError
                                :message="
                                    error(
                                        `lines.${index}.devices.${deviceIndex}.imei`,
                                    )
                                "
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label :for="`imei2-${index}-${deviceIndex}`"
                                >IMEI 2</Label
                            ><Input
                                :id="`imei2-${index}-${deviceIndex}`"
                                v-model="device.imei2"
                                inputmode="numeric"
                                maxlength="32"
                            /><InputError
                                :message="
                                    error(
                                        `lines.${index}.devices.${deviceIndex}.imei2`,
                                    )
                                "
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label :for="`serial-${index}-${deviceIndex}`"
                                >Serial number</Label
                            ><Input
                                :id="`serial-${index}-${deviceIndex}`"
                                v-model="device.serial_number"
                                maxlength="100"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label :for="`condition-${index}-${deviceIndex}`"
                                >Condition</Label
                            ><select
                                :id="`condition-${index}-${deviceIndex}`"
                                v-model="device.condition"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            >
                                <option value="new">New</option>
                                <option value="used">Used</option>
                                <option value="refurbished">Refurbished</option>
                            </select>
                        </div>
                        <div class="grid gap-1">
                            <Label :for="`device-cost-${index}-${deviceIndex}`"
                                >Actual unit cost</Label
                            ><Input
                                :id="`device-cost-${index}-${deviceIndex}`"
                                v-model="device.unit_cost"
                                type="number"
                                min="0"
                                step="0.01"
                                required
                            /><InputError
                                :message="
                                    error(
                                        `lines.${index}.devices.${deviceIndex}.unit_cost`,
                                    )
                                "
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label :for="`device-price-${index}-${deviceIndex}`"
                                >Selling price (optional)</Label
                            ><Input
                                :id="`device-price-${index}-${deviceIndex}`"
                                v-model="device.unit_selling_price"
                                type="number"
                                min="0"
                                step="0.01"
                            />
                        </div>
                        <div class="flex items-end sm:col-span-2 xl:col-span-2">
                            <Button
                                v-if="line.devices.length > 1"
                                type="button"
                                variant="ghost"
                                size="sm"
                                class="text-destructive"
                                @click="line.devices.splice(deviceIndex, 1)"
                                >Remove device</Button
                            >
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
            <div class="surface-panel grid gap-2 p-5">
                <Label for="purchase-notes">Notes</Label
                ><textarea
                    id="purchase-notes"
                    v-model="form.notes"
                    rows="4"
                    maxlength="4000"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                /><InputError :message="form.errors.notes" />
            </div>
            <div class="surface-panel space-y-3 p-5">
                <h2 class="font-semibold">Invoice total</h2>
                <div class="flex justify-between text-sm">
                    <span class="text-muted-foreground">Subtotal</span
                    ><span>{{ formatMinor(subtotal) }}</span>
                </div>
                <div
                    class="flex justify-between border-t border-border/70 pt-3 font-semibold"
                >
                    <span>Total</span><span>{{ formatMinor(total) }}</span>
                </div>
                <p class="text-xs text-muted-foreground">
                    No stock is changed until this draft is posted.
                </p>
            </div>
        </section>
        <InputError :message="error('purchase')" />
        <div
            v-if="
                suppliers.length === 0 ||
                locations.length === 0 ||
                variants.length === 0
            "
            class="rounded-lg border border-warning/40 bg-warning/10 p-4 text-sm"
        >
            A purchase requires an active supplier, an authorized active
            location, and at least one active non-combo product variant.
        </div>
        <div class="flex flex-wrap justify-end gap-2">
            <Button as-child type="button" variant="outline"
                ><Link :href="purchasesIndex()">Cancel</Link></Button
            ><Button
                type="submit"
                :disabled="
                    form.processing ||
                    !suppliers.length ||
                    !locations.length ||
                    !variants.length
                "
                >{{
                    form.processing
                        ? 'Saving…'
                        : props.method === 'post'
                          ? 'Save draft'
                          : 'Update draft'
                }}</Button
            >
        </div>
    </form>
</template>
