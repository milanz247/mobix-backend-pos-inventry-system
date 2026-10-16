<script setup lang="ts">
import { Head, InfiniteScroll, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    Barcode,
    ChevronDown,
    Plus,
    Search,
    ShoppingCart,
    Trash2,
} from '@lucide/vue';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useConfirmationDialog } from '@/composables/useConfirmationDialog';
import inventoryRoutes from '@/routes/inventory';
import { index, checkout } from '@/routes/pos';
import { index as salesIndex } from '@/routes/sales';

type Variant = {
    id: number;
    name: string;
    variant: string;
    sku: string;
    barcode: string | null;
    category: string;
    brand: string | null;
    product_type: string;
    tracking_mode: 'serialized' | 'quantity_based';
    price: string;
    minimum_selling_price: string | null;
    image_url: string | null;
    quantity_on_hand: string;
    devices_in_stock: number;
    is_virtual_combo: boolean;
};
type MatchingDevice = {
    product_variant_id: number;
    identifier: string;
    imei: string;
    product_name: string;
    variant_name: string;
    sku: string;
    condition: string;
    price: string;
};
type CartLine = {
    variant: Variant;
    quantity: string;
    unit_price: string;
    discount_amount: string;
    device_identifier: string;
};
type Option = { id: number; name: string };
type Payment = {
    amount: string;
    method: string;
    reference: string;
    cash_tendered: string;
};
type HeldCart = {
    id: string;
    held_at: string;
    location_id: number;
    lines: CartLine[];
    order_discount: string;
};

const props = defineProps<{
    variants: { data: Variant[] };
    matchingDevice: MatchingDevice | null;
    locations: (Option & { code: string })[];
    selectedLocation: Option & { code: string };
    categories: Option[];
    brands: Option[];
    filters: {
        search: string;
        category_id: number | null;
        brand_id: number | null;
        location_id: number;
    };
    currency: string;
    cashierId: number;
    canOverridePrice: boolean;
    canDiscount: boolean;
    canCredit: boolean;
    canReceiveStock: boolean;
    canOpenStock: boolean;
    saleSources: { value: string; label: string }[];
}>();

const search = ref(props.filters.search);
const categoryId = ref(String(props.filters.category_id ?? ''));
const brandId = ref(String(props.filters.brand_id ?? ''));
const locationId = ref(String(props.filters.location_id));
const cart = ref<CartLine[]>([]);
const heldCarts = ref<HeldCart[]>([]);
const orderDiscount = ref('0.00');
const customerName = ref('');
const customerPhone = ref('');
const customerEmail = ref('');
const saleSource = ref('in_store_pos');
const notes = ref('');
const currentDateTime = ref<Date | null>(null);
const checkoutModalOpen = ref(false);
const payments = ref<Payment[]>([
    { amount: '', method: 'cash', reference: '', cash_tendered: '' },
]);
const confirmation = useConfirmationDialog();
const heldCartStorageKey = `nalakamobile:pos-held-carts:${props.cashierId}`;
const form = useForm({
    operation_key: crypto.randomUUID(),
    location_id: Number(locationId.value),
    customer_name: '',
    customer_phone: '',
    customer_email: '',
    source: 'in_store_pos',
    order_discount: '0.00',
    notes: '',
    lines: [] as {
        product_variant_id: number;
        quantity: string;
        unit_price: string | null;
        discount_amount: string;
        device_identifier?: string;
    }[],
    payments: [] as {
        amount: string;
        method: string;
        reference?: string;
        cash_tendered?: string;
    }[],
});
const checkoutErrors = computed(() => Object.entries(form.errors));
const hasUnavailableProducts = computed(() =>
    props.variants.data.some(
        (variant) =>
            !variant.is_virtual_combo &&
            (variant.tracking_mode === 'serialized'
                ? variant.devices_in_stock < 1
                : thousandths(variant.quantity_on_hand) < 1000n),
    ),
);
const stockEntryHref = computed(() => {
    const options = { query: { location_id: Number(locationId.value) } };

    if (props.canReceiveStock) {
        return inventoryRoutes.receiving.create(options).url;
    }

    if (props.canOpenStock) {
        return inventoryRoutes.opening.create(options).url;
    }

    return null;
});
const currentDate = computed(() =>
    currentDateTime.value?.toLocaleDateString(undefined, {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    }),
);
const currentTime = computed(() =>
    currentDateTime.value?.toLocaleTimeString(undefined, {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    }),
);

