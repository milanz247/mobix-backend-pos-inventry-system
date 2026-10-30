<script setup lang="ts">
import {
    Head,
    InfiniteScroll,
    Link,
    router,
    useForm,
    usePoll,
} from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    Barcode,
    CircleCheckBig,
    LoaderCircle,
    Percent,
    Plus,
    Printer,
    Search,
    ShoppingCart,
    Trash2,
} from '@lucide/vue';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import PosCashDrawer from '@/components/shifts/PosCashDrawer.vue';
import type { PosDrawer } from '@/types/shift';
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
import { index, checkout } from '@/routes/pos';
import { receipt as saleReceipt } from '@/routes/sales';

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
    available_quantity: string;
    stock_status: string;
    can_sell: boolean;
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
type DiscountMode = 'percentage' | 'amount';
type HeldCart = {
    id: string;
    held_at: string;
    location_id: number;
    lines: CartLine[];
    order_discount: string;
};
type ActiveCartSnapshot = {
    saved_at: string;
    location_id: number;
    lines: CartLine[];
    order_discount: string;
    sale_source: string;
};
type PosSettings = {
    show_sale_source: boolean;
    show_customer_name: boolean;
    show_customer_phone: boolean;
    show_customer_email: boolean;
    show_sale_notes: boolean;
    show_pay_exact: boolean;
    require_imei_on_sale: boolean;
    show_date_time: boolean;
    show_sales_history: boolean;
    show_category_filter: boolean;
    show_brand_filter: boolean;
    show_location_filter: boolean;
    show_receipt_after_sale: boolean;
    auto_print_receipt: boolean;
    products_per_page: number;
};
type CompletedSale = {
    id: number;
    sale_number: string;
    customer: string;
    total: string;
    paid_total: string;
    outstanding: string;
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
    drawer: PosDrawer;
    canOverridePrice: boolean;
    canDiscount: boolean;
    canCredit: boolean;
    canCreateCustomer: boolean;
    canPrintReceipt: boolean;
    creditSale: {
        id: number;
        sale_number: string;
        customer_id: number;
        customer_name: string;
        outstanding: string;
    } | null;
    customers: {
        id: number;
        name: string;
        phone: string;
        email: string | null;
    }[];
    saleSources: { value: string; label: string }[];
    posSettings: PosSettings;
}>();

const search = ref(props.filters.search);
const categoryId = ref(String(props.filters.category_id ?? ''));
const brandId = ref(String(props.filters.brand_id ?? ''));
const locationId = ref(String(props.filters.location_id));
const drawerProcessing = ref(false);
const navbarReady = ref(false);
const hasActiveShift = computed(
    () =>
        props.drawer.location_id === Number(locationId.value) &&
        props.selectedLocation.id === Number(locationId.value) &&
        props.drawer.shift !== null,
);

