<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Plus,
    FileChartColumn,
    PackageX,
    Wrench,
    Smartphone,
} from '@lucide/vue';
import ShiftPagination from '@/components/shifts/ShiftPagination.vue';
import { damageQuantity } from '@/types/damage';
import type { DamageSummary } from '@/types/damage';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
    DialogFooter,
} from '@/components/ui/dialog';

type PageLink = { url: string | null; label: string; active: boolean };
type Entry = {
    id: number;
    product: string;
    variant: string;
    sku: string;
    model_number: string | null;
    category: string;
    brand: string | null;
    location: string;
    quantity: string;
    remaining: string;
    reason: string;
    notes: string | null;
    date: string;
    sale_id: number | null;
    invoice: string | null;
};
const props = defineProps<{
    entries: { data: Entry[]; links: PageLink[] };
    devices: {
        data: {
            id: number;
            product: string;
            variant: string;
            sku: string;
            model_number: string | null;
            category: string;
            brand: string | null;
            imei: string;
            location: string;
        }[];
        links: PageLink[];
    };
    locations: { id: number; name: string }[];
    variants: { id: number; label: string; sku: string }[];
    availableDevices: { id: number; location_id: number; label: string }[];
    filters: {
        search: string;
        location_id: number | '';
        category_id?: number;
        brand_id?: number;
        model_number?: string;
        status: string;
    };
    categories: { id: number; name: string }[];
    brands: { id: number; name: string }[];
    summary: DamageSummary;
    canViewDevices: boolean;
    canAdjust: boolean;
    canCreate: boolean;
    canRepair: boolean;
    canWriteOff: boolean;
    canViewReport: boolean;
    canManageDevices: boolean;
    canViewSales: boolean;
}>();
const moveOpen = ref(false);
const activeTab = ref('quantity');
const category = ref(String(props.filters.category_id ?? ''));
const brand = ref(String(props.filters.brand_id ?? ''));
const model = ref(props.filters.model_number ?? '');
const status = ref(props.filters.status);
const productSearch = ref('');
const filteredVariants = computed(() =>
    props.variants.filter((variant) =>
        `${variant.label} ${variant.sku}`
            .toLowerCase()
            .includes(productSearch.value.toLowerCase().trim()),
    ),
);
const reportUrl = computed(
    () =>
        '/reports/damaged-stock?' +
        new URLSearchParams({
            location_id: location.value,
            category_id: category.value,
            brand_id: brand.value,
            model_number: model.value,
        }).toString(),
);
const search = ref(props.filters.search);
const location = ref(String(props.filters.location_id));
let timer: ReturnType<typeof setTimeout> | undefined;
watch([search, location, category, brand, model, status], () => {
    clearTimeout(timer);
    timer = setTimeout(
        () =>
            router.get(
                '/inventory/damaged',
                {
                    search: search.value,
                    location_id: location.value || undefined,
                    category_id: category.value || undefined,
                    brand_id: brand.value || undefined,
                    model_number: model.value || undefined,
                    status: status.value,
                },
                { preserveState: true, preserveScroll: true, replace: true },
            ),
        250,
    );
});
onBeforeUnmount(() => clearTimeout(timer));
const form = useForm({
    operation_key: crypto.randomUUID(),
    mode: 'quantity',
    location_id: props.locations[0]?.id ?? '',
    product_variant_id: '',
    quantity: '1.000',
    device_id: '',
    notes: '',
});
watch(
    () => [form.location_id, form.mode],
    () => {
        form.device_id = '';
    },
);
const matchingDevices = computed(() =>
    props.availableDevices.filter(
        (device) => device.location_id === Number(form.location_id),
    ),
);
function submit(): void {
    form.post('/inventory/damaged', {
        preserveScroll: true,
        onSuccess: () => {
            moveOpen.value = false;
            productSearch.value = '';
            form.reset();
            form.operation_key = crypto.randomUUID();
        },
    });
}
const selectedEntry = ref<Entry | null>(null);
const resolveOpen = ref(false);
const resolution = useForm({
    operation_key: crypto.randomUUID(),
    action: 'repair',
    quantity: '1.000',
    notes: '',
});
function openResolution(entry: Entry): void {
    selectedEntry.value = entry;
    resolution.reset();
    resolution.action = props.canRepair ? 'repair' : 'write_off';
    resolution.clearErrors();
    resolution.quantity = entry.remaining;
    resolution.operation_key = crypto.randomUUID();
    resolveOpen.value = true;
}
function resolve(): void {
    if (selectedEntry.value)
        resolution.post(
            `/inventory/damaged/${selectedEntry.value.id}/resolve`,
            {
                preserveScroll: true,
                onSuccess: () => {
                    resolveOpen.value = false;
                },
            },
        );
}
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Damaged stock', href: '/inventory/damaged' }],
    },
});
</script>

