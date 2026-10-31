<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Printer, RotateCcw } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import sales, { index, receipt } from '@/routes/sales';
import { shiftDate } from '@/lib/shift';

const page = usePage();

type Method = { value: string; label: string };
type CustomerOption = {
    id: number;
    name: string;
    phone: string;
    email: string | null;
};
type SaleData = {
    id: number;
    sale_number: string;
    posted_at: string;
    due_date: string | null;
    status: string;
    source_label: string;
    payment_status: string;
    location: { id: number; name: string; code: string };
    cashier: string;
    customer: {
        name: string | null;
        phone: string | null;
        email: string | null;
    };
    subtotal: string;
    discount_total: string;
    tax_total: string;
    total: string;
    paid_total: string;
    returned_total: string;
    refund_total: string;
    notes: string | null;
    lines: {
        is_replacement: boolean;
        id: number;
        product_name: string;
        variant_name: string;
        sku: string;
        tracking_mode: string;
        is_combo: boolean;
        imei: string | null;
        quantity: string;
        unit_price: string;
        discount_amount: string;
        subtotal: string;
        total: string;
        cost_snapshot: string | null;
        components: {
            name: string;
            sku: string;
            quantity: string;
            imei: string | null;
        }[];
    }[];
    payments: {
        id: number;
        amount: string;
        cash_tendered: string | null;
        change_given: string;
        method: string;
        reference: string | null;
        paid_at: string;
        received_by: string;
    }[];
    returns: {
        is_exchange: boolean;
        id: number;
        return_number: string | null;
        total: string;
        refund_total: string;
        reason: string;
        processed_at: string;
        processed_by: string;
        notes: string | null;
        lines: {
            id: number;
            product: string;
            quantity: string;
            total: string;
            restock: boolean;
        }[];
        refunds: {
            id: number;
            amount: string;
            method: string;
            reference: string | null;
            refunded_at: string;
            processed_by: string;
        }[];
    }[];
};
const props = defineProps<{
    sale: SaleData;
    currency: string;
    outstanding: string;
    canRecordPayment: boolean;
    canPrintReceipt: boolean;
    canPrintReturn: boolean;
    canAddItems: boolean;
    canLinkCustomer: boolean;
    canReturn: boolean;
    canRefund: boolean;
    paymentMethods: Method[];
}>();
const form = useForm({
    operation_key: crypto.randomUUID(),
    amount: '',
    cash_tendered: '',
    method: 'cash',
    reference: '',
});
const hasOutstanding = computed(
    () => BigInt(props.outstanding.replace('.', '')) > 0n,
);
const linkDialogOpen = ref(false);
const customerSearch = ref('');
const customerOptions = ref<CustomerOption[]>([]);
const selectedCustomer = ref<CustomerOption | null>(null);
const customerSearchError = ref('');
const customerLinkForm = useForm({ customer_id: null as number | null });
let customerSearchTimer: ReturnType<typeof setTimeout> | undefined;
let customerSearchController: AbortController | undefined;
watch(customerSearch, (value) => {
    if (customerSearchTimer) clearTimeout(customerSearchTimer);
    customerSearchController?.abort();
    customerOptions.value = [];
    customerSearchError.value = '';
    const term = value.trim();
    if (term.length < 2) return;
    customerSearchTimer = setTimeout(async () => {
        customerSearchController = new AbortController();
        try {
            const response = await fetch(
                `/customers/search?search=${encodeURIComponent(term)}`,
                {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    signal: customerSearchController.signal,
                },
            );
            if (!response.ok) throw new Error('Customer search failed.');
            customerOptions.value = (await response.json()) as CustomerOption[];
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError')
                return;
            customerSearchError.value =
                'Could not search customers. Try again.';
        }
    }, 250);
});
function linkHistoricalSale(): void {
    if (!selectedCustomer.value) return;
    customerLinkForm.customer_id = selectedCustomer.value.id;
    customerLinkForm.patch(`/sales/${props.sale.id}/customer`, {
        preserveScroll: true,
        onSuccess: () => {
            linkDialogOpen.value = false;
            router.reload({ only: ['sale', 'canLinkCustomer'] });
        },
    });
}
onBeforeUnmount(() => {
    if (customerSearchTimer) clearTimeout(customerSearchTimer);
    customerSearchController?.abort();
});
function formatMinor(value: bigint): string {
    return `${value / 100n}.${(value % 100n).toString().padStart(2, '0')}`;
}
function money(value: string): string {
    const [whole = '0', fraction = '00'] = value.split('.', 2);
    return `${props.currency} ${BigInt(whole || '0').toLocaleString()}.${fraction.padEnd(2, '0').slice(0, 2)}`;
}
function minorUnits(value: string): bigint {
    const [whole = '0', fraction = ''] = value.split('.', 2);
    return (
        BigInt(whole || '0') * 100n +
        BigInt(fraction.padEnd(2, '0').slice(0, 2))
    );
}
function recordPayment(): void {
    if (form.method !== 'cash') form.cash_tendered = '';
    form.post(sales.payments.store(props.sale.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('amount', 'reference', 'cash_tendered');
            form.operation_key = crypto.randomUUID();
        },
    });
}
defineOptions({
    layout: { breadcrumbs: [{ title: 'Sales history', href: index() }] },
});
</script>

