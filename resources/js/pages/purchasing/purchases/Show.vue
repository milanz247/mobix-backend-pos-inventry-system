<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, CreditCard, Pencil, RotateCcw, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useConfirmationDialog } from '@/composables/useConfirmationDialog';
import { index, edit, post } from '@/routes/purchases';
import purchases from '@/routes/purchases';
import type { Auth } from '@/types';

type PurchaseLine = {
    id: number;
    label: string;
    sku: string;
    tracking_mode: string;
    quantity: string;
    unit_cost: string;
    subtotal: string;
    discount_amount: string;
    total: string;
    devices: {
        id: number;
        imei: string;
        imei2: string | null;
        serial_number: string | null;
        condition: string;
        unit_cost: string;
        unit_selling_price: string | null;
        status: string | null;
        location_name: string | null;
    }[];
};
type PurchaseData = {
    id: number;
    invoice_number: string;
    invoice_date: string;
    notes: string | null;
    status: string;
    payment_status: string;
    subtotal: string;
    discount_total: string;
    total: string;
    paid_total: string;
    supplier: { id: number; name: string };
    location: { id: number; name: string; code: string };
    lines: PurchaseLine[];
    payments: {
        id: number;
        amount: string;
        payment_date: string;
        method: string;
        reference: string | null;
        recorded_by: string;
    }[];
    returns: {
        id: number;
        return_number: string;
        return_date: string;
        location: string;
        total: string;
        status: string;
    }[];
};
type PaymentMethod = { value: string; label: string };
const props = defineProps<{
    purchase: PurchaseData;
    outstanding: string;
    currency: string;
    paymentMethods: PaymentMethod[];
}>();
const auth = usePage<{ auth: Auth }>().props.auth;
const can = (permission: string): boolean =>
    auth.permissions.includes(permission);
const confirmation = useConfirmationDialog();
const posting = ref(false);
const postingError = ref('');
const form = useForm({
    operation_key: crypto.randomUUID(),
    amount: '',
    payment_date: new Date().toISOString().slice(0, 10),
    method: 'cash',
    reference: '',
    notes: '',
});
const hasOutstanding = computed(
    () => BigInt(props.outstanding.replace('.', '')) > 0n,
);
function money(value: string): string {
    const [whole = '0', fraction = '00'] = value.split('.', 2);
    return `${props.currency} ${BigInt(whole || '0').toLocaleString()}.${fraction.padEnd(2, '0').slice(0, 2)}`;
}
function postInvoice(): void {
    postingError.value = '';
    confirmation.request({
        title: `Post purchase ${props.purchase.invoice_number}?`,
        description:
            'This will receive all listed quantities and serialized devices into the selected location. Stock movement history and device records will be created atomically. This cannot be edited afterward.',
        confirmLabel: 'Post invoice',
        onConfirm: () => {
            router.post(
                post(props.purchase.id).url,
                {},
                {
                    preserveScroll: true,
                    onStart: () => {
                        posting.value = true;
                    },
                    onError: (errors) => {
                        postingError.value =
                            Object.values(errors).find(
                                (message): message is string =>
                                    typeof message === 'string',
                            ) ??
                            'The invoice could not be posted. Check the purchase details and try again.';
                    },
                    onHttpException: (response) => {
                        postingError.value =
                            response.status === 403
                                ? 'You are not authorized to post this purchase.'
                                : `The server could not post this purchase (HTTP ${response.status}). No stock should have been received.`;
                    },
                    onNetworkError: () => {
                        postingError.value =
                            'The request could not reach the server. Check your connection and refresh the purchase status before retrying.';
                    },
                    onFinish: () => {
                        posting.value = false;
                    },
                },
            );
        },
    });
}
function deletePurchase(): void {
    confirmation.request({
        title: `Delete ${props.purchase.invoice_number}?`,
        description:
            'This permanently deletes the draft invoice and its lines. This action cannot be undone.',
        confirmLabel: 'Delete draft',
        destructive: true,
        onConfirm: () =>
            router.delete(purchases.destroy(props.purchase.id).url, {
                onError: (errors) => {
                    if (errors.purchase) toast.error(errors.purchase);
                },
            }),
    });
}
function recordPayment(): void {
    form.post(purchases.payments.store(props.purchase.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('amount', 'reference', 'notes');
            form.operation_key = crypto.randomUUID();
        },
    });
}
defineOptions({
    layout: { breadcrumbs: [{ title: 'Purchases', href: index() }] },
});
</script>

