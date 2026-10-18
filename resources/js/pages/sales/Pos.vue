<script setup lang="ts">
import { Head, InfiniteScroll, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    Barcode,
    CircleCheckBig,
    Plus,
    Printer,
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
import { index as salesIndex, receipt as saleReceipt } from '@/routes/sales';

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
type PosSettings = {
    show_sale_source: boolean;
    show_customer_name: boolean;
    show_customer_phone: boolean;
    show_customer_email: boolean;
    show_sale_notes: boolean;
    show_pay_exact: boolean;
    show_date_time: boolean;
    show_sales_history: boolean;
    show_category_filter: boolean;
    show_brand_filter: boolean;
    show_location_filter: boolean;
    show_receipt_after_sale: boolean;
    products_per_page: number;
};
type CompletedSale = {
    id: number;
    sale_number: string;
    customer: string;
    total: string;
    cash_tendered: string | null;
    change_given: string;
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
    posSettings: PosSettings;
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
const receiptModalOpen = ref(false);
const completedSale = ref<CompletedSale | null>(null);
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
const hasInsufficientCash = computed(() =>
    payments.value.some(
        (payment) =>
            payment.method === 'cash' &&
            cashReceived(payment) < minorUnits(appliedPaymentAmount(payment)),
    ),
);
const needsExactCashReset = computed(() => {
    const payment = payments.value[0];

    return (
        payments.value.length !== 1 ||
        payment?.method !== 'cash' ||
        payment.cash_tendered !== formatMinor(cartTotal())
    );
});
const showAnyCustomerDetails = computed(
    () =>
        props.posSettings.show_customer_name ||
        props.posSettings.show_customer_phone ||
        props.posSettings.show_customer_email ||
        props.posSettings.show_sale_notes,
);
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
function isExactCashPayment(payment: Payment): boolean {
    return payments.value.length === 1 && payment.method === 'cash';
}
function appliedPaymentAmount(payment: Payment): string {
    return isExactCashPayment(payment)
        ? formatMinor(cartTotal())
        : payment.amount;
}
function cashReceived(payment: Payment): bigint {
    return payment.cash_tendered.trim() === ''
        ? minorUnits(appliedPaymentAmount(payment))
        : minorUnits(payment.cash_tendered);
}
function changeDue(payment: Payment): bigint {
    const change = cashReceived(payment) - minorUnits(appliedPaymentAmount(payment));

    return change > 0n ? change : 0n;
}
function remainingCash(payment: Payment): bigint {
    const remaining = minorUnits(appliedPaymentAmount(payment)) - cashReceived(payment);

    return remaining > 0n ? remaining : 0n;
}
function openCheckout(): void {
    form.clearErrors();
    payRemaining();
    checkoutModalOpen.value = true;
}
function resetSale(): void {
    cart.value = [];
    orderDiscount.value = '0.00';
    customerName.value = '';
    customerPhone.value = '';
    customerEmail.value = '';
    saleSource.value = 'in_store_pos';
    notes.value = '';
    payments.value = [
        { amount: '', method: 'cash', reference: '', cash_tendered: '' },
    ];
    form.clearErrors();
    form.operation_key = crypto.randomUUID();
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
    form.customer_name = props.posSettings.show_customer_name
        ? customerName.value
        : '';
    form.customer_phone = props.posSettings.show_customer_phone
        ? customerPhone.value
        : '';
    form.customer_email = props.posSettings.show_customer_email
        ? customerEmail.value
        : '';
    form.source = saleSource.value;
    form.order_discount = orderDiscount.value;
    form.notes = props.posSettings.show_sale_notes ? notes.value : '';
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
        .filter((payment) => appliedPaymentAmount(payment) !== '')
        .map((payment) => ({
            amount: appliedPaymentAmount(payment),
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

    form.post(checkout().url, {
        preserveScroll: true,
        onFlash: (flash) => {
            const sale = flash.completedSale as CompletedSale | undefined;

            if (sale) {
                completedSale.value = sale;
                receiptModalOpen.value = true;
            }
        },
        onSuccess: () => {
            checkoutModalOpen.value = false;
            resetSale();
        },
    });
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
                        v-if="posSettings.show_date_time"
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
                    <Button
                        v-if="posSettings.show_sales_history"
                        as-child
                        variant="outline"
                        size="sm"
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
                        class="surface-panel flex flex-wrap gap-2 p-2"
                    >
                        <div class="relative min-w-56 flex-1">
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
                            v-if="posSettings.show_category_filter"
                            v-model="categoryId"
                            class="h-10 min-w-40 flex-1 rounded-md border border-input bg-background px-3 text-sm"
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
                            v-if="posSettings.show_brand_filter"
                            v-model="brandId"
                            class="h-10 min-w-40 flex-1 rounded-md border border-input bg-background px-3 text-sm"
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
                            v-if="posSettings.show_location_filter"
                            v-model="locationId"
                            class="h-10 min-w-44 flex-1 rounded-md border border-input bg-background px-3 text-sm"
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
                            @click="openCheckout"
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
                class="grid max-h-[calc(100svh-1rem)] grid-rows-[auto_minmax(0,1fr)_auto] gap-0 overflow-hidden border-0 p-0 shadow-2xl sm:max-w-xl sm:rounded-2xl"
            >
                <DialogHeader class="border-b bg-muted/30 px-5 py-4 text-left sm:px-6">
                    <div class="flex flex-col gap-3 pr-6 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                        <div class="grid gap-1">
                            <DialogTitle class="text-xl tracking-tight">Take payment</DialogTitle>
                            <DialogDescription>
                                Select a payment type and enter the amount received.
                            </DialogDescription>
                        </div>
                        <div class="shrink-0 text-left sm:text-right">
                            <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Amount due</p>
                            <p class="text-xl font-bold tabular-nums text-foreground">{{ money(cartTotal()) }}</p>
                        </div>
                    </div>
                </DialogHeader>

                <div class="min-h-0 space-y-4 overflow-y-auto p-4 sm:p-5">
                    <div
                        class="flex items-center justify-between gap-4 rounded-lg border bg-card px-4 py-3"
                    >
                        <div>
                            <p class="text-sm font-medium">Order summary</p>
                            <p class="text-xs text-muted-foreground">{{ selectedLocation.name }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-semibold tabular-nums">{{ money(cartTotal()) }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ cart.length }} item{{ cart.length === 1 ? '' : 's' }}
                            </p>
                        </div>
                    </div>

                    <details v-if="canDiscount" class="group rounded-xl border bg-background">
                        <summary
                            class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm font-medium"
                        >
                            Adjust discount
                            <span class="text-xs font-normal text-muted-foreground">Optional</span>
                        </summary>
                        <div class="grid gap-3 border-t bg-muted/20 p-4 sm:grid-cols-2 sm:items-end">
                            <div class="flex justify-between gap-3 text-sm sm:pb-2">
                                <span class="text-muted-foreground">Subtotal</span>
                                <span class="font-medium tabular-nums">{{ money(cartSubtotal()) }}</span>
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="order-discount">Discount amount</Label>
                                <Input
                                    id="order-discount"
                                    v-model="orderDiscount"
                                    inputmode="decimal"
                                />
                            </div>
                        </div>
                    </details>

                    <div v-if="posSettings.show_sale_source" class="grid gap-1.5">
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
                        class="space-y-3 rounded-xl border bg-card p-4 shadow-sm"
                        aria-labelledby="payment-heading"
                    >
                        <div class="flex flex-wrap items-end justify-between gap-2">
                            <div>
                                <h3 id="payment-heading" class="text-base font-semibold tracking-tight">
                                    Payment
                                </h3>
                                <p class="text-sm text-muted-foreground">
                                    Choose how the customer is paying and enter the amount received.
                                </p>
                            </div>
                            <Button
                                v-if="posSettings.show_pay_exact && needsExactCashReset"
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="payRemaining"
                            >
                                Reset to exact cash
                            </Button>
                        </div>

                        <div
                            v-for="(payment, index) in payments"
                            :key="index"
                            class="grid gap-4 rounded-lg border bg-muted/20 p-3 sm:grid-cols-2"
                        >
                            <div
                                class="grid gap-1.5"
                                :class="isExactCashPayment(payment) ? 'sm:col-span-2' : ''"
                            >
                                <div class="flex items-center justify-between gap-2">
                                    <Label :for="'payment-method-' + index">Payment type</Label>
                                    <Button
                                        v-if="payments.length > 1"
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        class="h-7 px-2 text-xs text-muted-foreground"
                                        @click="removePayment(index)"
                                    >
                                        <Trash2 class="size-3.5" />
                                        Remove
                                    </Button>
                                </div>
                                <select
                                    :id="'payment-method-' + index"
                                    v-model="payment.method"
                                    class="h-11 w-full rounded-md border border-input bg-background px-3 text-base font-medium shadow-sm outline-none transition-colors focus:border-ring focus:ring-2 focus:ring-ring/20"
                                >
                                    <option value="cash">Cash</option>
                                    <option value="card">Card</option>
                                    <option value="bank_transfer">
                                        Bank transfer
                                    </option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div
                                v-if="!isExactCashPayment(payment)"
                                class="grid gap-1.5"
                            >
                                <Label :for="'payment-amount-' + index">Amount applied</Label>
                                <Input
                                    :id="'payment-amount-' + index"
                                    v-model="payment.amount"
                                    inputmode="decimal"
                                    :placeholder="money(cartTotal())"
                                    class="h-11 font-medium tabular-nums"
                                />
                            </div>
                            <div v-if="payment.method !== 'cash'" class="grid gap-1.5 sm:col-span-2">
                                <Label :for="'payment-reference-' + index">Payment reference <span class="font-normal text-muted-foreground">(optional)</span></Label>
                                <Input
                                    :id="'payment-reference-' + index"
                                    v-model="payment.reference"
                                />
                            </div>
                            <div v-else class="grid gap-2.5 rounded-lg border border-primary/20 bg-primary/5 p-4 sm:col-span-2">
                                <Label :for="'cash-tendered-' + index" class="text-sm font-semibold">
                                    {{ isExactCashPayment(payment) ? 'Cash received from customer' : 'Cash received for this payment' }}
                                </Label>
                                <Input
                                    :id="'cash-tendered-' + index"
                                    v-model="payment.cash_tendered"
                                    inputmode="decimal"
                                    :placeholder="money(minorUnits(appliedPaymentAmount(payment)))"
                                    class="h-14 border-primary/30 bg-background px-4 text-2xl font-bold tabular-nums shadow-sm focus-visible:ring-primary/30"
                                />
                                <p v-if="!isExactCashPayment(payment)" class="text-xs text-muted-foreground">
                                    Amount due for this payment: {{ money(minorUnits(appliedPaymentAmount(payment))) }}
                                </p>
                                <div
                                    v-if="remainingCash(payment) === 0n"
                                    role="status"
                                    class="flex items-center justify-between gap-3 rounded-md bg-emerald-100 px-3 py-2.5 text-emerald-950 dark:bg-emerald-950/60 dark:text-emerald-100"
                                >
                                    <span class="text-sm font-medium">Change to return</span>
                                    <span class="text-xl font-bold tabular-nums">{{ money(changeDue(payment)) }}</span>
                                </div>
                                <div
                                    v-else
                                    role="status"
                                    class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2.5 text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100"
                                >
                                    Still needed to cover this payment:
                                    <span class="font-semibold tabular-nums">{{ money(remainingCash(payment)) }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="addPayment"
                            >
                                Add another payment
                            </Button>
                        </div>
                        <p v-if="canCredit" class="text-xs text-muted-foreground">
                            To split payment, add another method. Any unpaid balance is recorded as due.
                        </p>
                    </section>

                    <section
                        v-if="showAnyCustomerDetails"
                        class="space-y-3 rounded-xl border bg-card p-4"
                        aria-labelledby="customer-details-heading"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h3 id="customer-details-heading" class="text-sm font-semibold">Customer details &amp; notes</h3>
                                <p class="text-xs text-muted-foreground">Add details for the receipt or future reference.</p>
                            </div>
                            <span class="text-xs text-muted-foreground">Optional</span>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <Input
                                v-if="posSettings.show_customer_name"
                                v-model="customerName"
                                aria-label="Customer name"
                                placeholder="Customer name"
                            />
                            <Input
                                v-if="posSettings.show_customer_phone"
                                v-model="customerPhone"
                                aria-label="Customer phone"
                                placeholder="Customer phone"
                            />
                            <Input
                                v-if="posSettings.show_customer_email"
                                v-model="customerEmail"
                                aria-label="Customer email"
                                class="sm:col-span-2"
                                placeholder="Customer email"
                            />
                            <textarea
                                v-if="posSettings.show_sale_notes"
                                v-model="notes"
                                aria-label="Sale notes"
                                class="min-h-16 rounded-md border border-input bg-background px-3 py-2 text-sm sm:col-span-2"
                                placeholder="Sale notes"
                            />
                        </div>
                    </section>

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
                    class="flex-col-reverse gap-2 border-t bg-background px-4 py-3 sm:flex-row sm:justify-between sm:px-5"
                >
                    <Button
                        type="button"
                        variant="outline"
                        class="w-full sm:w-auto"
                        @click="checkoutModalOpen = false"
                    >
                        Back to order
                    </Button>
                    <Button
                        type="button"
                        class="h-11 w-full justify-between gap-4 px-5 text-base sm:w-auto sm:min-w-56"
                        :disabled="form.processing || cart.length === 0 || hasInsufficientCash"
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

        <Dialog v-model:open="receiptModalOpen">
            <DialogContent class="gap-0 overflow-hidden p-0 sm:max-w-sm">
                <DialogHeader class="items-center border-b px-5 py-5 text-center">
                    <span
                        class="mb-1 grid size-12 place-items-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"
                    >
                        <CircleCheckBig class="size-7" />
                    </span>
                    <DialogTitle>Sale completed</DialogTitle>
                    <DialogDescription v-if="completedSale">
                        {{ completedSale.sale_number }} ·
                        {{ completedSale.customer }}
                    </DialogDescription>
                </DialogHeader>

                <div v-if="completedSale" class="space-y-3 p-5">
                    <div class="flex justify-between gap-3 text-sm">
                        <span class="text-muted-foreground">Sale total</span>
                        <span class="font-semibold tabular-nums">{{
                            money(minorUnits(completedSale.total))
                        }}</span>
                    </div>
                    <div
                        v-if="completedSale.cash_tendered"
                        class="flex justify-between gap-3 text-sm"
                    >
                        <span class="text-muted-foreground">Cash received</span>
                        <span class="font-semibold tabular-nums">{{
                            money(minorUnits(completedSale.cash_tendered))
                        }}</span>
                    </div>
                    <div
                        class="rounded-xl bg-primary px-4 py-4 text-center text-primary-foreground"
                    >
                        <p class="text-xs font-medium text-primary-foreground/75">
                            Change to customer
                        </p>
                        <p class="mt-1 text-3xl font-bold tabular-nums">
                            {{ money(minorUnits(completedSale.change_given)) }}
                        </p>
                    </div>
                </div>

                <DialogFooter class="flex-row border-t bg-muted/30 p-3">
                    <Button
                        type="button"
                        variant="outline"
                        class="flex-1"
                        @click="receiptModalOpen = false"
                    >
                        Next sale
                    </Button>
                    <Button v-if="completedSale" as-child class="flex-1">
                        <a
                            :href="saleReceipt(completedSale.id).url"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <Printer class="size-4" />
                            Print receipt
                        </a>
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