<template>
    <Head :title="`Sale ${sale.sale_number}`" />
    <section class="page-shell h-0 min-h-0 gap-2 overflow-hidden p-2 sm:p-3">
        <div class="flex shrink-0 flex-wrap items-start justify-between gap-2">
            <div class="space-y-2">
                <Heading
                    :title="sale.sale_number"
                    description="Completed invoice, payment records, and inventory posting."
                />
                <div class="flex flex-wrap gap-2">
                    <Badge
                        :variant="
                            sale.status === 'returned'
                                ? 'secondary'
                                : sale.status === 'partially_returned'
                                  ? 'warning'
                                  : 'success'
                        "
                        >{{ sale.status.replaceAll('_', ' ') }}</Badge
                    ><Badge variant="outline">{{
                        sale.payment_status.replaceAll('_', ' ')
                    }}</Badge>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button v-if="canAddItems" as-child
                    ><Link
                        :href="`/pos?credit_sale_id=${sale.id}&location_id=${sale.location.id}`"
                        >Add items to this bill</Link
                    ></Button
                >
                <Button as-child variant="outline"
                    ><Link :href="index()"><ArrowLeft /> Sales</Link></Button
                ><Button v-if="canPrintReceipt" as-child variant="outline"
                    ><Link :href="receipt(sale.id)"
                        ><Printer /> Print receipt</Link
                    ></Button
                ><Button v-if="canReturn" as-child variant="outline"
                    ><Link :href="sales.returns.create(sale.id)"
                        ><RotateCcw /> Process return</Link
                    ></Button
                >
            </div>
        </div>

        <div
            class="grid min-h-0 flex-1 grid-rows-[minmax(0,1fr)_minmax(0,1fr)] gap-3 overflow-hidden xl:grid-cols-[minmax(0,1.3fr)_minmax(300px,0.7fr)] xl:grid-rows-1"
        >
            <div class="flex min-h-0 flex-col gap-3 overflow-hidden">
                <div
                    class="surface-panel grid shrink-0 gap-x-4 gap-y-2 p-3 sm:grid-cols-2 xl:grid-cols-3"
                >
                    <div>
                        <p class="text-xs text-muted-foreground">Date / time</p>
                        <p class="font-medium">{{ sale.posted_at }}</p>
                        <p v-if="sale.due_date" class="text-sm">
                            Payment due {{ sale.due_date }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Location</p>
                        <p class="font-medium">
                            {{ sale.location.name }} · {{ sale.location.code }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Cashier</p>
                        <p class="font-medium">{{ sale.cashier }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Sale source</p>
                        <p class="font-medium">{{ sale.source_label }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Customer</p>
                        <p class="font-medium">
                            {{ sale.customer.name || 'Walk-in customer' }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{
                                sale.customer.phone ||
                                sale.customer.email ||
                                'No contact saved'
                            }}
                        </p>
                        <Button
                            v-if="canLinkCustomer"
                            type="button"
                            variant="link"
                            class="h-auto p-0 text-sm"
                            @click="linkDialogOpen = true"
                        >
                            Link historical invoice
                        </Button>
                    </div>
                </div>
                <div class="table-panel min-h-0 flex-1 overflow-hidden">
                    <div class="h-full overflow-auto">
                        <table class="data-table min-w-[760px]">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>IMEI / details</th>
                                    <th class="text-right">Qty</th>
                                    <th class="text-right">Unit price</th>
                                    <th class="text-right">Discount</th>
                                    <th class="text-right">Total</th>
                                    <th
                                        v-if="
                                            sale.lines.some(
                                                (line) =>
                                                    line.cost_snapshot !== null,
                                            )
                                        "
                                        class="text-right"
                                    >
                                        Cost snapshot
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <template
                                    v-for="line in sale.lines"
                                    :key="line.id"
                                    ><tr>
                                        <td>
                                            <div class="font-medium">
                                                {{ line.product_name }} ·
                                                {{ line.variant_name }}
                                            </div>
                                            <div
                                                class="text-xs text-muted-foreground"
                                            >
                                                <Badge
                                                    v-if="line.is_replacement"
                                                    variant="outline"
                                                    class="mr-2"
                                                    >Warranty replacement</Badge
                                                >
                                                {{ line.sku
                                                }}<Badge
                                                    v-if="line.is_combo"
                                                    class="ml-2"
                                                    variant="secondary"
                                                    >Bundle</Badge
                                                >
                                            </div>
                                        </td>
                                        <td class="text-xs">
                                            {{
                                                line.imei ||
                                                (line.is_combo
                                                    ? 'Virtual bundle components'
                                                    : '—')
                                            }}
                                        </td>
                                        <td class="text-right tabular-nums">
                                            {{ line.quantity }}
                                        </td>
                                        <td class="text-right tabular-nums">
                                            {{ money(line.unit_price) }}
                                        </td>
                                        <td class="text-right tabular-nums">
                                            {{ money(line.discount_amount) }}
                                        </td>
                                        <td
                                            class="text-right font-medium tabular-nums"
                                        >
                                            {{ money(line.total) }}
                                        </td>
                                        <td
                                            v-if="
                                                sale.lines.some(
                                                    (entry) =>
                                                        entry.cost_snapshot !==
                                                        null,
                                                )
                                            "
                                            class="text-right tabular-nums"
                                        >
                                            {{
                                                line.cost_snapshot === null
                                                    ? '—'
                                                    : money(line.cost_snapshot)
                                            }}
                                        </td>
                                    </tr>
                                    <tr
                                        v-for="component in line.components"
                                        :key="`${line.id}-${component.sku}-${component.imei}`"
                                        class="bg-muted/30"
                                    >
                                        <td class="pl-8 text-sm">
                                            ↳ {{ component.name }}
                                            <span
                                                class="text-xs text-muted-foreground"
                                                >{{ component.sku }}</span
                                            >
                                        </td>
                                        <td class="text-xs">
                                            {{
                                                component.imei ||
                                                'Bundle component'
                                            }}
                                        </td>
                                        <td class="text-right tabular-nums">
                                            {{ component.quantity }}
                                        </td>
                                        <td
                                            colspan="4"
                                            class="text-xs text-muted-foreground"
                                        >
                                            Included in bundle; no separate sale
                                            price
                                        </td>
                                    </tr></template
                                >
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <aside
                class="flex min-h-0 flex-col gap-3 overflow-y-auto overscroll-contain pr-1"
            >
                <div class="surface-panel shrink-0 space-y-2 p-3">
                    <h2 class="font-semibold">Totals</h2>
                    <div class="flex justify-between text-sm">
                        <span>Subtotal</span
                        ><span class="tabular-nums">{{
                            money(sale.subtotal)
                        }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span>Discount</span
                        ><span class="tabular-nums"
                            >−{{ money(sale.discount_total) }}</span
                        >
                    </div>
                    <div class="flex justify-between text-sm">
                        <span>Tax included</span
                        ><span>{{ money(sale.tax_total) }}</span>
                    </div>
                    <div class="flex justify-between border-t pt-2 font-bold">
                        <span>{{
                            sale.returned_total !== '0.00'
                                ? 'Gross total'
                                : 'Total'
                        }}</span
                        ><span class="tabular-nums">{{
                            money(sale.total)
                        }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span>Paid</span
                        ><span>{{ money(sale.paid_total) }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span>Returned</span
                        ><span>{{ money(sale.returned_total) }}</span>
                    </div>
                    <div
                        v-if="sale.returned_total !== '0.00'"
                        class="flex justify-between text-sm font-semibold"
                    >
                        <span>Total after returns</span
                        ><span>{{
                            money(
                                formatMinor(
                                    minorUnits(sale.total) -
                                        minorUnits(sale.returned_total),
                                ),
                            )
                        }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span>Refunded</span
                        ><span>{{ money(sale.refund_total) }}</span>
                    </div>
                    <div
                        class="flex justify-between border-t pt-2 font-semibold"
                    >
                        <span>Balance due</span
                        ><span>{{ money(outstanding) }}</span>
                    </div>
                </div>
                <form
                    v-if="canRecordPayment && hasOutstanding"
                    class="surface-panel shrink-0 space-y-2 p-3"
                    @submit.prevent="recordPayment"
                >
                    <h2 class="font-semibold">Record payment</h2>
                    <p class="text-xs text-muted-foreground">
                        Add a payment whenever the customer pays, or settle the
                        full balance.
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        @click="form.amount = outstanding"
                        >Use full balance / Final payment</Button
                    >
                    <div class="grid grid-cols-2 gap-2">
                        <div class="space-y-1">
                            <Label for="payment-amount">Amount</Label
                            ><Input
                                id="payment-amount"
                                v-model="form.amount"
                                required
                                inputmode="decimal"
                            /><InputError :message="form.errors.amount" />
                        </div>
                        <div class="space-y-1">
                            <Label for="payment-method">Method</Label
                            ><select
                                id="payment-method"
                                v-model="form.method"
                                class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option
                                    v-for="method in paymentMethods"
                                    :key="method.value"
                                    :value="method.value"
                                >
                                    {{ method.label }}
                                </option>
                            </select>
                        </div>
                        <div v-if="form.method === 'cash'" class="space-y-1">
                            <Label for="payment-cash-tendered"
                                >Cash received (optional)</Label
                            >
                            <Input
                                id="payment-cash-tendered"
                                v-model="form.cash_tendered"
                                inputmode="decimal"
                            />
                            <InputError :message="form.errors.cash_tendered" />
                            <p
                                v-if="
                                    form.amount &&
                                    form.cash_tendered &&
                                    minorUnits(form.cash_tendered) >=
                                        minorUnits(form.amount)
                                "
                                class="text-xs text-muted-foreground"
                            >
                                Change due:
                                {{
                                    money(
                                        (
                                            (minorUnits(form.cash_tendered) -
                                                minorUnits(form.amount)) /
                                            100n
                                        ).toString() +
                                            '.' +
                                            (
                                                (minorUnits(
                                                    form.cash_tendered,
                                                ) -
                                                    minorUnits(form.amount)) %
                                                100n
                                            )
                                                .toString()
                                                .padStart(2, '0'),
                                    )
                                }}
                            </p>
                        </div>
                        <div class="col-span-2 space-y-1">
                            <Label for="payment-reference">Reference</Label
                            ><Input
                                id="payment-reference"
                                v-model="form.reference"
                            /><InputError :message="form.errors.reference" />
                        </div>
                    </div>
                    <Button class="w-full" :disabled="form.processing">{{
                        form.processing ? 'Saving…' : 'Save payment'
                    }}</Button>
                </form>
                <div class="surface-panel shrink-0 space-y-2 p-3">
                    <h2 class="font-semibold">Payment history</h2>
                    <div
                        v-for="payment in sale.payments"
                        :id="`payment-${payment.id}`"
                        :key="payment.id"
                        class="flex justify-between gap-3 border-b pb-2 text-sm last:border-0"
                    >
                        <div>
                            <p class="font-medium">
                                {{ payment.method.replaceAll('_', ' ') }}
                                <span class="text-xs text-muted-foreground">
                                    · PAY-{{ payment.id }} · posted</span
                                >
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ payment.paid_at }} · {{ payment.received_by
                                }}<span v-if="payment.reference">
                                    · {{ payment.reference }}</span
                                >
                            </p>
                        </div>
                        <span class="font-medium tabular-nums">{{
                            money(payment.amount)
                        }}</span>
                        <p
                            v-if="payment.change_given !== '0.00'"
                            class="text-xs text-muted-foreground"
                        >
                            Cash
                            {{ money(payment.cash_tendered || payment.amount) }}
                            · change {{ money(payment.change_given) }}
                        </p>
                    </div>
                    <p
                        v-if="sale.payments.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No payments recorded.
                    </p>
                </div>
                <div
                    v-if="sale.returns.length"
                    class="surface-panel space-y-3 p-4"
                >
                    <h2 class="font-semibold">Returns</h2>
                    <div
                        v-for="item in sale.returns"
                        :key="item.id"
                        class="border-b pb-2 text-sm last:border-0"
                    >
                        <div class="flex justify-between">
                            <span class="font-medium">{{
                                item.return_number || `Return ${item.id}`
                            }}</span
                            ><span>{{ money(item.total) }}</span>
                        </div>
                        <Button
                            v-if="canPrintReturn"
                            as-child
                            variant="outline"
                            size="sm"
                            class="my-2"
                            ><Link
                                :href="`/sales/${sale.id}/returns/${item.id}/receipt`"
                                ><Printer /> Print
                                {{
                                    item.is_exchange ? 'replacement' : 'return'
                                }}
                                receipt</Link
                            ></Button
                        >
                        <p class="text-xs text-muted-foreground">
                            {{ item.processed_at }} · {{ item.processed_by }} ·
                            {{ item.reason }} ·
                            {{
                                item.is_exchange
                                    ? 'Replacement issued — no cash refund'
                                    : 'Return / Refund'
                            }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Refund {{ money(item.refund_total) }}
                        </p>
                        <p
                            v-if="item.notes"
                            class="mt-2 text-sm whitespace-pre-wrap"
                        >
                            {{ item.notes }}
                        </p>
                        <ul
                            class="mt-2 space-y-1 text-xs text-muted-foreground"
                        >
                            <li v-for="line in item.lines" :key="line.id">
                                {{ line.product }} · {{ line.quantity }} ·
                                {{ money(line.total) }} ·
                                {{
                                    line.restock ? 'Restocked' : 'Not restocked'
                                }}
                            </li>
                        </ul>
                        <div
                            v-for="refund in item.refunds"
                            :id="`refund-${refund.id}`"
                            :key="refund.id"
                            class="mt-3 rounded-lg border bg-background p-3 text-xs"
                        >
                            <p class="font-semibold">
                                REF-{{ refund.id }} ·
                                {{ refund.method.replaceAll('_', ' ') }} ·
                                {{ money(refund.amount) }} · posted
                            </p>
                            <p class="mt-1 text-muted-foreground">
                                {{
                                    shiftDate(
                                        refund.refunded_at,
                                        page.props.businessPreferences.timezone,
                                    )
                                }}
                                ·
                                {{ refund.processed_by }}
                            </p>
                            <p v-if="refund.reference" class="mt-1">
                                Reference: {{ refund.reference }}
                            </p>
                        </div>
                    </div>
                </div>
                <p v-if="sale.notes" class="surface-panel p-4 text-sm">
                    <span class="font-medium">Notes:</span> {{ sale.notes }}
                </p>
            </aside>
        </div>
    </section>
    <Dialog v-model:open="linkDialogOpen">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Link invoice to a customer</DialogTitle>
                <DialogDescription>
                    Invoice {{ sale.sale_number }} currently records “{{
                        sale.customer.name || 'Walk-in customer'
                    }}”. Linking it adds this existing invoice to the selected
                    customer’s history without changing its saved customer
                    details, prices, payments, or dates.
                </DialogDescription>
            </DialogHeader>
            <div class="space-y-3">
                <p
                    v-if="sale.payment_status !== 'paid'"
                    class="rounded-lg border border-amber-500/40 bg-amber-500/5 p-3 text-sm"
                >
                    This invoice has an outstanding balance of
                    {{ money(outstanding) }}. Linking it will include that
                    balance in the customer account. Confirm the customer
                    identity before saving.
                </p>
                <Label for="historical-customer-search">Search customers</Label>
                <Input
                    id="historical-customer-search"
                    v-model="customerSearch"
                    placeholder="Name or phone number"
                    autocomplete="off"
                />
                <p
                    v-if="customerSearch.trim().length < 2"
                    class="text-sm text-muted-foreground"
                >
                    Enter at least two characters.
                </p>
                <div
                    v-else-if="customerOptions.length"
                    class="max-h-56 overflow-y-auto rounded-lg border"
                >
                    <button
                        v-for="customer in customerOptions"
                        :key="customer.id"
                        type="button"
                        class="flex w-full items-start justify-between gap-3 border-b p-3 text-left last:border-0 hover:bg-muted"
                        @click="selectedCustomer = customer"
                    >
                        <span
                            ><span class="block font-medium">{{
                                customer.name
                            }}</span
                            ><span class="text-sm text-muted-foreground"
                                >{{ customer.phone
                                }}<span v-if="customer.email">
                                    · {{ customer.email }}</span
                                ></span
                            ></span
                        >
                        <span
                            v-if="selectedCustomer?.id === customer.id"
                            class="text-primary"
                            >Selected</span
                        >
                    </button>
                </div>
                <p
                    v-else-if="!customerSearchError"
                    class="text-sm text-muted-foreground"
                >
                    No matching customers found.
                </p>
                <p v-if="customerSearchError" class="text-sm text-destructive">
                    {{ customerSearchError }}
                </p>
                <p
                    v-if="selectedCustomer"
                    class="rounded-lg bg-muted p-3 text-sm"
                >
                    Selected: <strong>{{ selectedCustomer.name }}</strong> ·
                    {{ selectedCustomer.phone }}
                </p>
                <InputError :message="customerLinkForm.errors.customer_id" />
            </div>
            <DialogFooter>
                <Button
                    type="button"
                    variant="outline"
                    @click="linkDialogOpen = false"
                    >Cancel</Button
                >
                <Button
                    type="button"
                    :disabled="!selectedCustomer || customerLinkForm.processing"
                    @click="linkHistoricalSale"
                    >Link invoice</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
