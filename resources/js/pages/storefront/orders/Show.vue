<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import StaffBankTransferPanel, {
    type PaymentAttempt,
} from './StaffBankTransferPanel.vue';

type Item = {
    name: string;
    slug: string;
    variant_name: string;
    condition: string | null;
    included_device_conditions:
        | { variant_name: string; condition: string }[]
        | null;
    quantity: string;
    unit_price: string;
    line_total: string;
};
type Order = {
    reference: string;
    submitted_at: string | null;
    status: string;
    payment_method: string;
    payment_status: string;
    currency: string;
    subtotal: string;
    discount_total: string;
    delivery_fee: string;
    total: string;
    contact: { name?: string; phone?: string; email?: string } | null;
    address: {
        line1?: string;
        line2?: string;
        city?: string;
        postal_code?: string;
    } | null;
    delivery_area: string | null;
    pickup_location: string | null;
    location: string;
    reservation_expires_at: string | null;
    cod_confirmation_deadline_at: string | null;
    bank_proof_deadline_at: string | null;
    items: Item[];
};
type History = { status: string; message: string; at: string };
type PrivateHistory = {
    from: string | null;
    to: string;
    reason: string | null;
    actor_id: number | null;
    at: string;
};
type Call = {
    outcome: string;
    actor_name_snapshot: string;
    created_at: string;
    internal_note: string | null;
};
type Reservation = {
    id: number;
    item_name: string;
    component_name: string;
    quantity: string;
    status: string;
    expires_at: string | null;
    is_serialized: boolean;
    latest_check: { result: string; checked_at: string } | null;
};
type PickCheck = {
    reservation_id: number;
    result: string;
    checked_quantity: string;
    internal_note: string | null;
    performed_by: number;
    checked_at: string;
};
const props = defineProps<{
    order: Order;
    public_history: History[];
    private_history: PrivateHistory[];
    call_attempts: Call[];
    reservations: Reservation[];
    pick_checks: PickCheck[];
    preparer_name: string | null;
    can_start_preparation: boolean;
    can_verify_picks: boolean;
    can_mark_ready: boolean;
    can_handover_pickup: boolean;
    can_confirm: boolean;
    can_confirm_bank: boolean;
    can_cancel: boolean;
    bank_confirmation: {
        actor_name_snapshot: string;
        internal_note: string | null;
        confirmed_at: string;
    } | null;
    payment_attempts: PaymentAttempt[];
    payment_remaining: string | null;
    payment_verified_amount: string | null;
    can_view_proofs: boolean;
    can_verify_payments: boolean;
    lifecycle_issues: string[];
}>();
const call = useForm({ outcome: '', internal_note: '' });
const confirmation = useForm({});
const bankConfirmation = useForm({ internal_note: '' });
const cancellation = useForm({});
const preparation = useForm({});
const ready = useForm({});
const pickupHandover = useForm({ operation_key: '', cash_tendered: '' });
watch(
    () => pickupHandover.cash_tendered,
    () => {
        pickupHandover.operation_key = '';
    },
);
const pick = useForm({
    checked_quantity: '',
    device_identifier: '',
    observed_condition: '',
    internal_note: '',
});
const selectedReservation = ref<number | null>(null);
const allPicked = computed(
    () =>
        props.reservations.length > 0 &&
        props.reservations.every(
            (row) => row.latest_check?.result === 'matched',
        ),
);
const callError = computed(() => Object.values(call.errors)[0] ?? '');
const confirmationError = computed(
    () => Object.values(confirmation.errors)[0] ?? '',
);
const cancellationError = computed(
    () => Object.values(cancellation.errors)[0] ?? '',
);
const base = `/storefront/orders/${props.order.reference}`;
const label = (value: string) => value.replaceAll('_', ' ');
function recordCall() {
    call.post(`${base}/calls`, {
        preserveScroll: true,
        onSuccess: () => call.reset(),
    });
}
function confirmCod() {
    if (
        window.confirm(
            'Confirm that you spoke with the customer and they accepted the exact items, address and COD amount?',
        )
    ) {
        confirmation.post(`${base}/confirm-cod`, { preserveScroll: true });
    }
}
function confirmBank() {
    if (
        window.confirm(
            'Payment has been verified separately. Confirm this bank-transfer order for preparation?',
        )
    ) {
        bankConfirmation.post(`${base}/confirm-bank`, { preserveScroll: true });
    }
}
function collectPickup() {
    if (pickupHandover.processing) return;
    if (
        !window.confirm(
            'The customer is here. Collect the full accepted COD amount and hand over the exact picked items now?',
        )
    )
        return;
    if (!pickupHandover.operation_key)
        pickupHandover.operation_key = crypto.randomUUID();
    pickupHandover.post(`${base}/handover/pickup`, { preserveScroll: true });
}
function cancelDeclined() {
    if (
        window.confirm(
            'Cancel this declined COD order and release its reserved stock? This action cannot be undone here.',
        )
    ) {
        cancellation.post(`${base}/cancel-declined`, { preserveScroll: true });
    }
}
function startPreparation() {
    if (
        window.confirm(
            'Start preparing this confirmed order using its reserved items?',
        )
    ) {
        preparation.post(`${base}/preparation/start`, { preserveScroll: true });
    }
}
function openPick(row: Reservation) {
    selectedReservation.value = row.id;
    pick.reset();
    pick.checked_quantity = row.quantity;
}
function recordPick() {
    if (selectedReservation.value === null) return;
    pick.post(`${base}/preparation/checks/${selectedReservation.value}`, {
        preserveScroll: true,
        onSuccess: () => {
            selectedReservation.value = null;
            pick.reset();
        },
    });
}
function markReady() {
    if (
        window.confirm(
            'All reserved items have been physically checked. Mark this order ready?',
        )
    ) {
        ready.post(`${base}/preparation/ready`, { preserveScroll: true });
    }
}
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Online orders', href: '/storefront/orders' }],
    },
});
</script>