let searchTimer: ReturnType<typeof setTimeout> | undefined;
let clockTimer: ReturnType<typeof setInterval> | undefined;
let sidebarWrapper: HTMLElement | null = null;
let originalDocumentOverflow = '';
let originalBodyOverflow = '';
let originalSidebarWrapperHeight = '';
let originalSidebarWrapperMinHeight = '';
let originalSidebarWrapperOverflow = '';
watch([search, categoryId, brandId, locationId], () => {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        router.get(
            index.url(),
            {
                search: search.value || undefined,
                category_id: categoryId.value || undefined,
                brand_id: brandId.value || undefined,
                location_id: locationId.value,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                reset: ['variants'],
                only: [
                    'variants',
                    'matchingDevice',
                    'filters',
                    'selectedLocation',
                ],
            },
        );
    }, 250);
});
onBeforeUnmount(() => {
    if (searchTimer) clearTimeout(searchTimer);
    if (clockTimer) clearInterval(clockTimer);

    document.documentElement.style.overflow = originalDocumentOverflow;
    document.body.style.overflow = originalBodyOverflow;

    if (sidebarWrapper) {
        sidebarWrapper.style.height = originalSidebarWrapperHeight;
        sidebarWrapper.style.minHeight = originalSidebarWrapperMinHeight;
        sidebarWrapper.style.overflow = originalSidebarWrapperOverflow;
    }
});
onMounted(() => {
    originalDocumentOverflow = document.documentElement.style.overflow;
    originalBodyOverflow = document.body.style.overflow;
    document.documentElement.style.overflow = 'hidden';
    document.body.style.overflow = 'hidden';

    sidebarWrapper = document.querySelector<HTMLElement>(
        '[data-slot="sidebar-wrapper"]',
    );

    if (sidebarWrapper) {
        originalSidebarWrapperHeight = sidebarWrapper.style.height;
        originalSidebarWrapperMinHeight = sidebarWrapper.style.minHeight;
        originalSidebarWrapperOverflow = sidebarWrapper.style.overflow;
        sidebarWrapper.style.height = '100svh';
        sidebarWrapper.style.minHeight = '0';
        sidebarWrapper.style.overflow = 'hidden';
    }

    currentDateTime.value = new Date();
    clockTimer = setInterval(() => {
        currentDateTime.value = new Date();
    }, 1000);

    try {
        const stored = window.localStorage.getItem(heldCartStorageKey);
        const parsed = stored ? (JSON.parse(stored) as HeldCart[]) : [];
        heldCarts.value = Array.isArray(parsed)
            ? parsed.filter(
                  (held) =>
                      Date.now() - Date.parse(held.held_at) <
                      7 * 24 * 60 * 60 * 1000,
              )
            : [];
        saveHeldCarts();
    } catch {
        heldCarts.value = [];
    }
});