<template>
    <Head :title="`Purchase ${purchase.invoice_number}`" />
    <section class="page-shell">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-2">
                <Heading
                    :title="`Purchase ${purchase.invoice_number}`"
                    description="Supplier invoice, received stock, payment history, and returns."
                />
                <div class="flex flex-wrap gap-2">
                    <Badge
                        :variant="
                            purchase.status === 'draft'
                                ? 'warning'
                                : purchase.status === 'returned'
                                  ? 'secondary'
                                  : 'success'
                        "
                        >{{ purchase.status.replaceAll('_', ' ') }}</Badge
                    ><Badge variant="outline">{{
                        purchase.payment_status.replaceAll('_', ' ')
                    }}</Badge>
                </div>
                <p
                    v-if="postingError"
                    class="rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive"
                    role="alert"
                    aria-live="polite"
                >
                    {{ postingError }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button as-child variant="outline"
                    ><Link :href="index()"
                        ><ArrowLeft /> Purchases</Link
                    ></Button
                ><Button
                    v-if="
                        purchase.status === 'draft' && can('purchases.update')
                    "
                    as-child
                    variant="outline"
                    ><Link :href="edit(purchase.id)"
                        ><Pencil /> Edit draft</Link
                    ></Button
                ><Button
                    v-if="
                        purchase.status === 'draft' && can('purchases.delete')
                    "
                    variant="destructive"
                    @click="deletePurchase"
                    ><Trash2 /> Delete draft</Button
                ><Button
                    v-if="
                        purchase.status === 'draft' &&
                        can('purchases.post') &&
                        can('inventory.receive')
                    "
                    :disabled="posting"
                    @click="postInvoice"
                    >{{ posting ? 'Posting invoice…' : 'Post invoice' }}</Button
                ><Button
                    v-if="
                        purchase.status !== 'draft' &&
                        can('purchase-returns.create')
                    "
                    as-child
                    variant="outline"
                    ><Link :href="purchases.returns.create(purchase.id)"
                        ><RotateCcw /> Return stock</Link
                    ></Button
                >
            </div>
        </div>
        <div
            class="grid gap-4 xl:grid-cols-[minmax(0,1.5fr)_minmax(19rem,1fr)]"
        >
            <section class="surface-panel space-y-5 p-5">
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <div>
                        <div class="text-xs text-muted-foreground">
                            Supplier
                        </div>
                        <div class="mt-1 font-medium">
                            {{ purchase.supplier.name }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-muted-foreground">
                            Invoice date
                        </div>
                        <div class="mt-1 font-medium">
                            {{ purchase.invoice_date }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-muted-foreground">
                            Destination
                        </div>
                        <div class="mt-1 font-medium">
                            {{ purchase.location.name }} ·
                            {{ purchase.location.code }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-muted-foreground">
                            Payment outstanding
                        </div>
                        <div class="mt-1 font-semibold">
                            {{ money(outstanding) }}
                        </div>
                    </div>
                </div>
                <div class="table-panel">
                    <div class="overflow-x-auto">
                        <table class="data-table min-w-[760px]">
                            <thead>
                                <tr>
                                    <th>Product / SKU</th>
                                    <th>Tracking</th>
                                    <th>Qty</th>
                                    <th>Unit cost</th>
                                    <th>Discount</th>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template
                                    v-for="line in purchase.lines"
                                    :key="line.id"
                                    ><tr>
                                        <td>
                                            <div class="font-medium">
                                                {{ line.label }}
                                            </div>
                                            <div
                                                class="text-xs text-muted-foreground"
                                            >
                                                {{ line.sku }}
                                            </div>
                                        </td>
                                        <td>
                                            {{
                                                line.tracking_mode ===
                                                'serialized'
                                                    ? 'Serialized'
                                                    : 'Quantity'
                                            }}
                                        </td>
                                        <td>{{ line.quantity }}</td>
                                        <td>{{ money(line.unit_cost) }}</td>
                                        <td>
                                            {{ money(line.discount_amount) }}
                                        </td>
                                        <td class="font-medium">
                                            {{ money(line.total) }}
                                        </td>
                                    </tr>
                                    <tr v-if="line.devices.length">
                                        <td colspan="6" class="bg-muted/15">
                                            <div
                                                class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3"
                                            >
                                                <div
                                                    v-for="device in line.devices"
                                                    :key="device.id"
                                                    class="rounded-md border border-border/60 bg-background p-3 text-xs"
                                                >
                                                    <div
                                                        class="font-medium tabular-nums"
                                                    >
                                                        IMEI {{ device.imei }}
                                                    </div>
                                                    <div
                                                        v-if="device.imei2"
                                                        class="text-muted-foreground"
                                                    >
                                                        IMEI 2
                                                        {{ device.imei2 }}
                                                    </div>
                                                    <div
                                                        class="mt-1 text-muted-foreground"
                                                    >
                                                        {{ device.condition }} ·
                                                        {{
                                                            money(
                                                                device.unit_cost,
                                                            )
                                                        }}
                                                        ·
                                                        {{
                                                            device.status?.replaceAll(
                                                                '_',
                                                                ' ',
                                                            ) || 'Not received'
                                                        }}<span
                                                            v-if="
                                                                device.location_name
                                                            "
                                                        >
                                                            ·
                                                            {{
                                                                device.location_name
                                                            }}</span
                                                        >
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr></template
                                >
                                <tr v-if="purchase.lines.length === 0">
                                    <td
                                        colspan="6"
                                        class="py-8 text-center text-muted-foreground"
                                    >
                                        No lines.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <p
                    v-if="purchase.notes"
                    class="text-sm whitespace-pre-line text-muted-foreground"
                >
                    {{ purchase.notes }}
                </p>
            </section>
            <div class="space-y-4">
                <section class="surface-panel space-y-3 p-5">
                    <h2 class="font-semibold">Invoice summary</h2>
                    <div class="flex justify-between text-sm">
                        <span class="text-muted-foreground">Subtotal</span
                        ><span>{{ money(purchase.subtotal) }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-muted-foreground">Discounts</span
                        ><span>− {{ money(purchase.discount_total) }}</span>
                    </div>
                    <div
                        class="flex justify-between border-t border-border/70 pt-3 font-semibold"
                    >
                        <span>Total</span
                        ><span>{{ money(purchase.total) }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-muted-foreground">Payments</span
                        ><span>{{ money(purchase.paid_total) }}</span>
                    </div>
                    <div class="flex justify-between text-sm font-semibold">
                        <span>Outstanding</span
                        ><span>{{ money(outstanding) }}</span>
                    </div>
                </section>
                <section
                    v-if="
                        purchase.status !== 'draft' &&
                        can('purchase-payments.create') &&
                        hasOutstanding
                    "
                    class="surface-panel space-y-4 p-5"
                >
                    <div>
                        <h2 class="font-semibold">Record supplier payment</h2>
                        <p class="text-sm text-muted-foreground">
                            Maximum remaining amount: {{ money(outstanding) }}
                        </p>
                    </div>
                    <form class="grid gap-3" @submit.prevent="recordPayment">
                        <div class="grid gap-2">
                            <Label for="payment-amount">Amount</Label
                            ><Input
                                id="payment-amount"
                                v-model="form.amount"
                                type="number"
                                min="0.01"
                                step="0.01"
                                :max="outstanding"
                                required
                            /><InputError :message="form.errors.amount" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="payment-date">Payment date</Label
                            ><Input
                                id="payment-date"
                                v-model="form.payment_date"
                                type="date"
                                required
                            /><InputError :message="form.errors.payment_date" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="payment-method">Method</Label
                            ><select
                                id="payment-method"
                                v-model="form.method"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            >
                                <option
                                    v-for="item in paymentMethods"
                                    :key="item.value"
                                    :value="item.value"
                                >
                                    {{ item.label }}
                                </option></select
                            ><InputError :message="form.errors.method" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="payment-reference">Reference</Label
                            ><Input
                                id="payment-reference"
                                v-model="form.reference"
                                maxlength="100"
                            /><InputError :message="form.errors.reference" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="payment-notes">Notes</Label
                            ><textarea
                                id="payment-notes"
                                v-model="form.notes"
                                rows="2"
                                maxlength="2000"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            />
                        </div>
                        <Button type="submit" :disabled="form.processing"
                            ><CreditCard />
                            {{
                                form.processing
                                    ? 'Recording…'
                                    : 'Record payment'
                            }}</Button
                        >
                    </form>
                </section>
                <section class="surface-panel space-y-3 p-5">
                    <h2 class="font-semibold">Payment history</h2>
                    <div
                        v-for="payment in purchase.payments"
                        :key="payment.id"
                        class="flex items-start justify-between gap-3 border-b border-border/60 pb-3 text-sm last:border-0 last:pb-0"
                    >
                        <div>
                            <div class="font-medium">
                                {{ money(payment.amount) }} ·
                                {{ payment.method.replaceAll('_', ' ') }}
                            </div>
                            <div class="text-xs text-muted-foreground">
                                {{ payment.payment_date }} ·
                                {{ payment.recorded_by
                                }}<span v-if="payment.reference">
                                    · {{ payment.reference }}</span
                                >
                            </div>
                        </div>
                    </div>
                    <p
                        v-if="purchase.payments.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No payments recorded.
                    </p>
                </section>
                <section class="surface-panel space-y-3 p-5">
                    <h2 class="font-semibold">Purchase returns</h2>
                    <div
                        v-for="item in purchase.returns"
                        :key="item.id"
                        class="flex items-start justify-between gap-3 border-b border-border/60 pb-3 text-sm last:border-0 last:pb-0"
                    >
                        <div>
                            <div class="font-medium">
                                {{ item.return_number }}
                            </div>
                            <div class="text-xs text-muted-foreground">
                                {{ item.return_date }} · {{ item.location }}
                            </div>
                        </div>
                        <div class="font-medium">{{ money(item.total) }}</div>
                    </div>
                    <p
                        v-if="purchase.returns.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No returns recorded.
                    </p>
                </section>
            </div>
        </div>
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