<template>
    <Head :title="`Online order ${order.reference}`" />
    <div class="page-shell space-y-6">
        <Link href="/storefront/orders" class="text-sm font-semibold underline"
            >← All online orders</Link
        >
        <Heading
            :title="`Order ${order.reference}`"
            description="Accepted customer terms and current operational state."
        />
        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
            <div class="space-y-6">
                <section class="space-y-3 rounded-xl border p-5">
                    <h2 class="text-lg font-semibold">Order and customer</h2>
                    <p class="text-sm">
                        Submitted
                        {{
                            order.submitted_at
                                ? new Date(order.submitted_at).toLocaleString()
                                : '—'
                        }}
                    </p>
                    <div class="flex flex-wrap gap-2 text-sm">
                        <span
                            class="rounded-md bg-muted px-3 py-1 capitalize"
                            >{{ label(order.status) }}</span
                        ><span
                            class="rounded-md bg-muted px-3 py-1 capitalize"
                            >{{ label(order.payment_status) }}</span
                        >
                    </div>
                    <p class="text-sm">
                        {{ order.contact?.name ?? 'Customer' }} ·
                        {{ order.contact?.phone ?? 'No phone'
                        }}<span v-if="order.contact?.email">
                            · {{ order.contact.email }}</span
                        >
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{
                            [
                                order.address?.line1,
                                order.address?.line2,
                                order.address?.city,
                                order.address?.postal_code,
                            ]
                                .filter(Boolean)
                                .join(', ')
                        }}
                    </p>
                    <p class="text-sm">
                        Delivery area: {{ order.delivery_area ?? '—' }} ·
                        Fulfillment: {{ order.location }}
                    </p>
                </section>
                <section class="rounded-xl border p-5">
                    <h2 class="text-lg font-semibold">Accepted items</h2>
                    <div
                        v-for="(item, index) in order.items"
                        :key="index"
                        class="grid gap-2 border-b py-4 last:border-0 sm:grid-cols-[1fr_auto]"
                    >
                        <div>
                            <p class="font-semibold">{{ item.name }}</p>
                            <p class="text-sm text-muted-foreground">
                                {{ item.variant_name }} · Qty {{ item.quantity
                                }}<span v-if="item.condition">
                                    · {{ item.condition }}</span
                                >
                            </p>
                            <p
                                v-for="(
                                    part, partIndex
                                ) in item.included_device_conditions ?? []"
                                :key="partIndex"
                                class="text-xs text-muted-foreground"
                            >
                                {{ part.variant_name }}: {{ part.condition }}
                            </p>
                        </div>
                        <div class="text-sm sm:text-right">
                            <p>{{ order.currency }} {{ item.line_total }}</p>
                            <p class="text-muted-foreground">
                                {{ order.currency }} {{ item.unit_price }} each
                            </p>
                        </div>
                    </div>
                </section>
                <section class="rounded-xl border p-5">
                    <h2 class="text-lg font-semibold">
                        Customer-visible status history
                    </h2>
                    <ol class="mt-4 space-y-3 border-l pl-5">
                        <li
                            v-for="(event, index) in public_history"
                            :key="index"
                            class="text-sm"
                        >
                            <p class="font-medium capitalize">
                                {{ label(event.status) }}
                            </p>
                            <p>{{ event.message }}</p>
                            <time
                                :datetime="event.at"
                                class="text-xs text-muted-foreground"
                                >{{ new Date(event.at).toLocaleString() }}</time
                            >
                        </li>
                    </ol>
                </section>
                <section class="rounded-xl border p-5">
                    <h2 class="text-lg font-semibold">Private staff audit</h2>
                    <p class="mt-1 text-xs text-muted-foreground">
                        These details are not shown to customers.
                    </p>
                    <div
                        v-for="(event, index) in private_history"
                        :key="index"
                        class="border-b py-3 text-sm last:border-0"
                    >
                        {{ label(event.to) }} · {{ event.reason ?? '—' }} ·
                        Staff ID {{ event.actor_id ?? '—' }} ·
                        {{ new Date(event.at).toLocaleString() }}
                    </div>
                    <h3 class="mt-5 font-semibold">Phone call attempts</h3>
                    <p
                        v-if="!call_attempts.length"
                        class="mt-2 text-sm text-muted-foreground"
                    >
                        No calls recorded.
                    </p>
                    <div
                        v-for="(attempt, index) in call_attempts"
                        :key="index"
                        class="border-b py-3 text-sm last:border-0"
                    >
                        <p class="font-medium capitalize">
                            {{ label(attempt.outcome) }} ·
                            {{ attempt.actor_name_snapshot }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ new Date(attempt.created_at).toLocaleString() }}
                        </p>
                        <p
                            v-if="attempt.internal_note"
                            class="mt-1 whitespace-pre-wrap"
                        >
                            {{ attempt.internal_note }}
                        </p>
                    </div>
                </section>
            </div>
            <aside class="space-y-6 xl:self-start">
                <section class="rounded-xl border p-5">
                    <h2 class="text-lg font-semibold">Accepted total</h2>
                    <dl class="mt-4 space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt>Products</dt>
                            <dd>{{ order.currency }} {{ order.subtotal }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt>Discount</dt>
                            <dd>
                                {{ order.currency }} {{ order.discount_total }}
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt>Delivery</dt>
                            <dd>
                                {{ order.currency }} {{ order.delivery_fee }}
                            </dd>
                        </div>
                        <div
                            class="flex justify-between border-t pt-3 font-bold"
                        >
                            <dt>Final total</dt>
                            <dd>{{ order.currency }} {{ order.total }}</dd>
                        </div>
                    </dl>
                    <p class="mt-4 text-sm capitalize">
                        {{ label(order.payment_method) }} ·
                        {{ label(order.payment_status) }}
                    </p>
                    <p
                        v-if="order.payment_method === 'bank_transfer'"
                        class="mt-3 text-sm text-muted-foreground"
                    >
                        Bank payment verification and order confirmation are
                        separate actions. Verified amount: {{ order.currency }}
                        {{ payment_verified_amount ?? '—' }}. Preparation
                        requires both actions.
                    </p>
                    <p
                        v-else-if="order.payment_status === 'cod_due'"
                        class="mt-3 text-sm text-muted-foreground"
                    >
                        COD payment remains due; phone confirmation does not
                        collect it.
                    </p>
                </section>
                <section
                    v-if="can_handover_pickup"
                    class="rounded-xl border p-5"
                >
                    <h2 class="text-lg font-semibold">
                        Customer pickup handover
                    </h2>
                    <p class="mt-2 text-sm text-muted-foreground">
                        Collect the full COD amount at
                        {{ order.pickup_location || order.location }}. An open
                        cashier shift at this shop is required. This action
                        posts the sale and exact reserved stock once.
                    </p>
                    <form
                        class="mt-4 space-y-3"
                        @submit.prevent="collectPickup"
                    >
                        <label class="block text-sm" for="pickup-cash-tendered"
                            >Cash received ({{ order.currency }})</label
                        >
                        <Input
                            id="pickup-cash-tendered"
                            v-model="pickupHandover.cash_tendered"
                            type="number"
                            inputmode="decimal"
                            min="0.01"
                            step="0.01"
                            required
                        />
                        <p
                            v-if="Object.values(pickupHandover.errors).length"
                            role="alert"
                            class="text-sm text-destructive"
                        >
                            {{ Object.values(pickupHandover.errors)[0] }}
                        </p>
                        <Button
                            type="submit"
                            :disabled="pickupHandover.processing"
                        >
                            {{
                                pickupHandover.processing
                                    ? 'Recording handover…'
                                    : 'Collect cash and hand over'
                            }}
                        </Button>
                    </form>
                </section>
                <StaffBankTransferPanel
                    v-if="order.payment_method === 'bank_transfer'"
                    :reference="order.reference"
                    :currency="order.currency"
                    :expected-amount="order.total"
                    :remaining-amount="payment_remaining"
                    :payment-status="order.payment_status"
                    :attempts="payment_attempts"
                    :can-view="can_view_proofs"
                    :can-verify="can_verify_payments"
                />
                <section
                    v-if="order.payment_method === 'bank_transfer'"
                    class="rounded-xl border p-5"
                >
                    <h2 class="text-lg font-semibold">
                        Bank order confirmation
                    </h2>
                    <p v-if="bank_confirmation" class="mt-2 text-sm">
                        Confirmed by
                        {{ bank_confirmation.actor_name_snapshot }} on
                        {{
                            new Date(
                                bank_confirmation.confirmed_at,
                            ).toLocaleString()
                        }}.
                    </p>
                    <p
                        v-if="bank_confirmation?.internal_note"
                        class="mt-2 text-sm text-muted-foreground"
                    >
                        Private note: {{ bank_confirmation.internal_note }}
                    </p>
                    <p
                        v-if="!bank_confirmation"
                        class="mt-2 text-sm text-muted-foreground"
                    >
                        A verified transfer does not confirm this order
                        automatically.
                    </p>
                    <form
                        v-if="can_confirm_bank"
                        class="mt-4 space-y-3"
                        @submit.prevent="confirmBank"
                    >
                        <label class="block text-sm"
                            >Private confirmation note (optional)
                            <Input
                                v-model="bankConfirmation.internal_note"
                                maxlength="1000"
                                class="mt-1"
                            />
                        </label>
                        <p
                            v-if="Object.values(bankConfirmation.errors).length"
                            role="alert"
                            class="text-sm text-destructive"
                        >
                            {{ Object.values(bankConfirmation.errors)[0] }}
                        </p>
                        <Button
                            type="submit"
                            :disabled="bankConfirmation.processing"
                        >
                            {{
                                bankConfirmation.processing
                                    ? 'Confirming…'
                                    : 'Confirm bank order'
                            }}
                        </Button>
                    </form>
                </section>
                <section class="rounded-xl border p-5">
                    <h2 class="text-lg font-semibold">Reservations</h2>
                    <p v-if="preparer_name" class="mt-2 text-sm">
                        Preparation started by {{ preparer_name }}.
                    </p>
                    <p
                        v-if="order.reservation_expires_at"
                        class="mt-2 text-xs text-muted-foreground"
                    >
                        Deadline
                        {{
                            new Date(
                                order.reservation_expires_at,
                            ).toLocaleString()
                        }}
                    </p>
                    <p
                        v-if="order.cod_confirmation_deadline_at"
                        class="mt-2 text-xs text-muted-foreground"
                    >
                        COD confirmation deadline
                        {{
                            new Date(
                                order.cod_confirmation_deadline_at,
                            ).toLocaleString()
                        }}
                    </p>
                    <p
                        v-if="order.bank_proof_deadline_at"
                        class="mt-2 text-xs text-muted-foreground"
                    >
                        Bank proof deadline
                        {{
                            new Date(
                                order.bank_proof_deadline_at,
                            ).toLocaleString()
                        }}
                    </p>
                    <div
                        v-if="lifecycle_issues.length"
                        role="alert"
                        class="mt-4 rounded-md border border-amber-400 bg-amber-50 p-3 text-sm text-amber-950"
                    >
                        <p class="font-semibold">
                            Manual lifecycle review required
                        </p>
                        <ul class="mt-2 list-disc pl-5">
                            <li
                                v-for="issue in lifecycle_issues"
                                :key="issue"
                                class="capitalize"
                            >
                                {{ label(issue) }}
                            </li>
                        </ul>
                        <p class="mt-2 text-xs">
                            Do not release stock or change payment state until
                            the discrepancy is reconciled.
                        </p>
                    </div>
                    <p
                        v-if="!reservations.length"
                        class="mt-3 text-sm text-muted-foreground"
                    >
                        No reservation rows found.
                    </p>
                    <div
                        v-for="(reservation, index) in reservations"
                        :key="index"
                        class="border-b py-2 text-sm last:border-0"
                    >
                        {{ reservation.item_name }} /
                        {{ reservation.component_name }} ·
                        {{ reservation.quantity }} ·
                        <span class="capitalize">{{
                            label(reservation.status)
                        }}</span>
                        <span
                            v-if="reservation.latest_check"
                            class="ml-2 font-medium capitalize"
                            >·
                            {{ label(reservation.latest_check.result) }}</span
                        >
                        <Button
                            v-if="can_verify_picks"
                            type="button"
                            variant="outline"
                            size="sm"
                            class="mt-2 block"
                            @click="openPick(reservation)"
                        >
                            {{
                                reservation.latest_check
                                    ? 'Recheck item'
                                    : 'Record pick check'
                            }}
                        </Button>
                        <form
                            v-if="
                                can_verify_picks &&
                                selectedReservation === reservation.id
                            "
                            class="mt-3 space-y-3 rounded-lg bg-muted/40 p-3"
                            @submit.prevent="recordPick"
                        >
                            <label class="block text-sm"
                                >Physically picked quantity
                                <Input
                                    v-model="pick.checked_quantity"
                                    required
                                    type="number"
                                    min="0.001"
                                    step="0.001"
                                    class="mt-1"
                                />
                            </label>
                            <template v-if="reservation.is_serialized">
                                <label class="block text-sm"
                                    >IMEI or serial on the physical device
                                    <Input
                                        v-model="pick.device_identifier"
                                        required
                                        autocomplete="off"
                                        maxlength="100"
                                        class="mt-1"
                                    />
                                </label>
                                <label class="block text-sm"
                                    >Observed device condition
                                    <select
                                        v-model="pick.observed_condition"
                                        required
                                        class="mt-1 w-full rounded-md border bg-background p-2"
                                    >
                                        <option value="">
                                            Choose condition
                                        </option>
                                        <option value="new">New</option>
                                        <option value="used">Used</option>
                                        <option value="refurbished">
                                            Refurbished
                                        </option>
                                    </select>
                                </label>
                            </template>
                            <label class="block text-sm"
                                >Internal discrepancy note
                                <Input
                                    v-model="pick.internal_note"
                                    maxlength="1000"
                                    class="mt-1"
                                />
                            </label>
                            <p class="text-xs text-muted-foreground">
                                A mismatch is recorded as a discrepancy and
                                prevents this order from becoming ready.
                                Identifiers are checked on the server and are
                                not saved in this form history.
                            </p>
                            <p
                                v-if="Object.values(pick.errors).length"
                                role="alert"
                                class="text-sm text-destructive"
                            >
                                {{ Object.values(pick.errors)[0] }}
                            </p>
                            <Button type="submit" :disabled="pick.processing">{{
                                pick.processing ? 'Recording…' : 'Record check'
                            }}</Button>
                        </form>
                    </div>
                    <div v-if="can_start_preparation" class="mt-4">
                        <Button
                            type="button"
                            :disabled="
                                preparation.processing ||
                                lifecycle_issues.length > 0
                            "
                            @click="startPreparation"
                        >
                            {{
                                preparation.processing
                                    ? 'Starting…'
                                    : 'Start preparation'
                            }}
                        </Button>
                        <p
                            v-if="Object.values(preparation.errors).length"
                            role="alert"
                            class="mt-2 text-sm text-destructive"
                        >
                            {{ Object.values(preparation.errors)[0] }}
                        </p>
                    </div>
                    <div v-if="can_mark_ready" class="mt-4">
                        <Button
                            type="button"
                            :disabled="
                                ready.processing ||
                                !allPicked ||
                                lifecycle_issues.length > 0
                            "
                            @click="markReady"
                        >
                            {{
                                ready.processing
                                    ? 'Saving…'
                                    : 'Mark ready for handover'
                            }}
                        </Button>
                        <p
                            v-if="!allPicked"
                            class="mt-2 text-xs text-muted-foreground"
                        >
                            Every component needs a matching physical pick
                            check.
                        </p>
                        <p
                            v-if="Object.values(ready.errors).length"
                            role="alert"
                            class="mt-2 text-sm text-destructive"
                        >
                            {{ Object.values(ready.errors)[0] }}
                        </p>
                    </div>
                </section>
                <section v-if="can_confirm" class="rounded-xl border p-5">
                    <h2 class="text-lg font-semibold">
                        COD phone confirmation
                    </h2>
                    <p class="mt-2 text-sm text-muted-foreground">
                        Call {{ order.contact?.phone ?? 'the customer' }} and
                        verify the exact items, delivery address and
                        {{ order.currency }} {{ order.total }} COD amount.
                    </p>
                    <form class="mt-4 space-y-3" @submit.prevent="recordCall">
                        <label class="block text-sm"
                            >Call outcome<select
                                v-model="call.outcome"
                                required
                                class="mt-1 w-full rounded-md border bg-background p-2"
                            >
                                <option value="">Choose outcome</option>
                                <option value="answered_confirmed">
                                    Answered and confirmed
                                </option>
                                <option value="no_answer">No answer</option>
                                <option value="customer_declined">
                                    Customer declined
                                </option>
                                <option value="wrong_number">
                                    Wrong number
                                </option>
                                <option value="request_callback">
                                    Request callback
                                </option>
                            </select></label
                        ><label class="block text-sm"
                            >Internal note<Input
                                v-model="call.internal_note"
                                class="mt-1"
                                maxlength="1000"
                                :required="
                                    call.outcome === 'customer_declined'
                                "
                        /></label>
                        <p
                            v-if="callError"
                            role="alert"
                            class="text-sm text-destructive"
                        >
                            {{ callError }}
                        </p>
                        <Button type="submit" :disabled="call.processing">{{
                            call.processing ? 'Saving…' : 'Record call outcome'
                        }}</Button>
                    </form>
                    <div
                        v-if="
                            call_attempts[0]?.outcome === 'answered_confirmed'
                        "
                        class="mt-5 border-t pt-4"
                    >
                        <p class="mb-3 text-sm">
                            Latest call confirms the customer accepted these
                            terms.
                        </p>
                        <Button
                            :disabled="confirmation.processing"
                            @click="confirmCod"
                            >{{
                                confirmation.processing
                                    ? 'Confirming…'
                                    : 'Confirm COD order'
                            }}</Button
                        >
                        <p
                            v-if="confirmationError"
                            role="alert"
                            class="mt-2 text-sm text-destructive"
                        >
                            {{ confirmationError }}
                        </p>
                    </div>
                </section>
                <section
                    v-if="can_cancel"
                    class="rounded-xl border border-destructive/40 p-5"
                >
                    <h2 class="text-lg font-semibold">Customer declined</h2>
                    <p class="mt-2 text-sm text-muted-foreground">
                        The latest call outcome records a decline. Cancellation
                        releases all active reservations atomically.
                    </p>
                    <Button
                        variant="destructive"
                        class="mt-4"
                        :disabled="cancellation.processing"
                        @click="cancelDeclined"
                        >{{
                            cancellation.processing
                                ? 'Cancelling…'
                                : 'Cancel declined order'
                        }}</Button
                    >
                    <p
                        v-if="cancellationError"
                        role="alert"
                        class="mt-2 text-sm text-destructive"
                    >
                        {{ cancellationError }}
                    </p>
                </section>
            </aside>
        </div>
    </div>
</template>