function minorUnits(value: string): bigint {
    const normalized = value.trim().match(/^(\d+)(?:\.(\d{0,2}))?$/);
    if (!normalized) return 0n;
    return (
        BigInt(normalized[1]) * 100n +
        BigInt((normalized[2] ?? '').padEnd(2, '0'))
    );
}
function thousandths(value: string): bigint {
    const normalized = value.trim().match(/^(\d+)(?:\.(\d{0,3}))?$/);
    if (!normalized) return 0n;
    return (
        BigInt(normalized[1]) * 1000n +
        BigInt((normalized[2] ?? '').padEnd(3, '0'))
    );
}
function formatMinor(value: bigint): string {
    const sign = value < 0n ? '-' : '';
    const absolute = value < 0n ? -value : value;
    return `${sign}${absolute / 100n}.${(absolute % 100n).toString().padStart(2, '0')}`;
}
function money(value: bigint): string {
    const formatted = formatMinor(value);
    const [whole, fraction] = formatted.split('.');
    return `${props.currency} ${BigInt(whole || '0').toLocaleString()}.${fraction}`;
}
function lineSubtotal(line: CartLine): bigint {
    return (
        (minorUnits(line.unit_price) * thousandths(line.quantity) + 500n) /
        1000n
    );
}
function lineTotal(line: CartLine): bigint {
    return lineSubtotal(line) > minorUnits(line.discount_amount)
        ? lineSubtotal(line) - minorUnits(line.discount_amount)
        : 0n;
}
function cartSubtotal(): bigint {
    return cart.value.reduce((sum, line) => sum + lineSubtotal(line), 0n);
}
function cartTotal(): bigint {
    const beforeOrderDiscount = cart.value.reduce(
        (sum, line) => sum + lineTotal(line),
        0n,
    );
    return beforeOrderDiscount > minorUnits(orderDiscount.value)
        ? beforeOrderDiscount - minorUnits(orderDiscount.value)
        : 0n;
}
function addVariant(variant: Variant): void {
    if (!canAddVariant(variant)) return;
    if (variant.tracking_mode === 'serialized' && !variant.is_virtual_combo) {
        cart.value.push({
            variant,
            quantity: '1.000',
            unit_price: variant.price,
            discount_amount: '0.00',
            device_identifier: '',
        });
        return;
    }
    const existing = cart.value.find(
        (line) =>
            line.variant.id === variant.id &&
            line.variant.tracking_mode === 'quantity_based',
    );
    if (existing) {
        existing.quantity = formatQuantity(
            thousandths(existing.quantity) + 1000n,
        );
        return;
    }
    cart.value.push({
        variant,
        quantity: '1.000',
        unit_price: variant.price,
        discount_amount: '0.00',
        device_identifier: '',
    });
}
function canAddVariant(variant: Variant): boolean {
    if (variant.is_virtual_combo) return true;

    return variant.tracking_mode === 'serialized'
        ? variant.devices_in_stock > 0
        : thousandths(variant.quantity_on_hand) >= 1000n;
}
function formatQuantity(value: bigint): string {
    return `${value / 1000n}.${(value % 1000n).toString().padStart(3, '0')}`;
}
function removeLine(index: number): void {
    cart.value.splice(index, 1);
}
function saveHeldCarts(): void {
    window.localStorage.setItem(
        heldCartStorageKey,
        JSON.stringify(heldCarts.value.slice(0, 10)),
    );
}
function copyCartLines(lines: CartLine[]): CartLine[] {
    return lines.map((line) => ({
        variant: { ...line.variant },
        quantity: line.quantity,
        unit_price: line.unit_price,
        discount_amount: line.discount_amount,
        device_identifier: line.device_identifier,
    }));
}
function holdCart(): void {
    if (cart.value.length === 0) return;
    heldCarts.value.unshift({
        id: crypto.randomUUID(),
        held_at: new Date().toISOString(),
        location_id: Number(locationId.value),
        lines: copyCartLines(cart.value),
        order_discount: orderDiscount.value,
    });
    heldCarts.value = heldCarts.value.slice(0, 10);
    saveHeldCarts();
    cart.value = [];
    orderDiscount.value = '0.00';
    customerName.value = '';
    customerPhone.value = '';
    customerEmail.value = '';
    notes.value = '';
    payRemaining();
}
function resumeHeldCart(id: string): void {
    const held = heldCarts.value.find((item) => item.id === id);
    if (!held) return;
    if (cart.value.length > 0) holdCart();
    cart.value = held.lines;
    locationId.value = String(held.location_id);
    orderDiscount.value = held.order_discount;
    heldCarts.value = heldCarts.value.filter((item) => item.id !== id);
    saveHeldCarts();
    payRemaining();
}
function selectHeldCart(event: Event): void {
    const select = event.target as HTMLSelectElement;
    if (select.value !== '') resumeHeldCart(select.value);
    select.value = '';
}
function clearCart(): void {
    if (cart.value.length === 0) return;
    confirmation.request({
        title: 'Clear the current cart?',
        description:
            'This removes the unsaved cart only. It does not change inventory or completed sales.',
        confirmLabel: 'Clear cart',
        destructive: true,
        onConfirm: () => {
            cart.value = [];
            orderDiscount.value = '0.00';
            customerName.value = '';
            customerPhone.value = '';
            customerEmail.value = '';
            notes.value = '';
            payRemaining();
        },
    });
}
function addPayment(): void {
    payments.value.push({
        amount: '',
        method: 'cash',
        reference: '',
        cash_tendered: '',
    });
}
function removePayment(index: number): void {
    if (payments.value.length > 1) payments.value.splice(index, 1);
}
function payRemaining(): void {
    payments.value = [
        {
            amount: formatMinor(cartTotal()),
            method: 'cash',
            reference: '',
            cash_tendered: formatMinor(cartTotal()),
        },
    ];
}
function scanSearch(): void {
    const scannedValue = search.value.trim();
    const match = props.variants.data.find(
        (variant) =>
            variant.sku.toLowerCase() === scannedValue.toLowerCase() ||
            variant.barcode === scannedValue,
    );
    if (match) {
        addVariant(match);
        search.value = '';
        return;
    }
    const normalizedIdentifier = scannedValue.replace(/\s+/g, '').toUpperCase();
    if (props.matchingDevice?.identifier === normalizedIdentifier) {
        const device = props.matchingDevice;
        const variant: Variant = {
            id: device.product_variant_id,
            name: device.product_name,
            variant: device.variant_name,
            sku: device.sku,
            barcode: null,
            category: '',
            brand: null,
            product_type: 'serialized',
            tracking_mode: 'serialized',
            price: device.price,
            minimum_selling_price: null,
            image_url: null,
            quantity_on_hand: '0.000',
            devices_in_stock: 1,
            is_virtual_combo: false,
        };
        cart.value.push({
            variant,
            quantity: '1.000',
            unit_price: device.price,
            discount_amount: '0.00',
            device_identifier: device.identifier,
        });
        search.value = '';
        return;
    }
    if (scannedValue !== '' && props.filters.search !== scannedValue) {
        if (searchTimer) clearTimeout(searchTimer);
        router.get(
            index.url(),
            {
                search: scannedValue,
                category_id: categoryId.value || undefined,
                brand_id: brandId.value || undefined,
                location_id: locationId.value,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                reset: ['variants'],
                only: [
                    'variants',
                    'matchingDevice',
                    'filters',
                    'selectedLocation',
                ],
                onSuccess: () => scanSearch(),
            },
        );
    }
}
function submitCheckout(): void {
    if (cart.value.length === 0) return;
    form.location_id = Number(locationId.value);
    form.customer_name = customerName.value;
    form.customer_phone = customerPhone.value;
    form.customer_email = customerEmail.value;
    form.source = saleSource.value;
    form.order_discount = orderDiscount.value;
    form.notes = notes.value;
    form.lines = cart.value.map((line) => ({
        product_variant_id: line.variant.id,
        quantity: line.quantity,
        unit_price: props.canOverridePrice ? line.unit_price : null,
        discount_amount: props.canDiscount ? line.discount_amount : '0.00',
        ...(line.variant.tracking_mode === 'serialized' &&
        !line.variant.is_virtual_combo
            ? { device_identifier: line.device_identifier }
            : {}),
    }));
    form.payments = payments.value
        .filter((payment) => payment.amount !== '')
        .map((payment) => ({
            amount: payment.amount,
            method: payment.method,
            reference: payment.reference || undefined,
            cash_tendered:
                payment.method === 'cash' && payment.cash_tendered !== ''
                    ? payment.cash_tendered
                    : undefined,
        }));

    if (form.payments.length === 0 && cartTotal() > 0n) {
        form.payments = [{ amount: formatMinor(cartTotal()), method: 'cash' }];
    }

    form.post(checkout().url, { preserveScroll: true });
}
defineOptions({
    layout: { breadcrumbs: [{ title: 'POS checkout', href: index() }] },
});
</script>

