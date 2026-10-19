<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Printer, RotateCcw } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import sales, { index, receipt } from '@/routes/sales';
import { shiftDate } from '@/lib/shift';

const page = usePage();

type Method = { value: string; label: string };
type SaleData = {
    id: number;
    sale_number: string;
    posted_at: string;
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
    <section class="page-shell space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
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
                <Button as-child variant="outline"
                    ><Link :href="index()"><ArrowLeft /> Sales</Link></Button
                ><Button as-child variant="outline"
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
            class="grid gap-4 xl:grid-cols-[minmax(0,1.3fr)_minmax(300px,0.7fr)]"
        >
            <div class="space-y-4">
                <div
                    class="surface-panel grid gap-4 p-4 sm:grid-cols-2 xl:grid-cols-3"
                >
                    <div>
                        <p class="text-xs text-muted-foreground">Date / time</p>
                        <p class="font-medium">{{ sale.posted_at }}</p>
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
                    </div>
                </div>
                <div class="table-panel">
                    <div class="overflow-x-auto">
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

            <aside class="space-y-4">
                <div class="surface-panel space-y-3 p-4">
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
                        <span>Tax</span><span>{{ money(sale.tax_total) }}</span>
                    </div>
                    <div class="flex justify-between border-t pt-3 font-bold">
                        <span>Total</span
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
                    <div class="flex justify-between text-sm">
                        <span>Refunded</span
                        ><span>{{ money(sale.refund_total) }}</span>
                    </div>
                    <div
                        class="flex justify-between border-t pt-3 font-semibold"
                    >
                        <span>Balance due</span
                        ><span>{{ money(outstanding) }}</span>
                    </div>
                </div>
                <form
                    v-if="canRecordPayment && hasOutstanding"
                    class="surface-panel space-y-3 p-4"
                    @submit.prevent="recordPayment"
                >
                    <h2 class="font-semibold">Record payment</h2>
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
                                            (minorUnits(form.cash_tendered) -
                                                minorUnits(form.amount)) %
                                            100n
                                        )
                                            .toString()
                                            .padStart(2, '0'),
                                )
                            }}
                        </p>
                    </div>
                    <div class="space-y-1">
                        <Label for="payment-reference">Reference</Label
                        ><Input
                            id="payment-reference"
                            v-model="form.reference"
                        /><InputError :message="form.errors.reference" />
                    </div>
                    <Button class="w-full" :disabled="form.processing">{{
                        form.processing ? 'Saving…' : 'Save payment'
                    }}</Button>
                </form>
                <div class="surface-panel space-y-3 p-4">
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
                        <p class="text-xs text-muted-foreground">
                            {{ item.processed_at }} · {{ item.processed_by }} ·
                            {{ item.reason }}
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
</template>