<template>
    <Head title="Damaged stock" />
    <section class="page-shell space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="Damaged stock"
                description="Track faulty items, customer returns and repairs."
            />
            <div class="flex w-full flex-wrap gap-2 sm:w-auto">
                <Button v-if="canViewReport" as-child variant="outline"
                    ><Link :href="reportUrl"
                        ><FileChartColumn /> View report</Link
                    ></Button
                ><Button v-if="canCreate" @click="moveOpen = true"
                    ><Plus /> Move stock to damaged</Button
                >
            </div>
        </div>
        <div class="grid gap-3 sm:grid-cols-3">
            <article class="surface-panel flex items-center gap-3 p-4">
                <PackageX
                    class="size-9 rounded-lg bg-amber-100 p-2 text-amber-700"
                />
                <div>
                    <p class="text-sm text-muted-foreground">
                        Current damaged SKUs
                    </p>
                    <p class="text-2xl font-semibold">{{ summary.skus }}</p>
                </div>
            </article>
            <article class="surface-panel flex items-center gap-3 p-4">
                <Wrench
                    class="size-9 rounded-lg bg-orange-100 p-2 text-orange-700"
                />
                <div>
                    <p class="text-sm text-muted-foreground">
                        Quantity items awaiting action
                    </p>
                    <p class="text-2xl font-semibold">
                        {{ damageQuantity(summary.quantity) }}
                    </p>
                </div>
            </article>
            <article class="surface-panel flex items-center gap-3 p-4">
                <Smartphone
                    class="size-9 rounded-lg bg-rose-100 p-2 text-rose-700"
                />
                <div>
                    <p class="text-sm text-muted-foreground">
                        Damaged IMEI devices
                    </p>
                    <p class="text-2xl font-semibold">{{ summary.devices }}</p>
                </div>
            </article>
        </div>
        <Dialog v-model:open="moveOpen"
            ><DialogContent class="max-h-[90svh] overflow-y-auto sm:max-w-2xl"
                ><DialogHeader
                    ><DialogTitle>Move stock to damaged</DialogTitle
                    ><DialogDescription
                        >Choose the faulty stock and record the reason.
                        Available stock will decrease by the quantity
                        moved.</DialogDescription
                    ></DialogHeader
                >
                <form
                    v-if="canCreate"
                    class="surface-panel grid gap-3 p-4 md:grid-cols-2"
                    @submit.prevent="submit"
                >
                    <h2 class="font-semibold md:col-span-2">
                        Move available stock to damaged
                    </h2>
                    <label class="grid min-w-0 gap-1 text-sm"
                        >Location<select
                            v-model="form.location_id"
                            required
                            class="h-10 w-full min-w-0 rounded-md border bg-background px-3"
                        >
                            <option
                                v-for="item in locations"
                                :key="item.id"
                                :value="item.id"
                            >
                                {{ item.name }}
                            </option>
                        </select></label
                    >
                    <label class="grid min-w-0 gap-1 text-sm"
                        >Stock type<select
                            v-model="form.mode"
                            class="h-10 w-full min-w-0 rounded-md border bg-background px-3"
                        >
                            <option value="quantity">Quantity item</option>
                            <option v-if="canManageDevices" value="serialized">
                                IMEI device
                            </option>
                        </select></label
                    >
                    <label
                        v-if="form.mode === 'quantity'"
                        class="grid min-w-0 gap-1 text-sm"
                        >Find product<Input
                            v-model="productSearch"
                            placeholder="Search product or SKU"
                    /></label>
                    <label
                        v-if="form.mode === 'quantity'"
                        class="grid min-w-0 gap-1 text-sm"
                        >Product<select
                            v-model="form.product_variant_id"
                            required
                            class="h-10 w-full min-w-0 rounded-md border bg-background px-3"
                        >
                            <option disabled value="">Choose product</option>
                            <option
                                v-for="item in filteredVariants"
                                :key="item.id"
                                :value="item.id"
                            >
                                {{ item.label }} · {{ item.sku }}
                            </option>
                        </select></label
                    >
                    <label v-else class="grid min-w-0 gap-1 text-sm"
                        >Device<select
                            v-model="form.device_id"
                            required
                            class="h-10 w-full min-w-0 rounded-md border bg-background px-3"
                        >
                            <option disabled value="">
                                Choose available IMEI
                            </option>
                            <option
                                v-for="item in matchingDevices"
                                :key="item.id"
                                :value="item.id"
                            >
                                {{ item.label }}
                            </option>
                        </select></label
                    >
                    <label
                        v-if="form.mode === 'quantity'"
                        class="grid min-w-0 gap-1 text-sm"
                        >Quantity<Input
                            v-model="form.quantity"
                            type="number"
                            min="0.001"
                            step="0.001"
                            required
                    /></label>
                    <label class="grid min-w-0 gap-1 text-sm"
                        >Damage reason / notes<Input
                            v-model="form.notes"
                            required
                            maxlength="2000"
                            placeholder="Broken screen, water damage…"
                    /></label>
                    <Button class="self-end" :disabled="form.processing"
                        >Move to damaged stock</Button
                    >
                    <InputError
                        v-for="(message, field) in form.errors"
                        :key="field"
                        :message="message"
                    />
                </form> </DialogContent
        ></Dialog>
        <div class="surface-panel grid gap-3 p-3 sm:grid-cols-2 xl:grid-cols-3">
            <Input
                v-model="search"
                class="max-w-sm"
                placeholder="Product, SKU, invoice, customer or IMEI"
                aria-label="Search damaged stock"
            />
            <select
                v-model="location"
                class="h-10 w-full min-w-0 rounded-md border bg-background px-3"
                aria-label="Filter location"
            >
                <option value="">All accessible locations</option>
                <option
                    v-for="item in locations"
                    :key="item.id"
                    :value="String(item.id)"
                >
                    {{ item.name }}
                </option>
            </select>
            <select
                v-model="category"
                aria-label="Category"
                class="h-10 w-full min-w-0 rounded-md border bg-background px-3"
            >
                <option value="">All categories</option>
                <option
                    v-for="item in categories"
                    :key="item.id"
                    :value="String(item.id)"
                >
                    {{ item.name }}
                </option>
            </select>
            <select
                v-model="brand"
                aria-label="Brand"
                class="h-10 w-full min-w-0 rounded-md border bg-background px-3"
            >
                <option value="">All brands</option>
                <option
                    v-for="item in brands"
                    :key="item.id"
                    :value="String(item.id)"
                >
                    {{ item.name }}
                </option>
            </select>
            <Input
                v-model="model"
                placeholder="Exact model number"
                aria-label="Model number"
            />
            <select
                v-model="status"
                aria-label="Quantity stock status"
                class="h-10 w-full min-w-0 rounded-md border bg-background px-3"
            >
                <option value="active">Awaiting action</option>
                <option value="resolved">Resolved quantity entries</option>
                <option value="all">All quantity entries</option>
            </select>
        </div>
        <div class="flex gap-2" role="tablist" aria-label="Damaged stock type">
            <Button
                role="tab"
                :aria-selected="activeTab === 'quantity'"
                :variant="activeTab === 'quantity' ? 'default' : 'outline'"
                @click="activeTab = 'quantity'"
                >Quantity items</Button
            ><Button
                role="tab"
                :aria-selected="activeTab === 'devices'"
                :variant="activeTab === 'devices' ? 'default' : 'outline'"
                @click="activeTab = 'devices'"
                >IMEI devices</Button
            >
        </div>
        <div
            v-show="activeTab === 'quantity'"
            class="table-panel overflow-x-auto"
        >
            <h2 class="p-4 font-semibold">Quantity items</h2>
            <div class="space-y-3 px-3 pb-3 sm:hidden">
                <article
                    v-for="entry in entries.data"
                    :key="entry.id"
                    class="rounded-lg border p-3"
                >
                    <div class="flex items-start justify-between gap-3">
                        <p class="min-w-0 font-semibold">
                            {{ entry.product }} · {{ entry.variant }}
                        </p>
                        <div
                            class="shrink-0 rounded-md bg-amber-50 px-3 py-2 text-center text-amber-900"
                        >
                            <strong class="text-xl">{{
                                damageQuantity(entry.remaining)
                            }}</strong>
                            <p class="text-[10px]">Damaged</p>
                        </div>
                    </div>
                    <p class="mt-2 font-mono text-xs break-all">
                        SKU {{ entry.sku }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Model {{ entry.model_number || '—' }} ·
                        {{ entry.category }} · {{ entry.brand || 'Unbranded' }}
                    </p>
                    <p class="mt-2 text-sm">
                        {{ entry.location }} · Received
                        {{ damageQuantity(entry.quantity) }}
                    </p>
                    <p class="text-sm">{{ entry.reason }}</p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ entry.date }}
                    </p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <Button
                            v-if="canAdjust && Number(entry.remaining) > 0"
                            variant="outline"
                            size="sm"
                            @click="openResolution(entry)"
                            >{{
                                canRepair && canWriteOff
                                    ? 'Repair / Write off'
                                    : canRepair
                                      ? 'Return repaired stock'
                                      : 'Write off'
                            }}</Button
                        ><span
                            v-else-if="Number(entry.remaining) === 0"
                            class="text-xs text-muted-foreground"
                            >Resolved</span
                        ><Button
                            v-if="entry.sale_id && canViewSales"
                            as-child
                            variant="ghost"
                            size="sm"
                            ><Link :href="`/sales/${entry.sale_id}`"
                                >View invoice</Link
                            ></Button
                        >
                    </div>
                </article>
                <p
                    v-if="entries.data.length === 0"
                    class="p-5 text-center text-sm text-muted-foreground"
                >
                    No damaged items match these filters.
                </p>
            </div>

            <table class="data-table hidden min-w-[1000px] table-auto sm:table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Location</th>
                        <th>Received</th>
                        <th>Remaining damaged</th>
                        <th>Source / Reason</th>
                        <th>Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="entry in entries.data" :key="entry.id">
                        <td>
                            {{ entry.product }} · {{ entry.variant }}
                            <p class="text-xs text-muted-foreground">
                                SKU {{ entry.sku }} · Model
                                {{ entry.model_number || '—' }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ entry.category }} ·
                                {{ entry.brand || 'Unbranded' }}
                            </p>
                        </td>
                        <td>{{ entry.location }}</td>
                        <td>
                            {{ damageQuantity(entry.quantity) }}
                        </td>
                        <td>
                            <strong>{{
                                damageQuantity(entry.remaining)
                            }}</strong>
                        </td>
                        <td>
                            <Link
                                v-if="entry.sale_id && canViewSales"
                                :href="`/sales/${entry.sale_id}`"
                                class="underline"
                                >{{ entry.invoice }}</Link
                            >
                            <p>{{ entry.reason }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ entry.notes }}
                            </p>
                        </td>
                        <td>{{ entry.date }}</td>
                        <td>
                            <Button
                                v-if="canAdjust && Number(entry.remaining) > 0"
                                variant="outline"
                                size="sm"
                                @click="openResolution(entry)"
                                >{{
                                    canRepair && canWriteOff
                                        ? 'Repair / Write off'
                                        : canRepair
                                          ? 'Return repaired stock'
                                          : 'Write off'
                                }}</Button
                            ><span v-else-if="Number(entry.remaining) === 0"
                                >Resolved</span
                            >
                        </td>
                    </tr>
                    <tr v-if="entries.data.length === 0">
                        <td
                            colspan="7"
                            class="p-8 text-center text-muted-foreground"
                        >
                            No damaged quantity items found.
                        </td>
                    </tr>
                </tbody>
            </table>
            <ShiftPagination :links="entries.links" />
        </div>
        <div
            v-show="activeTab === 'devices'"
            class="table-panel overflow-x-auto"
        >
            <h2 class="p-4 font-semibold">Damaged IMEI devices</h2>
            <div class="space-y-3 px-3 pb-3 sm:hidden">
                <article
                    v-for="device in devices.data"
                    :key="device.id"
                    class="rounded-lg border p-3"
                >
                    <p class="font-semibold">
                        {{ device.product }} · {{ device.variant }}
                    </p>
                    <p class="mt-2 font-mono text-xs break-all">
                        IMEI {{ device.imei }}
                    </p>
                    <p class="text-xs">
                        SKU {{ device.sku }} · Model
                        {{ device.model_number || '—' }}
                    </p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ device.location }} ·
                        {{ device.brand || 'Unbranded' }}
                    </p>
                    <Button
                        v-if="canManageDevices && canViewDevices"
                        as-child
                        variant="outline"
                        size="sm"
                        class="mt-3"
                        ><Link
                            :href="`/inventory/devices?search=${device.imei}`"
                            >Inspect / repair</Link
                        ></Button
                    >
                </article>
                <p
                    v-if="devices.data.length === 0"
                    class="p-5 text-center text-sm text-muted-foreground"
                >
                    No damaged devices match these filters.
                </p>
            </div>

            <table class="data-table hidden sm:table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>IMEI</th>
                        <th>Location</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="device in devices.data" :key="device.id">
                        <td>
                            <p>{{ device.product }} · {{ device.variant }}</p>
                            <p class="text-xs text-muted-foreground">
                                SKU {{ device.sku }} · Model
                                {{ device.model_number || '—' }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ device.category }} ·
                                {{ device.brand || 'Unbranded' }}
                            </p>
                        </td>
                        <td>{{ device.imei }}</td>
                        <td>{{ device.location }}</td>
                        <td>
                            <Link
                                v-if="canManageDevices && canViewDevices"
                                :href="`/inventory/devices?search=${device.imei}`"
                                class="underline"
                                >Inspect / repair device</Link
                            >
                        </td>
                    </tr>
                    <tr v-if="devices.data.length === 0">
                        <td
                            colspan="4"
                            class="p-8 text-center text-muted-foreground"
                        >
                            No damaged devices found.
                        </td>
                    </tr>
                </tbody>
            </table>
            <ShiftPagination :links="devices.links" />
        </div>
    </section>
    <Dialog v-model:open="resolveOpen"
        ><DialogContent
            ><DialogHeader
                ><DialogTitle>Resolve damaged stock</DialogTitle
                ><DialogDescription
                    >{{ selectedEntry?.product }} · Remaining
                    {{ selectedEntry?.remaining }}. Repaired items return to
                    saleable stock; written-off items do not.</DialogDescription
                ></DialogHeader
            >
            <form class="space-y-3" @submit.prevent="resolve">
                <Label for="damage-action">Action</Label
                ><select
                    id="damage-action"
                    v-model="resolution.action"
                    class="h-10 w-full rounded-md border bg-background px-3"
                >
                    <option v-if="canRepair" value="repair">
                        Repaired — return to available stock
                    </option>
                    <option v-if="canWriteOff" value="write_off">
                        Write off — remove from damaged stock
                    </option>
                </select>
                <Label for="damage-quantity">Quantity</Label
                ><Input
                    id="damage-quantity"
                    v-model="resolution.quantity"
                    type="number"
                    min="0.001"
                    step="0.001"
                    :max="selectedEntry?.remaining"
                    required
                />
                <Label for="damage-notes">Reason / notes</Label
                ><Input
                    id="damage-notes"
                    v-model="resolution.notes"
                    required
                    maxlength="2000"
                />
                <InputError
                    v-for="(message, field) in resolution.errors"
                    :key="field"
                    :message="message"
                />
                <DialogFooter
                    ><Button :disabled="resolution.processing"
                        >Confirm stock update</Button
                    ></DialogFooter
                >
            </form>
        </DialogContent></Dialog
    >
</template>

<style scoped>
:deep(.data-table th) {
    white-space: normal;
}
:deep(.data-table td:first-child) {
    min-width: 14rem;
}
</style>