let cancelShiftPoll: (() => void) | null = null;
const shiftPolling = usePoll(15000, () => ({
    only: ['drawer'],
    data: {
        location_id: Number(locationId.value),
        credit_sale_id: props.creditSale?.id,
    },
    onCancelToken: (token) => {
        cancelShiftPoll = () => token.cancel();
    },
    onFinish: () => {
        cancelShiftPoll = null;
    },
}));
const cart = ref<CartLine[]>([]);
const heldCarts = ref<HeldCart[]>([]);
const orderDiscount = ref('0.00');
const creditModalOpen = ref(false);
const creditCustomerId = ref(
    props.creditSale ? String(props.creditSale.customer_id) : '',
);
const creditPaid = ref('0.00');
const customerSearch = ref('');
function normalizedCustomerSearch(value: string): string {
    return value.toLowerCase().replace(/[\s()+.-]+/g, '');
}
const selectedCreditCustomer = computed(() =>
    props.customers.find(
        (customer) => String(customer.id) === creditCustomerId.value,
    ),
);
const filteredCustomers = computed(() => {
    const query = normalizedCustomerSearch(customerSearch.value);
    return props.customers.filter(
        (customer) =>
            normalizedCustomerSearch(customer.name).includes(query) ||
            normalizedCustomerSearch(customer.phone).includes(query),
    );
});
watch(creditModalOpen, (open) => {
    if (open) router.reload({ only: ['customers'] });
});
watch(customerSearch, () => {
    if (!props.creditSale) creditCustomerId.value = '';
});
const customerName = ref('');
const customerPhone = ref('');
const customerEmail = ref('');
const saleSource = ref('in_store_pos');
const notes = ref('');
const discountModalOpen = ref(false);
const customerModalOpen = ref(false);
const receiptModalOpen = ref(false);
const completedSale = ref<CompletedSale | null>(null);
const receiptPrintFrameKey = ref(0);
const receiptPrintUrl = ref<string | null>(null);
const discountMode = ref<DiscountMode>('amount');
const discountValue = ref('');
const discountLineIndex = ref<number | null>(null);
const payments = ref<Payment[]>([
    { amount: '', method: 'cash', reference: '', cash_tendered: '' },
]);
const confirmation = useConfirmationDialog();
const heldCartStorageKey = `nalakamobile:pos-held-carts:${props.cashierId}${props.creditSale ? `:credit:${props.creditSale.id}` : ''}`;
const activeCartStorageKey = `nalakamobile:pos-active-cart:${props.cashierId}${props.creditSale ? `:credit:${props.creditSale.id}` : ''}`;
const activeCartLifetime = 12 * 60 * 60 * 1000;
let activeCartRestored = false;
const form = useForm({
    operation_key: crypto.randomUUID(),
    location_id: Number(locationId.value),
    credit_sale_id: null as number | null,
    is_credit: false,
    customer_id: null as number | null,
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
watch(
    () => drawerProcessing.value || form.processing,
    (busy) => {
        if (busy) {
            shiftPolling.stop();
            cancelShiftPoll?.();
        } else {
            shiftPolling.start();
        }
    },
    { flush: 'sync' },
);
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
const discountBase = computed(() => {
    if (discountLineIndex.value === null) {
        return cartBeforeOrderDiscount();
    }

    const line = cart.value[discountLineIndex.value];

    return line ? lineSubtotal(line) : 0n;
});
const discountPreview = computed(() => {
    if (discountMode.value === 'percentage') {
        const percentage = minorUnits(discountValue.value);
        const cappedPercentage = percentage > 10000n ? 10000n : percentage;

        return (discountBase.value * cappedPercentage + 5000n) / 10000n;
    }

    const amount = minorUnits(discountValue.value);

    return amount > discountBase.value ? discountBase.value : amount;
});
let searchTimer: ReturnType<typeof setTimeout> | undefined;
let sidebarWrapper: HTMLElement | null = null;
let originalDocumentOverflow = '';
let originalBodyOverflow = '';
let originalSidebarWrapperHeight = '';
let originalSidebarWrapperMinHeight = '';
let originalSidebarWrapperOverflow = '';
let pendingReceiptWindow: Window | null = null;
watch([search, categoryId, brandId, locationId], () => {
    cancelShiftPoll?.();
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        router.get(
            index.url(),
            {
                search: search.value || undefined,
                category_id: categoryId.value || undefined,
                brand_id: brandId.value || undefined,
                location_id: locationId.value,
                credit_sale_id: props.creditSale?.id,
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
                    'drawer',
                ],
            },
        );
    }, 250);
});
watch(
    [cart, orderDiscount, saleSource, locationId],
    () => {
        if (activeCartRestored) saveActiveCart();
    },
    { deep: true, flush: 'sync' },
);
watch(
    () => props.selectedLocation.id,
    (id) => {
        if (locationId.value !== String(id)) {
            locationId.value = String(id);
        }
    },
);
onBeforeUnmount(() => {
    cancelShiftPoll?.();
    if (searchTimer) clearTimeout(searchTimer);
    closePendingReceiptWindow();

    document.documentElement.style.overflow = originalDocumentOverflow;
    document.body.style.overflow = originalBodyOverflow;

    if (sidebarWrapper) {
        sidebarWrapper.style.height = originalSidebarWrapperHeight;
        sidebarWrapper.style.minHeight = originalSidebarWrapperMinHeight;
        sidebarWrapper.style.overflow = originalSidebarWrapperOverflow;
    }
});
onMounted(() => {
    navbarReady.value = true;
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

    try {
        const stored = window.localStorage.getItem(heldCartStorageKey);
        const parsed: unknown = stored ? JSON.parse(stored) : [];
        heldCarts.value = Array.isArray(parsed)
            ? parsed
                  .map(restoreHeldCart)
                  .filter((held): held is HeldCart => held !== null)
            : [];
        saveHeldCarts();
    } catch {
        heldCarts.value = [];
    }

    restoreActiveCart();
    activeCartRestored = true;
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
function cartBeforeOrderDiscount(): bigint {
    return cart.value.reduce((sum, line) => sum + lineTotal(line), 0n);
}
function cartTotal(): bigint {
    const beforeOrderDiscount = cartBeforeOrderDiscount();
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
    return variant.can_sell;
}
function variantStockLabel(variant: Variant): string {
    if (variant.stock_status === 'inconsistent')
        return 'Check stock / components';
    if (thousandths(variant.available_quantity) <= 0n) return 'Out of stock';
    if (variant.is_virtual_combo)
        return `${variant.available_quantity.replace(/\.?0+$/, '')} bundles available`;

    if (variant.tracking_mode === 'serialized') {
        if (variant.devices_in_stock === 0) return 'No devices available';

        return `${variant.devices_in_stock} device${variant.devices_in_stock === 1 ? '' : 's'} available`;
    }

    return `${variant.available_quantity.replace(/\.0+$/, '')} in stock`;
}
function formatQuantity(value: bigint): string {
    return `${value / 1000n}.${(value % 1000n).toString().padStart(3, '0')}`;
}
function removeLine(index: number): void {
    cart.value.splice(index, 1);
}
function openOrderDiscount(): void {
    discountLineIndex.value = null;
    discountMode.value = 'amount';
    discountValue.value =
        orderDiscount.value === '0.00' ? '' : orderDiscount.value;
    discountModalOpen.value = true;
}
function openLineDiscount(index: number): void {
    discountLineIndex.value = index;
    discountMode.value = 'amount';
    discountValue.value =
        cart.value[index]?.discount_amount === '0.00'
            ? ''
            : (cart.value[index]?.discount_amount ?? '');
    discountModalOpen.value = true;
}
function applyDiscount(): void {
    const amount = formatMinor(discountPreview.value);

    if (discountLineIndex.value === null) {
        orderDiscount.value = amount;
    } else if (cart.value[discountLineIndex.value]) {
        cart.value[discountLineIndex.value].discount_amount = amount;
    }

    discountModalOpen.value = false;
}
function clearDiscount(): void {
    discountValue.value = '';
    applyDiscount();
}
function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}
function isMoneyValue(value: unknown): value is string {
    return typeof value === 'string' && /^\d+(?:\.\d{0,2})?$/.test(value);
}
function isQuantityValue(value: unknown): value is string {
    return typeof value === 'string' && /^\d+(?:\.\d{0,3})?$/.test(value);
}
function nullableString(value: unknown): string | null {
    return typeof value === 'string' ? value : null;
}
function restoreCartLine(value: unknown): CartLine | null {
    if (!isRecord(value) || !isRecord(value.variant)) return null;

    const storedVariant = value.variant;
    const id = storedVariant.id;
    const trackingMode = storedVariant.tracking_mode;

    if (
        typeof id !== 'number' ||
        !Number.isInteger(id) ||
        id < 1 ||
        (trackingMode !== 'serialized' && trackingMode !== 'quantity_based') ||
        typeof storedVariant.name !== 'string' ||
        typeof storedVariant.variant !== 'string' ||
        typeof storedVariant.sku !== 'string' ||
        !isQuantityValue(value.quantity) ||
        !isMoneyValue(value.unit_price) ||
        !isMoneyValue(value.discount_amount) ||
        typeof value.device_identifier !== 'string'
    ) {
        return null;
    }

    return {
        variant: {
            id,
            name: storedVariant.name,
            variant: storedVariant.variant,
            sku: storedVariant.sku,
            barcode: nullableString(storedVariant.barcode),
            category:
                typeof storedVariant.category === 'string'
                    ? storedVariant.category
                    : '',
            brand: nullableString(storedVariant.brand),
            product_type:
                typeof storedVariant.product_type === 'string'
                    ? storedVariant.product_type
                    : '',
            tracking_mode: trackingMode,
            price: isMoneyValue(storedVariant.price)
                ? storedVariant.price
                : value.unit_price,
            minimum_selling_price: isMoneyValue(
                storedVariant.minimum_selling_price,
            )
                ? storedVariant.minimum_selling_price
                : null,
            image_url: null,
            quantity_on_hand: isQuantityValue(storedVariant.quantity_on_hand)
                ? storedVariant.quantity_on_hand
                : '0.000',
            devices_in_stock:
                typeof storedVariant.devices_in_stock === 'number' &&
                Number.isInteger(storedVariant.devices_in_stock)
                    ? storedVariant.devices_in_stock
                    : 0,
            is_virtual_combo: storedVariant.is_virtual_combo === true,
            available_quantity: isQuantityValue(
                storedVariant.available_quantity,
            )
                ? storedVariant.available_quantity
                : '0.000',
            stock_status:
                typeof storedVariant.stock_status === 'string'
                    ? storedVariant.stock_status
                    : 'unknown',
            can_sell: false,
        },
        quantity: value.quantity,
        unit_price: value.unit_price,
        discount_amount: value.discount_amount,
        device_identifier: value.device_identifier,
    };
}
function restoreHeldCart(value: unknown): HeldCart | null {
    if (!isRecord(value) || !Array.isArray(value.lines)) return null;

    const heldAt = typeof value.held_at === 'string' ? value.held_at : '';
    const heldTime = Date.parse(heldAt);
    const locationIsAccessible = props.locations.some(
        (location) => location.id === value.location_id,
    );
    const lines = value.lines
        .slice(0, 100)
        .map(restoreCartLine)
        .filter((line): line is CartLine => line !== null);

    if (
        typeof value.id !== 'string' ||
        !Number.isFinite(heldTime) ||
        Date.now() - heldTime < 0 ||
        Date.now() - heldTime >= 7 * 24 * 60 * 60 * 1000 ||
        !locationIsAccessible ||
        lines.length === 0 ||
        !isMoneyValue(value.order_discount)
    ) {
        return null;
    }

    return {
        id: value.id,
        held_at: heldAt,
        location_id: value.location_id as number,
        lines,
        order_discount: value.order_discount,
    };
}
function saveActiveCart(): void {
    try {
        if (cart.value.length === 0) {
            clearActiveCart();
            return;
        }

        const snapshot: ActiveCartSnapshot = {
            saved_at: new Date().toISOString(),
            location_id: Number(locationId.value),
            lines: copyCartLines(cart.value),
            order_discount: orderDiscount.value,
            sale_source: saleSource.value,
        };

        window.sessionStorage.setItem(
            activeCartStorageKey,
            JSON.stringify(snapshot),
        );
    } catch {
        // Storage may be unavailable in restricted browser modes.
    }
}
function restoreActiveCart(): void {
    try {
        const stored = window.sessionStorage.getItem(activeCartStorageKey);
        const parsed: unknown = stored ? JSON.parse(stored) : null;

        if (!isRecord(parsed) || !Array.isArray(parsed.lines)) {
            if (stored) clearActiveCart();
            return;
        }

        const savedTime =
            typeof parsed.saved_at === 'string'
                ? Date.parse(parsed.saved_at)
                : Number.NaN;
        const isCurrentLocation =
            parsed.location_id === Number(locationId.value);
        const lines = parsed.lines
            .slice(0, 100)
            .map(restoreCartLine)
            .filter((line): line is CartLine => line !== null);

        if (
            !Number.isFinite(savedTime) ||
            Date.now() - savedTime < 0 ||
            Date.now() - savedTime > activeCartLifetime ||
            !isCurrentLocation ||
            lines.length === 0 ||
            !isMoneyValue(parsed.order_discount)
        ) {
            clearActiveCart();
            return;
        }

        cart.value = lines;
        orderDiscount.value = parsed.order_discount;
        saleSource.value = props.saleSources.some(
            (source) => source.value === parsed.sale_source,
        )
            ? (parsed.sale_source as string)
            : 'in_store_pos';
        resetCashPayment();
    } catch {
        clearActiveCart();
    }
}
function clearActiveCart(): void {
    try {
        window.sessionStorage.removeItem(activeCartStorageKey);
    } catch {
        // Storage may be unavailable in restricted browser modes.
    }
}
function saveHeldCarts(): void {
    try {
        window.localStorage.setItem(
            heldCartStorageKey,
            JSON.stringify(heldCarts.value.slice(0, 10)),
        );
    } catch {
        // The current cart remains usable when browser storage is unavailable.
    }
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
    resetCashPayment();
    clearActiveCart();
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
    resetCashPayment();
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
            resetCashPayment();
            clearActiveCart();
        },
    });
}
function resetCashPayment(): void {
    payments.value = [
        { amount: '', method: 'cash', reference: '', cash_tendered: '' },
    ];
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
    const change =
        cashReceived(payment) - minorUnits(appliedPaymentAmount(payment));

    return change > 0n ? change : 0n;
}
function remainingCash(payment: Payment): bigint {
    const remaining =
        minorUnits(appliedPaymentAmount(payment)) - cashReceived(payment);

    return remaining > 0n ? remaining : 0n;
}
function resetSale(): void {
    cart.value = [];
    orderDiscount.value = '0.00';
    customerName.value = '';
    customerPhone.value = '';
    customerEmail.value = '';
    saleSource.value = 'in_store_pos';
    notes.value = '';
    resetCashPayment();
    clearActiveCart();
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
            available_quantity: '1.000',
            stock_status: 'in_stock',
            can_sell: true,
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
                credit_sale_id: props.creditSale?.id,
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
                    'drawer',
                ],
                onSuccess: () => scanSearch(),
            },
        );
    }
}
function prepareAutoPrintWindow(): void {
    if (!props.posSettings.auto_print_receipt) return;

    closePendingReceiptWindow();
    pendingReceiptWindow = window.open(
        'about:blank',
        'nalakamobile-receipt-print',
        'popup,width=480,height=720',
    );

    if (pendingReceiptWindow) pendingReceiptWindow.opener = null;
}
function openReceiptForPrinting(saleId: number): boolean {
    const receiptUrl = saleReceipt(saleId, {
        query: { autoprint: 1 },
    }).url;

    if (pendingReceiptWindow && !pendingReceiptWindow.closed) {
        pendingReceiptWindow.location.replace(receiptUrl);
        pendingReceiptWindow = null;

        return true;
    }

    const receiptWindow = window.open(
        receiptUrl,
        'nalakamobile-receipt-print',
        'popup,width=480,height=720,noopener,noreferrer',
    );

    return receiptWindow !== null;
}
function closePendingReceiptWindow(): void {
    if (pendingReceiptWindow && !pendingReceiptWindow.closed) {
        pendingReceiptWindow.close();
    }

    pendingReceiptWindow = null;
}
function printCompletedSaleReceipt(): void {
    if (!completedSale.value) {
        return;
    }

    receiptPrintFrameKey.value += 1;
    receiptPrintUrl.value = saleReceipt(completedSale.value.id, {
        query: { autoprint: 1 },
    }).url;
    receiptModalOpen.value = false;
}
function submitCheckout(credit = false): void {
    if (
        cart.value.length === 0 ||
        !hasActiveShift.value ||
        drawerProcessing.value ||
        form.processing
    )
        return;
    prepareAutoPrintWindow();
    form.credit_sale_id = props.creditSale?.id ?? null;
    form.is_credit = credit;
    form.customer_id =
        credit && creditCustomerId.value
            ? Number(creditCustomerId.value)
            : null;
    form.location_id = Number(locationId.value);
    form.customer_name =
        credit || props.posSettings.show_customer_name
            ? customerName.value
            : '';
    form.customer_phone =
        credit || props.posSettings.show_customer_phone
            ? customerPhone.value
            : '';
    form.customer_email =
        credit || props.posSettings.show_customer_email
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
                payment.method === 'cash'
                    ? formatMinor(cashReceived(payment))
                    : undefined,
        }));

    if (form.payments.length === 0 && cartTotal() > 0n) {
        form.payments = [{ amount: formatMinor(cartTotal()), method: 'cash' }];
    }

    if (credit) {
        form.payments =
            minorUnits(creditPaid.value) > 0n
                ? [{ amount: creditPaid.value, method: 'cash' }]
                : [];
    }

    form.post(checkout().url, {
        preserveScroll: true,
        onFlash: (flash) => {
            const sale = flash.completedSale as CompletedSale | undefined;

            if (sale) {
                completedSale.value = sale;
                const printStarted = props.posSettings.auto_print_receipt
                    ? openReceiptForPrinting(sale.id)
                    : false;
                receiptModalOpen.value =
                    props.posSettings.show_receipt_after_sale || !printStarted;
            }
        },
        onSuccess: () => {
            creditModalOpen.value = false;
            creditCustomerId.value = '';
            creditPaid.value = '0.00';
            customerSearch.value = '';
            resetSale();
        },
        onError: () => closePendingReceiptWindow(),
        onFinish: () => closePendingReceiptWindow(),
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
        <div
            v-if="creditSale"
            class="flex items-center justify-between gap-3 rounded-md border bg-muted p-3 text-sm"
        >
            <span
                >Adding new items to
                <strong>{{ creditSale.sale_number }}</strong> ·
                {{ creditSale.customer_name }} · Previous balance
                {{ money(minorUnits(creditSale.outstanding)) }}</span
            >
            <Link :href="`/sales/${creditSale.id}`">Back to bill</Link>
        </div>
        <section
            class="flex min-h-0 min-w-0 flex-1 flex-col gap-3 overflow-hidden"
        >
            <Teleport v-if="navbarReady" to="#pos-navbar-actions">
                <PosCashDrawer
                    :drawer="drawer"
                    :location-id="Number(locationId)"
                    :resolved-location-id="selectedLocation.id"
                    :location-name="selectedLocation.name"
                    :currency="currency"
                    :checkout-busy="form.processing"
                    @busy="drawerProcessing = $event"
                    @switch-location="
                        !creditSale && (locationId = String($event))
                    "
                />
            </Teleport>

            <div
                class="grid min-h-0 min-w-0 flex-1 gap-3 overflow-hidden lg:grid-cols-[minmax(0,1.15fr)_minmax(400px,0.85fr)]"
            >
                <div
                    class="flex min-h-0 min-w-0 flex-col gap-2 overflow-hidden"
                >
                    <div class="surface-panel flex flex-wrap gap-2 p-2">
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
                            :disabled="
                                !!creditSale ||
                                drawerProcessing ||
                                form.processing
                            "
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
                            class="grid gap-2 pb-2 sm:grid-cols-2 xl:grid-cols-3"
                        >
                            <button
                                v-for="variant in variants.data"
                                :key="variant.id"
                                type="button"
                                class="surface-panel group relative grid min-h-28 grid-cols-[3.5rem_minmax(0,1fr)] gap-2.5 overflow-hidden p-2.5 text-left transition duration-150 hover:-translate-y-0.5 hover:border-primary/50 hover:shadow-md focus-visible:border-primary focus-visible:ring-2 focus-visible:ring-primary/20 active:translate-y-0 disabled:cursor-not-allowed disabled:opacity-65 disabled:hover:translate-y-0 disabled:hover:border-border disabled:hover:shadow-xs"
                                :disabled="!canAddVariant(variant)"
                                :aria-label="
                                    'Add ' +
                                    variant.name +
                                    ' ' +
                                    variant.variant +
                                    ' to the current order'
                                "
                                @click="addVariant(variant)"
                            >
                                <div
                                    class="relative size-14 overflow-hidden rounded-lg border bg-muted/30"
                                >
                                    <img
                                        v-if="variant.image_url"
                                        :src="variant.image_url"
                                        :alt="variant.name"
                                        class="size-full object-cover transition duration-200 group-hover:scale-105"
                                    />
                                    <div
                                        v-else
                                        class="grid size-full place-items-center bg-primary/8 text-primary"
                                    >
                                        <Barcode class="size-5" />
                                    </div>
                                </div>

                                <span class="flex min-w-0 flex-col">
                                    <span
                                        class="line-clamp-2 min-h-8 text-sm leading-4 font-semibold text-foreground"
                                        >{{ variant.name }}</span
                                    >
                                    <span
                                        class="mt-0.5 block truncate text-[0.7rem] leading-4 text-muted-foreground"
                                        >{{ variant.variant }} ·
                                        {{ variant.sku }}</span
                                    >

                                    <span
                                        class="mt-auto flex min-w-0 items-end justify-between gap-2 pt-1.5"
                                    >
                                        <span class="min-w-0">
                                            <span
                                                class="block text-sm leading-4 font-bold tabular-nums"
                                                >{{
                                                    money(
                                                        minorUnits(
                                                            variant.price,
                                                        ),
                                                    )
                                                }}</span
                                            >
                                            <span
                                                class="mt-1 flex min-w-0 items-center gap-1 text-[0.68rem] leading-3"
                                                :class="
                                                    canAddVariant(variant)
                                                        ? 'text-emerald-700 dark:text-emerald-400'
                                                        : 'text-destructive'
                                                "
                                            >
                                                <span
                                                    class="size-1.5 shrink-0 rounded-full bg-current"
                                                />
                                                <span class="truncate">{{
                                                    variantStockLabel(variant)
                                                }}</span>
                                            </span>
                                        </span>

                                        <span
                                            class="grid size-8 shrink-0 place-items-center rounded-full bg-primary/10 text-primary transition group-hover:bg-primary group-hover:text-primary-foreground group-disabled:bg-muted group-disabled:text-muted-foreground"
                                            aria-hidden="true"
                                        >
                                            <Plus class="size-4" />
                                        </span>
                                    </span>
                                </span>
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
                        <div class="grid grid-cols-4 gap-1.5">
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                class="min-w-0 px-2"
                                :disabled="cart.length === 0"
                                @click="holdCart"
                                >Hold cart</Button
                            >
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                class="px-2"
                                :disabled="cart.length === 0"
                                @click="clearCart"
                                >Clear cart</Button
                            >
                            <Button
                                v-if="canDiscount"
                                type="button"
                                size="sm"
                                variant="outline"
                                class="px-2"
                                :disabled="cart.length === 0"
                                @click="openOrderDiscount"
                            >
                                Discount
                            </Button>
                            <Button
                                v-if="showAnyCustomerDetails"
                                type="button"
                                size="sm"
                                variant="outline"
                                class="min-w-0 px-2"
                                @click="customerModalOpen = true"
                            >
                                <span class="truncate">{{
                                    customerName || 'Customer'
                                }}</span>
                            </Button>
                        </div>
                        <div v-if="heldCarts.length">
                            <select
                                class="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
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
                            v-if="posSettings.show_sale_source"
                            class="flex items-center gap-2 rounded-lg border bg-muted/20 px-3 py-2"
                        >
                            <Label for="sale-source" class="shrink-0"
                                >Source</Label
                            >
                            <select
                                id="sale-source"
                                v-model="saleSource"
                                class="h-8 min-w-0 flex-1 rounded-md border border-input bg-background px-2 text-sm"
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
                                class="rounded-lg border bg-background p-2.5 shadow-xs transition-colors focus-within:border-primary/40"
                            >
                                <p
                                    class="truncate leading-5"
                                    :title="`${line.variant.name} · ${line.variant.variant} · ${line.variant.sku} · ${money(minorUnits(line.unit_price))} each`"
                                >
                                    <span class="text-sm font-semibold">
                                        {{ line.variant.name }} ·
                                        {{ line.variant.variant }}
                                    </span>
                                    <span class="text-xs text-muted-foreground">
                                        · {{ line.variant.sku }} ·
                                        {{ money(minorUnits(line.unit_price)) }}
                                        each
                                    </span>
                                </p>

                                <div
                                    class="mt-2 flex min-w-0 flex-wrap items-center gap-2"
                                >
                                    <div
                                        class="flex shrink-0 items-center gap-1.5"
                                    >
                                        <Label
                                            :for="`qty-${index}`"
                                            class="text-xs text-muted-foreground"
                                            >Qty</Label
                                        >
                                        <Input
                                            v-if="
                                                !(
                                                    line.variant
                                                        .tracking_mode ===
                                                        'serialized' &&
                                                    !line.variant
                                                        .is_virtual_combo
                                                )
                                            "
                                            :id="`qty-${index}`"
                                            v-model="line.quantity"
                                            class="h-8 w-20 px-2 text-sm tabular-nums"
                                            inputmode="decimal"
                                            aria-label="Quantity"
                                        />
                                        <span
                                            v-else
                                            :id="`qty-${index}`"
                                            class="flex h-8 w-20 items-center rounded-md border bg-muted/20 px-2 text-sm tabular-nums"
                                        >
                                            1.000
                                        </span>
                                    </div>

                                    <div
                                        v-if="canOverridePrice"
                                        class="flex shrink-0 items-center gap-1.5"
                                    >
                                        <Label
                                            :for="`price-${index}`"
                                            class="text-xs text-muted-foreground"
                                            >Price</Label
                                        >
                                        <Input
                                            :id="`price-${index}`"
                                            v-model="line.unit_price"
                                            class="h-8 w-24 px-2 text-sm tabular-nums"
                                            inputmode="decimal"
                                            aria-label="Unit price"
                                        />
                                    </div>

                                    <Button
                                        v-if="canDiscount"
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        class="h-8 shrink-0 gap-1.5 px-2.5 text-xs"
                                        :class="
                                            minorUnits(line.discount_amount) >
                                            0n
                                                ? 'border-emerald-300 bg-emerald-50 text-emerald-800 hover:bg-emerald-100 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300'
                                                : ''
                                        "
                                        :aria-label="`Discount ${line.variant.name}`"
                                        @click="openLineDiscount(index)"
                                    >
                                        <Percent
                                            class="size-3.5"
                                            :class="
                                                minorUnits(
                                                    line.discount_amount,
                                                ) > 0n
                                                    ? 'text-primary'
                                                    : ''
                                            "
                                        />
                                        <span
                                            v-if="
                                                minorUnits(
                                                    line.discount_amount,
                                                ) > 0n
                                            "
                                        >
                                            Line discount · −{{
                                                money(
                                                    minorUnits(
                                                        line.discount_amount,
                                                    ),
                                                )
                                            }}
                                        </span>
                                        <span v-else>Discount</span>
                                    </Button>

                                    <div
                                        class="ml-auto flex min-w-0 items-baseline gap-1.5 text-right"
                                    >
                                        <span
                                            class="shrink-0 text-xs text-muted-foreground"
                                            >Line total</span
                                        >
                                        <strong
                                            class="truncate text-sm font-bold tabular-nums"
                                        >
                                            {{ money(lineTotal(line)) }}
                                        </strong>
                                    </div>

                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon-sm"
                                        class="size-8 shrink-0 text-muted-foreground hover:text-destructive"
                                        aria-label="Remove cart line"
                                        @click="removeLine(index)"
                                        ><Trash2 class="size-4"
                                    /></Button>
                                </div>
                                <div
                                    v-if="
                                        posSettings.require_imei_on_sale &&
                                        line.variant.tracking_mode ===
                                            'serialized' &&
                                        !line.variant.is_virtual_combo
                                    "
                                    class="mt-2 border-t pt-2"
                                >
                                    <Input
                                        :id="`imei-${index}`"
                                        v-model="line.device_identifier"
                                        autocomplete="off"
                                        class="h-8 text-sm"
                                        aria-label="IMEI or IMEI2"
                                        placeholder="Scan or enter device IMEI"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-2 border-t bg-card p-2.5">
                        <div
                            class="flex items-center justify-between gap-3 text-xs"
                        >
                            <div
                                class="flex flex-wrap items-center gap-x-3 gap-y-1"
                            >
                                <span class="text-muted-foreground">
                                    Subtotal
                                    <strong
                                        class="font-medium text-foreground tabular-nums"
                                        >{{ money(cartSubtotal()) }}</strong
                                    >
                                </span>
                                <span
                                    v-if="minorUnits(orderDiscount) > 0n"
                                    class="text-primary"
                                >
                                    Discount
                                    <strong class="font-semibold tabular-nums"
                                        >-{{
                                            money(minorUnits(orderDiscount))
                                        }}</strong
                                    >
                                </span>
                            </div>
                            <span class="shrink-0 font-semibold">
                                Due
                                <strong
                                    class="text-base font-bold tabular-nums"
                                    >{{ money(cartTotal()) }}</strong
                                >
                            </span>
                        </div>

                        <div
                            class="grid grid-cols-[minmax(0,1fr)_auto] items-end gap-2"
                        >
                            <div class="min-w-0">
                                <div
                                    class="mb-1 flex items-center justify-between gap-2"
                                >
                                    <Label
                                        for="cash-tendered"
                                        class="text-xs font-medium"
                                        >Cash received</Label
                                    >
                                    <Button
                                        v-if="
                                            posSettings.show_pay_exact &&
                                            needsExactCashReset &&
                                            payments[0].cash_tendered !== ''
                                        "
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        class="h-5 px-1.5 text-[0.7rem]"
                                        @click="payRemaining"
                                    >
                                        Use exact
                                    </Button>
                                </div>
                                <Input
                                    id="cash-tendered"
                                    v-model="payments[0].cash_tendered"
                                    inputmode="decimal"
                                    :placeholder="money(cartTotal())"
                                    class="h-10 border-primary/30 bg-background px-3 text-base font-semibold tabular-nums"
                                />
                            </div>

                            <Button
                                class="relative h-10 min-w-32 overflow-hidden px-3 transition-all duration-200 active:scale-[0.97]"
                                :class="
                                    form.processing
                                        ? 'cursor-wait shadow-lg shadow-primary/25'
                                        : 'justify-between gap-3'
                                "
                                :disabled="
                                    form.processing ||
                                    drawerProcessing ||
                                    !hasActiveShift ||
                                    cart.length === 0 ||
                                    hasInsufficientCash ||
                                    !!creditSale
                                "
                                @click="submitCheckout(false)"
                            >
                                <span
                                    v-if="form.processing"
                                    class="absolute inset-0 animate-pulse bg-white/15"
                                />
                                <span
                                    v-if="form.processing"
                                    class="relative flex w-full items-center justify-center gap-2"
                                >
                                    <LoaderCircle class="size-4 animate-spin" />
                                    Processing payment…
                                </span>
                                <template v-else>
                                    <span>Pay cash</span>
                                    <span class="tabular-nums">{{
                                        money(cartTotal())
                                    }}</span>
                                </template>
                            </Button>
                        </div>

                        <Button
                            v-if="canCredit"
                            variant="outline"
                            class="w-full"
                            :disabled="
                                form.processing ||
                                drawerProcessing ||
                                !hasActiveShift ||
                                cart.length === 0
                            "
                            @click="creditModalOpen = true"
                            >{{
                                creditSale
                                    ? 'Add items to credit bill'
                                    : 'Credit sale'
                            }}</Button
                        >

                        <div
                            v-if="
                                payments[0].cash_tendered !== '' &&
                                changeDue(payments[0]) > 0n
                            "
                            class="flex items-center justify-between gap-3 rounded-md bg-emerald-100 px-2.5 py-1.5 text-xs text-emerald-950 dark:bg-emerald-950/60 dark:text-emerald-100"
                        >
                            <span class="font-medium">Change</span>
                            <span class="font-bold tabular-nums">{{
                                money(changeDue(payments[0]))
                            }}</span>
                        </div>
                        <div
                            v-else-if="
                                payments[0].cash_tendered !== '' &&
                                remainingCash(payments[0]) > 0n
                            "
                            class="flex items-center justify-between gap-3 rounded-md bg-amber-50 px-2.5 py-1.5 text-xs text-amber-950 dark:bg-amber-950/40 dark:text-amber-100"
                        >
                            <span>Still needed</span>
                            <span class="font-semibold tabular-nums">{{
                                money(remainingCash(payments[0]))
                            }}</span>
                        </div>

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
                </aside>
            </div>
        </section>

        <Dialog v-model:open="discountModalOpen">
            <DialogContent class="gap-0 overflow-hidden p-0 sm:max-w-md">
                <DialogHeader class="border-b bg-muted/30 px-5 py-4 text-left">
                    <DialogTitle>
                        {{
                            discountLineIndex === null
                                ? 'Total discount'
                                : 'Product discount'
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        <template v-if="discountLineIndex === null">
                            Apply a percentage or fixed amount to the whole
                            order.
                        </template>
                        <template v-else>
                            {{ cart[discountLineIndex]?.variant.name }} ·
                            {{ cart[discountLineIndex]?.variant.variant }}
                        </template>
                    </DialogDescription>
                </DialogHeader>

                <div class="space-y-4 p-5">
                    <div class="grid grid-cols-2 rounded-lg bg-muted p-1">
                        <Button
                            type="button"
                            size="sm"
                            :variant="
                                discountMode === 'percentage'
                                    ? 'default'
                                    : 'ghost'
                            "
                            @click="discountMode = 'percentage'"
                        >
                            Percentage
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            :variant="
                                discountMode === 'amount' ? 'default' : 'ghost'
                            "
                            @click="discountMode = 'amount'"
                        >
                            Fixed amount
                        </Button>
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="discount-value">
                            {{
                                discountMode === 'percentage'
                                    ? 'Discount percentage'
                                    : `Discount amount (${currency})`
                            }}
                        </Label>
                        <div class="relative">
                            <Input
                                id="discount-value"
                                v-model="discountValue"
                                inputmode="decimal"
                                class="h-12 pr-14 text-lg font-semibold tabular-nums"
                                :placeholder="
                                    discountMode === 'percentage' ? '0' : '0.00'
                                "
                            />
                            <span
                                class="pointer-events-none absolute top-1/2 right-4 -translate-y-1/2 text-sm font-semibold text-muted-foreground"
                            >
                                {{
                                    discountMode === 'percentage'
                                        ? '%'
                                        : currency
                                }}
                            </span>
                        </div>
                        <p
                            v-if="
                                discountMode === 'percentage' &&
                                minorUnits(discountValue) > 10000n
                            "
                            class="text-xs text-destructive"
                        >
                            Percentage is limited to 100%.
                        </p>
                    </div>

                    <div
                        class="space-y-2 rounded-lg border bg-muted/20 p-3 text-sm"
                    >
                        <div class="flex justify-between gap-3">
                            <span class="text-muted-foreground"
                                >Before discount</span
                            >
                            <span class="font-medium tabular-nums">{{
                                money(discountBase)
                            }}</span>
                        </div>
                        <div class="flex justify-between gap-3">
                            <span class="text-muted-foreground">Discount</span>
                            <span class="font-medium text-primary tabular-nums"
                                >-{{ money(discountPreview) }}</span
                            >
                        </div>
                        <div class="flex justify-between gap-3 border-t pt-2">
                            <span class="font-semibold">After discount</span>
                            <span class="font-bold tabular-nums">{{
                                money(discountBase - discountPreview)
                            }}</span>
                        </div>
                    </div>
                </div>

                <DialogFooter
                    class="flex-row justify-between border-t bg-muted/20 p-3"
                >
                    <Button
                        type="button"
                        variant="ghost"
                        @click="clearDiscount"
                    >
                        Clear
                    </Button>
                    <Button type="button" @click="applyDiscount">
                        Apply discount
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="creditModalOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Credit sale</DialogTitle>
                    <DialogDescription
                        >Select a saved customer or enter a new customer. Record
                        today's payment and collect the remaining balance
                        later.</DialogDescription
                    >
                </DialogHeader>
                <div class="space-y-3">
                    <div v-if="creditSale" class="rounded-md border p-3">
                        <p class="font-semibold">
                            {{ creditSale.sale_number }} ·
                            {{ creditSale.customer_name }}
                        </p>
                        <p>
                            Previous balance:
                            {{ money(minorUnits(creditSale.outstanding)) }}
                        </p>
                    </div>
                    <template v-else>
                        <Label for="credit-search">Find customer</Label>
                        <Input
                            id="credit-search"
                            v-model="customerSearch"
                            placeholder="Search name or phone"
                            autocomplete="off"
                        />
                        <div
                            class="max-h-40 overflow-y-auto rounded-md border"
                            aria-label="Customer search results"
                        >
                            <button
                                v-for="customer in filteredCustomers"
                                :key="customer.id"
                                type="button"
                                class="block w-full border-b px-3 py-2 text-left text-sm hover:bg-muted"
                                :class="
                                    creditCustomerId === String(customer.id)
                                        ? 'bg-muted font-semibold'
                                        : ''
                                "
                                @click="creditCustomerId = String(customer.id)"
                            >
                                {{ customer.name }} · {{ customer.phone }}
                            </button>
                            <p
                                v-if="filteredCustomers.length === 0"
                                class="p-3 text-sm text-muted-foreground"
                            >
                                No saved customer found. Enter a new customer
                                below.
                            </p>
                        </div>
                        <p
                            v-if="selectedCreditCustomer"
                            class="text-sm font-semibold"
                        >
                            Selected: {{ selectedCreditCustomer.name }} ·
                            {{ selectedCreditCustomer.phone }}
                        </p>
                        <Button
                            v-if="canCreateCustomer"
                            type="button"
                            variant="outline"
                            @click="
                                creditCustomerId = '';
                                customerName = '';
                                customerPhone = '';
                            "
                            >Create new customer</Button
                        >
                    </template>
                    <template v-if="!creditCustomerId && canCreateCustomer">
                        <Label for="credit-name">Customer name *</Label>
                        <Input
                            id="credit-name"
                            v-model="customerName"
                            required
                        />
                        <Label for="credit-phone">Phone *</Label>
                        <Input
                            id="credit-phone"
                            v-model="customerPhone"
                            required
                        />
                    </template>
                    <Label for="credit-paid">Cash paid today</Label>
                    <Input
                        id="credit-paid"
                        v-model="creditPaid"
                        type="number"
                        min="0"
                        step="0.01"
                    />
                    <p>New items: {{ money(cartTotal()) }}</p>
                    <p class="font-semibold">
                        Balance due:
                        {{
                            money(
                                minorUnits(creditSale?.outstanding ?? '0') +
                                    cartTotal() -
                                    minorUnits(creditPaid || '0'),
                            )
                        }}
                    </p>
                    <p
                        v-for="[field, message] in checkoutErrors"
                        :key="field"
                        class="text-sm text-destructive"
                    >
                        {{ message }}
                    </p>
                </div>
                <DialogFooter>
                    <Button
                        :disabled="
                            form.processing ||
                            (!creditCustomerId &&
                                (!canCreateCustomer ||
                                    !customerName.trim() ||
                                    !customerPhone.trim())) ||
                            !/^\d+(?:\.\d{1,2})?$/.test(creditPaid) ||
                            minorUnits(creditPaid || '0') > cartTotal()
                        "
                        @click="submitCheckout(true)"
                        >{{
                            creditSale
                                ? 'Add items to bill'
                                : 'Complete credit sale'
                        }}</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="customerModalOpen">
            <DialogContent class="gap-0 overflow-hidden p-0 sm:max-w-lg">
                <DialogHeader class="border-b bg-muted/30 px-5 py-4 text-left">
                    <DialogTitle>Customer details &amp; notes</DialogTitle>
                    <DialogDescription>
                        Optional details for the receipt and future reference.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-3 p-5 sm:grid-cols-2">
                    <div
                        v-if="posSettings.show_customer_name"
                        class="grid gap-1.5"
                    >
                        <Label for="customer-name">Customer name</Label>
                        <Input
                            id="customer-name"
                            v-model="customerName"
                            autocomplete="name"
                            placeholder="Walk-in customer"
                        />
                    </div>
                    <div
                        v-if="posSettings.show_customer_phone"
                        class="grid gap-1.5"
                    >
                        <Label for="customer-phone">Phone</Label>
                        <Input
                            id="customer-phone"
                            v-model="customerPhone"
                            inputmode="tel"
                            autocomplete="tel"
                            placeholder="Customer phone"
                        />
                    </div>
                    <div
                        v-if="posSettings.show_customer_email"
                        class="grid gap-1.5 sm:col-span-2"
                    >
                        <Label for="customer-email">Email</Label>
                        <Input
                            id="customer-email"
                            v-model="customerEmail"
                            type="email"
                            autocomplete="email"
                            placeholder="Customer email"
                        />
                    </div>
                    <div
                        v-if="posSettings.show_sale_notes"
                        class="grid gap-1.5 sm:col-span-2"
                    >
                        <Label for="sale-notes">Sale notes</Label>
                        <textarea
                            id="sale-notes"
                            v-model="notes"
                            class="min-h-24 rounded-md border border-input bg-background px-3 py-2 text-sm"
                            placeholder="Add a note about this sale"
                        />
                    </div>
                </div>

                <DialogFooter class="border-t bg-muted/20 p-3">
                    <Button type="button" @click="customerModalOpen = false">
                        Done
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="receiptModalOpen">
            <DialogContent class="gap-0 overflow-hidden p-0 sm:max-w-sm">
                <DialogHeader
                    class="items-center border-b px-5 py-5 text-center"
                >
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
                        <p
                            class="text-xs font-medium text-primary-foreground/75"
                        >
                            {{
                                minorUnits(completedSale.outstanding) > 0n
                                    ? 'Credit balance due'
                                    : 'Change to customer'
                            }}
                        </p>
                        <p class="mt-1 text-3xl font-bold tabular-nums">
                            {{
                                money(
                                    minorUnits(
                                        minorUnits(completedSale.outstanding) >
                                            0n
                                            ? completedSale.outstanding
                                            : completedSale.change_given,
                                    ),
                                )
                            }}
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
                    <Button
                        v-if="completedSale && canPrintReceipt"
                        type="button"
                        class="flex-1"
                        @click="printCompletedSaleReceipt"
                    >
                        <Printer class="size-4" />
                        Print receipt
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <iframe
            v-if="receiptPrintUrl"
            :key="receiptPrintFrameKey"
            :src="receiptPrintUrl"
            title="Receipt print frame"
            class="pointer-events-none fixed size-px opacity-0"
            aria-hidden="true"
        />

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