<template>
    <div
        class="page-shell h-0 min-h-0 w-full max-w-full flex-1 overflow-hidden"
    >
        <Head title="POS checkout" />
        <section
            class="flex min-h-0 min-w-0 flex-1 flex-col gap-3 overflow-hidden"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <Heading
                    title="Point of sale"
                    description="Find products, build the order, and take payment."
                    variant="small"
                />
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <div
                        class="rounded-lg border border-border/70 bg-card px-3 py-1.5 shadow-xs"
                        aria-live="off"
                    >
                        <p
                            class="text-xs font-medium whitespace-nowrap tabular-nums"
                        >
                            <span class="text-muted-foreground">{{
                                currentDate ?? 'Loading date…'
                            }}</span>
                            <span class="px-1.5 text-muted-foreground/60"
                                >·</span
                            >
                            <span>{{ currentTime ?? '--:--:--' }}</span>
                        </p>
                    </div>
                    <Button as-child variant="outline" size="sm"
                        ><Link :href="salesIndex()">Sales history</Link></Button
                    >
                </div>
            </div>

            <div
                v-if="hasUnavailableProducts"
                role="status"
                class="surface-panel flex flex-col gap-3 border-amber-300/70 bg-amber-50/70 p-3 text-sm text-amber-950 sm:flex-row sm:items-center sm:justify-between dark:border-amber-800 dark:bg-amber-950/20 dark:text-amber-100"
            >
                <p>
                    Products without stock at the selected location cannot be
                    sold. Add stock through receiving or opening stock first.
                </p>
                <Link
                    v-if="stockEntryHref"
                    :href="stockEntryHref"
                    class="shrink-0 font-semibold underline underline-offset-4"
                >
                    Add stock at {{ selectedLocation.name }}
                </Link>
            </div>

            <div
                class="grid min-h-0 min-w-0 flex-1 gap-3 overflow-hidden lg:grid-cols-[minmax(0,1.4fr)_minmax(320px,0.6fr)]"
            >
                <div
                    class="flex min-h-0 min-w-0 flex-col gap-2 overflow-hidden"
                >
                    <div
                        class="surface-panel grid gap-2 p-2 md:grid-cols-2 xl:grid-cols-[minmax(12rem,1.6fr)_repeat(3,minmax(8rem,0.7fr))]"
                    >
                        <div class="relative md:col-span-2 xl:col-span-1">
                            <Search
                                class="pointer-events-none absolute top-2.5 left-3 size-4 text-muted-foreground"
                            />
                            <Input
                                v-model="search"
                                class="bg-background pl-9"
                                aria-label="Search products or scan barcode or IMEI"
                                placeholder="Search or scan a product"
                                @keydown.enter.prevent="scanSearch"
                            />
                        </div>
                        <select
                            v-model="categoryId"
                            class="rounded-md border border-input bg-background px-3 text-sm"
                            aria-label="Filter products by category"
                        >
                            <option value="">All categories</option>
                            <option
                                v-for="category in categories"
                                :key="category.id"
                                :value="String(category.id)"
                            >
                                {{ category.name }}
                            </option>
                        </select>
                        <select
                            v-model="brandId"
                            class="rounded-md border border-input bg-background px-3 text-sm"
                            aria-label="Filter products by brand"
                        >
                            <option value="">All brands</option>
                            <option
                                v-for="brand in brands"
                                :key="brand.id"
                                :value="String(brand.id)"
                            >
                                {{ brand.name }}
                            </option>
                        </select>
                        <select
                            v-model="locationId"
                            class="rounded-md border border-input bg-background px-3 text-sm md:col-span-2 xl:col-span-1"
                            aria-label="Sales location"
                        >
                            <option
                                v-for="location in locations"
                                :key="location.id"
                                :value="String(location.id)"
                            >
                                {{ location.name }} · {{ location.code }}
                            </option>
                        </select>
                    </div>

                    <button
                        v-if="matchingDevice"
                        type="button"
                        class="surface-panel flex w-full items-center justify-between gap-3 border-primary/30 p-3 text-left"
                        @click="scanSearch"
                    >
                        <span
                            ><span class="block font-semibold"
                                >{{ matchingDevice.product_name }} ·
                                {{ matchingDevice.variant_name }}</span
                            ><span class="text-xs text-muted-foreground"
                                >IMEI {{ matchingDevice.imei }} ·
                                {{ matchingDevice.condition }} ·
                                {{ matchingDevice.sku }}</span
                            ></span
                        >
                        <span class="shrink-0 text-sm font-semibold"
                            >Add ·
                            {{ money(minorUnits(matchingDevice.price)) }}</span
                        >
                    </button>

                    <div class="flex items-center justify-between gap-2 px-1">
                        <h2 class="text-sm font-semibold">Products</h2>
                        <Badge variant="secondary"
                            >{{ variants.data.length }} loaded</Badge
                        >
                    </div>

                    <div
                        class="min-h-0 min-w-0 flex-1 overflow-x-hidden overflow-y-auto pr-1"
                    >
                        <InfiniteScroll
                            data="variants"
                            preserve-url
                            :buffer="320"
                            class="grid gap-2 pb-2 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4"
                        >
                            <button
                                v-for="variant in variants.data"
                                :key="variant.id"
                                type="button"
                                class="surface-panel group flex min-h-22 gap-2 p-2 text-left transition duration-150 hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md active:translate-y-0 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:translate-y-0"
                                :disabled="
                                    !variant.is_virtual_combo &&
                                    (variant.tracking_mode === 'serialized'
                                        ? variant.devices_in_stock < 1
                                        : thousandths(
                                              variant.quantity_on_hand,
                                          ) < 1000n)
                                "
                                @click="addVariant(variant)"
                            >
                                <img
                                    v-if="variant.image_url"
                                    :src="variant.image_url"
                                    :alt="variant.name"
                                    class="size-12 shrink-0 rounded-md border object-cover"
                                />
                                <div
                                    v-else
                                    class="grid size-12 shrink-0 place-items-center rounded-md bg-primary/8 text-primary"
                                >
                                    <Barcode class="size-5" />
                                </div>
                                <span class="min-w-0 flex-1">
                                    <span
                                        class="block truncate text-sm leading-4 font-semibold"
                                        >{{ variant.name }}</span
                                    >
                                    <span
                                        class="block truncate text-xs text-muted-foreground"
                                        >{{ variant.variant }} ·
                                        {{ variant.sku }}</span
                                    >
                                    <span
                                        class="mt-0.5 block text-xs font-semibold tabular-nums"
                                        >{{
                                            money(minorUnits(variant.price))
                                        }}</span
                                    >
                                    <Badge
                                        v-if="variant.is_virtual_combo"
                                        variant="secondary"
                                        class="mt-0.5 max-w-full text-[0.65rem] leading-3 whitespace-normal"
                                        >Virtual bundle · components checked at
                                        checkout</Badge
                                    >
                                    <span
                                        v-else
                                        class="mt-0.5 block text-[0.68rem] leading-3 text-muted-foreground"
                                        >{{
                                            variant.tracking_mode ===
                                            'serialized'
                                                ? variant.devices_in_stock === 0
                                                    ? 'No devices at this location'
                                                    : `${variant.devices_in_stock} devices at location`
                                                : thousandths(
                                                        variant.quantity_on_hand,
                                                    ) === 0n
                                                  ? 'No stock at this location'
                                                  : `${variant.quantity_on_hand} in stock`
                                        }}</span
                                    >
                                </span>
                                <Plus
                                    class="size-6 shrink-0 rounded-full bg-primary/10 p-1.5 text-primary transition group-hover:bg-primary group-hover:text-primary-foreground"
                                />
                            </button>
                            <div
                                v-if="variants.data.length === 0"
                                class="surface-panel col-span-full p-10 text-center text-muted-foreground"
                            >
                                No active products match this search.
                            </div>
                            <template #next="{ loading, hasMore }">
                                <div
                                    v-if="loading || hasMore"
                                    class="col-span-full py-4 text-center text-sm text-muted-foreground"
                                >
                                    {{
                                        loading
                                            ? 'Loading more products…'
                                            : 'Scroll for more products'
                                    }}
                                </div>
                            </template>
                        </InfiniteScroll>
                    </div>
                </div>

                <aside
                    class="surface-panel flex h-fit min-w-0 flex-col overflow-hidden lg:h-full lg:min-h-0"
                >
                    <div
                        class="flex items-center justify-between gap-2 border-b bg-muted/40 p-3"
                    >
                        <div>
                            <div class="flex items-center gap-2">
                                <span
                                    class="grid size-8 place-items-center rounded-lg bg-primary text-primary-foreground shadow-sm"
                                >
                                    <ShoppingCart class="size-4" />
                                </span>
                                <div>
                                    <h2 class="font-semibold">Current order</h2>
                                    <p class="text-xs text-muted-foreground">
                                        {{ selectedLocation.name }}
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-sm text-muted-foreground">
                                {{ cart.length }} item{{
                                    cart.length === 1 ? '' : 's'
                                }}
                            </p>
                        </div>
                    </div>

                    <div
                        class="min-h-0 min-w-0 flex-1 space-y-3 overflow-x-hidden overflow-y-auto p-3"
                    >
                        <div class="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                :disabled="cart.length === 0"
                                @click="holdCart"
                                >Hold cart</Button
                            >
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                :disabled="cart.length === 0"
                                @click="clearCart"
                                >Clear cart</Button
                            >
                            <select
                                v-if="heldCarts.length"
                                class="h-9 min-w-0 flex-1 rounded-md border border-input bg-background px-2 text-sm"
                                aria-label="Held carts"
                                @change="selectHeldCart"
                            >
                                <option value="">
                                    Resume held cart ({{ heldCarts.length }})
                                </option>
                                <option
                                    v-for="held in heldCarts"
                                    :key="held.id"
                                    :value="held.id"
                                >
                                    {{
                                        new Date(held.held_at).toLocaleString()
                                    }}
                                    · {{ held.lines.length }} lines
                                </option>
                            </select>
                        </div>
                        <p
                            v-if="heldCarts.length"
                            class="text-xs text-muted-foreground"
                        >
                            Held carts stay on this device and do not reserve
                            stock.
                        </p>
                        <div
                            v-if="cart.length === 0"
                            class="grid min-h-28 place-items-center rounded-xl border border-dashed bg-muted/20 p-4 text-center text-sm text-muted-foreground"
                        >
                            <div class="space-y-2">
                                <ShoppingCart
                                    class="mx-auto size-8 opacity-50"
                                />
                                <p class="font-medium text-foreground">
                                    Your order is empty
                                </p>
                                <p>Select a product to get started.</p>
                            </div>
                        </div>
                        <div v-else class="space-y-2">
                            <div
                                v-for="(line, index) in cart"
                                :key="`${line.variant.id}-${index}`"
                                class="rounded-lg border bg-background p-2.5 shadow-xs"
                            >
                                <div
                                    class="flex items-start justify-between gap-2"
                                >
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-medium">
                                            {{ line.variant.name }} ·
                                            {{ line.variant.variant }}
                                        </p>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ line.variant.sku }} ·
                                            {{
                                                money(
                                                    minorUnits(line.unit_price),
                                                )
                                            }}
                                            each
                                        </p>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon-sm"
                                        aria-label="Remove cart line"
                                        @click="removeLine(index)"
                                        ><Trash2 class="size-4"
                                    /></Button>
                                </div>
                                <div
                                    v-if="
                                        line.variant.tracking_mode ===
                                            'serialized' &&
                                        !line.variant.is_virtual_combo
                                    "
                                    class="mt-1.5 space-y-1"
                                >
                                    <Label :for="`imei-${index}`"
                                        >IMEI / IMEI2</Label
                                    ><Input
                                        :id="`imei-${index}`"
                                        v-model="line.device_identifier"
                                        autocomplete="off"
                                        placeholder="Scan or enter device IMEI"
                                    />
                                    <p class="text-xs text-muted-foreground">
                                        The exact available device and its
                                        actual cost will be verified at
                                        checkout.
                                    </p>
                                </div>
                                <div class="mt-2 grid gap-2 sm:grid-cols-3">
                                    <div>
                                        <Label :for="`qty-${index}`"
                                            >Quantity</Label
                                        >
                                        <div
                                            v-if="
                                                line.variant.tracking_mode ===
                                                    'serialized' &&
                                                !line.variant.is_virtual_combo
                                            "
                                            class="flex h-9 items-center rounded-md border px-3 text-sm"
                                        >
                                            1 device
                                        </div>
                                        <Input
                                            v-else
                                            :id="`qty-${index}`"
                                            v-model="line.quantity"
                                            inputmode="decimal"
                                        />
                                    </div>
                                    <div v-if="canOverridePrice">
                                        <Label :for="`price-${index}`"
                                            >Unit price</Label
                                        ><Input
                                            :id="`price-${index}`"
                                            v-model="line.unit_price"
                                            inputmode="decimal"
                                        />
                                    </div>
                                    <div v-if="canDiscount">
                                        <Label :for="`discount-${index}`"
                                            >Discount</Label
                                        ><Input
                                            :id="`discount-${index}`"
                                            v-model="line.discount_amount"
                                            inputmode="decimal"
                                        />
                                    </div>
                                </div>
                                <p
                                    class="mt-1.5 text-right text-sm font-semibold tabular-nums"
                                >
                                    {{ money(lineTotal(line)) }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="border-t bg-card p-3">
                        <Button
                            class="w-full justify-between px-4"
                            size="lg"
                            :disabled="form.processing || cart.length === 0"
                            @click="checkoutModalOpen = true"
                        >
                            <span>Pay</span>
                            <span class="tabular-nums">{{
                                money(cartTotal())
                            }}</span>
                        </Button>
                    </div>
                </aside>
            </div>
        </section>

        <Dialog v-model:open="checkoutModalOpen">
            <DialogContent
                class="grid max-h-[calc(100svh-1rem)] grid-rows-[auto_minmax(0,1fr)_auto] gap-0 overflow-hidden p-0 sm:max-w-lg"
            >
                <DialogHeader class="gap-1 border-b px-4 py-3 text-left">
                    <DialogTitle class="text-base">Process payment</DialogTitle>
                    <DialogDescription class="text-xs">
                        Review the total, choose a payment method, and complete
                        the sale.
                    </DialogDescription>
                </DialogHeader>

                <div class="min-h-0 space-y-3 overflow-y-auto p-4">
                    <div
                        class="flex items-center justify-between gap-3 rounded-lg bg-primary p-3 text-primary-foreground shadow-sm"
                    >
                        <div>
                            <p class="text-sm text-primary-foreground/75">
                                Total
                            </p>
                            <p
                                class="text-2xl font-bold tracking-tight tabular-nums"
                            >
                                {{ money(cartTotal()) }}
                            </p>
                        </div>
                        <div
                            class="text-right text-sm text-primary-foreground/75"
                        >
                            <p>
                                {{ cart.length }} item{{
                                    cart.length === 1 ? '' : 's'
                                }}
                            </p>
                            <p>{{ selectedLocation.name }}</p>
                        </div>
                    </div>

                    <div class="space-y-2 rounded-lg border bg-muted/20 p-3">
                        <div class="flex justify-between text-sm">
                            <span class="text-muted-foreground">Subtotal</span>
                            <span class="font-medium tabular-nums">{{
                                money(cartSubtotal())
                            }}</span>
                        </div>
                        <div
                            v-if="canDiscount"
                            class="grid grid-cols-[1fr_8rem] items-center gap-2"
                        >
                            <Label for="order-discount">Order discount</Label>
                            <Input
                                id="order-discount"
                                v-model="orderDiscount"
                                inputmode="decimal"
                            />
                        </div>
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="sale-source">Sale source</Label>
                        <select
                            id="sale-source"
                            v-model="saleSource"
                            class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option
                                v-for="source in saleSources"
                                :key="source.value"
                                :value="source.value"
                            >
                                {{ source.label }}
                            </option>
                        </select>
                    </div>

                    <section
                        class="space-y-3"
                        aria-labelledby="payment-heading"
                    >
                        <div>
                            <h3 id="payment-heading" class="font-semibold">
                                Payment
                            </h3>
                            <p class="text-sm text-muted-foreground">
                                Blank amounts default to the exact total in
                                cash.
                            </p>
                        </div>

                        <div
                            v-for="(payment, index) in payments"
                            :key="index"
                            class="grid gap-2 rounded-lg border p-3 sm:grid-cols-[1fr_1fr_auto]"
                        >
                            <div class="space-y-1">
                                <Label :for="'payment-amount-' + index">
                                    Amount
                                </Label>
                                <Input
                                    :id="'payment-amount-' + index"
                                    v-model="payment.amount"
                                    inputmode="decimal"
                                    :placeholder="money(cartTotal())"
                                />
                            </div>
                            <div class="space-y-1">
                                <Label :for="'payment-method-' + index">
                                    Method
                                </Label>
                                <select
                                    :id="'payment-method-' + index"
                                    v-model="payment.method"
                                    class="w-full rounded-md border border-input bg-background px-3 text-sm"
                                >
                                    <option value="cash">Cash</option>
                                    <option value="card">Card</option>
                                    <option value="bank_transfer">
                                        Bank transfer
                                    </option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <Button
                                v-if="payments.length > 1"
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="sm:self-end"
                                aria-label="Remove payment"
                                @click="removePayment(index)"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                            <Input
                                v-if="payment.method !== 'cash'"
                                v-model="payment.reference"
                                class="sm:col-span-2"
                                placeholder="Payment reference (optional)"
                            />
                            <div v-else class="space-y-1 sm:col-span-2">
                                <Label :for="'cash-tendered-' + index">
                                    Cash received
                                </Label>
                                <Input
                                    :id="'cash-tendered-' + index"
                                    v-model="payment.cash_tendered"
                                    inputmode="decimal"
                                    placeholder="Leave blank for exact amount"
                                />
                                <p
                                    v-if="
                                        payment.amount &&
                                        payment.cash_tendered &&
                                        minorUnits(payment.cash_tendered) >=
                                            minorUnits(payment.amount)
                                    "
                                    class="text-xs text-muted-foreground"
                                >
                                    Change due:
                                    {{
                                        money(
                                            minorUnits(payment.cash_tendered) -
                                                minorUnits(payment.amount),
                                        )
                                    }}
                                </p>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="addPayment"
                            >
                                Add payment method
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="payRemaining"
                            >
                                Pay exact total
                            </Button>
                        </div>
                        <p
                            v-if="canCredit"
                            class="text-xs text-muted-foreground"
                        >
                            Partial payment will leave a recorded balance due.
                        </p>
                    </section>

                    <details class="group rounded-lg border bg-background">
                        <summary
                            class="flex cursor-pointer list-none items-center justify-between gap-3 px-3 py-2.5 text-sm font-medium"
                        >
                            Customer &amp; notes
                            <span
                                class="flex items-center gap-2 text-xs font-normal text-muted-foreground"
                            >
                                Optional
                                <ChevronDown
                                    class="size-4 transition-transform group-open:rotate-180"
                                />
                            </span>
                        </summary>
                        <div class="grid gap-2 border-t p-3 sm:grid-cols-2">
                            <Input
                                v-model="customerName"
                                aria-label="Customer name"
                                placeholder="Customer name"
                            />
                            <Input
                                v-model="customerPhone"
                                aria-label="Customer phone"
                                placeholder="Customer phone"
                            />
                            <Input
                                v-model="customerEmail"
                                aria-label="Customer email"
                                class="sm:col-span-2"
                                placeholder="Customer email"
                            />
                            <textarea
                                v-model="notes"
                                aria-label="Sale notes"
                                class="min-h-16 rounded-md border border-input bg-background px-3 py-2 text-sm sm:col-span-2"
                                placeholder="Sale notes"
                            />
                        </div>
                    </details>

                    <div
                        v-if="checkoutErrors.length > 0"
                        role="alert"
                        aria-live="assertive"
                        class="rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"
                    >
                        <p class="font-medium">Sale was not completed.</p>
                        <ul class="mt-1 list-inside list-disc space-y-1">
                            <li
                                v-for="[field, message] in checkoutErrors"
                                :key="field"
                            >
                                {{ message }}
                            </li>
                        </ul>
                    </div>
                </div>

                <DialogFooter
                    class="flex-row gap-2 border-t bg-muted/30 p-3 sm:justify-between"
                >
                    <Button
                        type="button"
                        variant="outline"
                        @click="checkoutModalOpen = false"
                    >
                        Back to order
                    </Button>
                    <Button
                        type="button"
                        class="min-w-44 justify-between gap-4"
                        :disabled="form.processing || cart.length === 0"
                        @click="submitCheckout"
                    >
                        <span>{{
                            form.processing ? 'Processing…' : 'Complete sale'
                        }}</span>
                        <span class="tabular-nums">{{
                            money(cartTotal())
                        }}</span>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <ConfirmActionDialog
            :open="confirmation.isOpen.value"
            :title="confirmation.title.value"
            :description="confirmation.description.value"
            :confirm-label="confirmation.confirmLabel.value"
            :destructive="confirmation.destructive.value"
            @update:open="confirmation.setOpen"
            @confirm="confirmation.confirm"
        />
    </div>
</template>
