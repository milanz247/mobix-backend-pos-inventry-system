<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { Plus, Search, Trash2 } from '@lucide/vue';
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
    label: string;
    sku: string;
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
const initialLines: LineInput[] = props.purchase?.lines.length
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
    : [];
const form = useForm({
    supplier_id: props.purchase?.supplier.id ?? '',
    location_id: props.purchase?.location.id ?? props.locations[0]?.id ?? '',
    invoice_number: props.purchase?.invoice_number ?? '',
    invoice_date:
        props.purchase?.invoice_date ?? new Date().toISOString().slice(0, 10),
    notes: props.purchase?.notes ?? '',
    lines: initialLines,
});
const productSearch = ref('');
const productPickerOpen = ref(false);
const selectedVariant = (line: LineInput): Variant | undefined =>
    props.variants.find(
        (variant) => variant.id === Number(line.product_variant_id),
    );
const isSerialized = (line: LineInput): boolean =>
    selectedVariant(line)?.tracking_mode === 'serialized';

function addProduct(variant: Variant): void {
    if (
        !variant ||
        form.lines.some(
            (line) => Number(line.product_variant_id) === variant.id,
        )
    )
        return;

    const line = makeLine();
    line.product_variant_id = variant.id;
    if (variant.tracking_mode === 'serialized') {
        line.devices = [
            {
                ...makeDevice(),
                unit_cost: variant.cost_price,
                unit_selling_price: variant.selling_price,
            },
        ];
    } else {
        line.unit_cost = variant.cost_price;
    }
    form.lines.push(line);
    productSearch.value = '';
    productPickerOpen.value = false;
}
function removeLine(index: number): void {
    form.lines.splice(index, 1);
}
function addDevice(line: LineInput): void {
    const variant = selectedVariant(line);
    line.devices.push({
        ...makeDevice(),
        unit_cost: variant?.cost_price ?? '',
        unit_selling_price: variant?.selling_price ?? '',
    });
}
function matchingVariants(): Variant[] {
    const search = productSearch.value.trim().toLocaleLowerCase();
    return props.variants
        .filter(
            (variant) =>
                !form.lines.some(
                    (line) => Number(line.product_variant_id) === variant.id,
                ) &&
                (search === '' ||
                    `${variant.label} ${variant.sku}`
                        .toLocaleLowerCase()
                        .includes(search)),
        )
        .slice(0, 8);
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
    <form
        class="flex min-h-0 flex-1 flex-col gap-2 overflow-hidden"
        @submit.prevent="submit"
    >
        <section class="surface-panel shrink-0 space-y-2.5 p-3 sm:p-4">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-sm font-semibold">Purchase details</h2>
                <span class="text-xs text-muted-foreground"
                    >Required fields marked *</span
                >
            </div>
            <div class="grid grid-cols-2 gap-2.5 xl:grid-cols-4">
                <div class="grid gap-2">
                    <Label for="purchase-supplier"
                        >Supplier <span class="text-destructive">*</span></Label
                    ><select
                        id="purchase-supplier"
                        v-model="form.supplier_id"
                        required
                        class="h-9 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
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
                        class="h-9 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
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
                        class="text-sm"
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
                        class="text-sm"
                        type="date"
                        required
                    /><InputError :message="form.errors.invoice_date" />
                </div>
            </div>
        </section>

        <section
            class="surface-panel flex min-h-0 flex-1 flex-col gap-2.5 overflow-hidden p-3 sm:p-4"
        >
            <div class="flex shrink-0 items-start justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold">Products</h2>
                    <p class="text-xs text-muted-foreground">
                        Search by name or SKU, then select a product to add it.
                    </p>
                </div>
                <span
                    class="shrink-0 rounded-full bg-muted px-2.5 py-1 text-xs font-medium text-muted-foreground"
                >
                    {{ form.lines.length }}
                    {{ form.lines.length === 1 ? 'item' : 'items' }}
                </span>
            </div>
            <div class="relative shrink-0">
                <Label for="purchase-product-search" class="sr-only"
                    >Search products</Label
                >
                <Search
                    class="pointer-events-none absolute top-2.5 left-3 size-4 text-muted-foreground"
                />
                <Input
                    id="purchase-product-search"
                    v-model="productSearch"
                    class="pl-9 text-sm"
                    autocomplete="off"
                    placeholder="Search product name or SKU"
                    @focus="productPickerOpen = true"
                    @input="productPickerOpen = true"
                    @keydown.enter.prevent="
                        matchingVariants()[0] &&
                        addProduct(matchingVariants()[0])
                    "
                    @keydown.esc="productPickerOpen = false"
                />
                <div
                    v-if="productPickerOpen && productSearch.trim()"
                    class="absolute top-full z-30 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border bg-popover p-1 shadow-lg"
                    role="listbox"
                    aria-label="Product search results"
                >
                    <button
                        v-for="variant in matchingVariants()"
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
                        v-if="matchingVariants().length === 0"
                        class="px-3 py-2 text-sm text-muted-foreground"
                    >
                        No matching products available.
                    </p>
                </div>
            </div>
            <InputError :message="form.errors.lines" />
            <div
                v-if="form.lines.length === 0"
                class="flex min-h-0 flex-1 flex-col items-center justify-center overflow-y-auto rounded-xl border border-dashed p-5 text-center text-sm text-muted-foreground"
            >
                <Search class="mb-2 size-5 opacity-60" />
                <span>Search for a product to start this purchase.</span>
            </div>
            <div
                v-else
                class="min-h-0 flex-1 space-y-3 overflow-y-auto overscroll-contain pr-1"
            >
                <div
                    v-for="(line, index) in form.lines"
                    :key="index"
                    class="space-y-3 rounded-xl border border-border/70 bg-muted/10 p-3"
                >
                    <div
                        class="grid gap-3 sm:grid-cols-2 xl:grid-cols-[minmax(12rem,1.5fr)_repeat(3,minmax(7rem,1fr))_auto]"
                    >
                        <div class="min-w-0 py-1">
                            <p class="truncate font-medium">
                                {{ selectedVariant(line)?.label ?? 'Product' }}
                            </p>
                            <p class="font-mono text-xs text-muted-foreground">
                                {{ selectedVariant(line)?.sku }}
                            </p>
                            <InputError
                                :message="
                                    error(`lines.${index}.product_variant_id`)
                                "
                            />
                        </div>
                        <div v-if="!isSerialized(line)" class="grid gap-2">
                            <Label :for="`purchase-qty-${index}`"
                                >Quantity</Label
                            ><Input
                                :id="`purchase-qty-${index}`"
                                v-model="line.quantity"
                                class="text-sm"
                                type="number"
                                min="0.001"
                                step="0.001"
                                required
                            /><InputError
                                :message="error(`lines.${index}.quantity`)"
                            />
                        </div>
                        <div v-if="!isSerialized(line)" class="grid gap-2">
                            <Label :for="`purchase-cost-${index}`"
                                >Unit cost</Label
                            ><Input
                                :id="`purchase-cost-${index}`"
                                v-model="line.unit_cost"
                                class="text-sm"
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
                                class="text-sm"
                                type="number"
                                min="0"
                                step="0.01"
                            /><InputError
                                :message="
                                    error(`lines.${index}.discount_amount`)
                                "
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
                                v-if="form.lines.length > 0"
                                type="button"
                                variant="ghost"
                                size="icon-sm"
                                class="text-destructive"
                                :aria-label="`Remove item ${index + 1}`"
                                @click="removeLine(index)"
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
                                    Each phone is received by its exact IMEI.
                                    The device cost is recorded separately.
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
                        <InputError
                            :message="error(`lines.${index}.devices`)"
                        />
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
                                <Label
                                    :for="`condition-${index}-${deviceIndex}`"
                                    >Condition</Label
                                ><select
                                    :id="`condition-${index}-${deviceIndex}`"
                                    v-model="device.condition"
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                >
                                    <option value="new">New</option>
                                    <option value="used">Used</option>
                                    <option value="refurbished">
                                        Refurbished
                                    </option>
                                </select>
                            </div>
                            <div class="grid gap-1">
                                <Label
                                    :for="`device-cost-${index}-${deviceIndex}`"
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
                                <Label
                                    :for="`device-price-${index}-${deviceIndex}`"
                                    >Selling price (optional)</Label
                                ><Input
                                    :id="`device-price-${index}-${deviceIndex}`"
                                    v-model="device.unit_selling_price"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                />
                            </div>
                            <div
                                class="flex items-end sm:col-span-2 xl:col-span-2"
                            >
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
            </div>
        </section>

        <section
            class="grid shrink-0 grid-cols-1 gap-2 sm:grid-cols-[minmax(0,1fr)_18rem]"
        >
            <div class="surface-panel grid content-center gap-1.5 p-3">
                <Label for="purchase-notes" class="text-xs"
                    >Notes (optional)</Label
                ><textarea
                    id="purchase-notes"
                    v-model="form.notes"
                    rows="1"
                    maxlength="4000"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                /><InputError :message="form.errors.notes" />
            </div>
            <div class="surface-panel grid gap-1.5 p-3 text-sm">
                <h2 class="font-semibold">Invoice total</h2>
                <div class="flex justify-between gap-2">
                    <span class="text-muted-foreground">Subtotal</span
                    ><span>{{ formatMinor(subtotal) }}</span>
                </div>
                <div
                    class="flex justify-between gap-2 border-t border-border/70 pt-1.5 font-semibold"
                >
                    <span>Total</span><span>{{ formatMinor(total) }}</span>
                </div>
                <p class="text-[10px] leading-tight text-muted-foreground">
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
        <div class="flex shrink-0 flex-wrap justify-end gap-1">
            <Button as-child type="button" size="sm" variant="outline"
                ><Link :href="purchasesIndex()">Cancel</Link></Button
            ><Button
                type="submit"
                size="sm"
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
