<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import { RefreshCw } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import ShiftSummaryPanel from '@/components/shifts/ShiftSummary.vue';
import ShiftPayments from '@/components/shifts/ShiftPayments.vue';
import { store, movements, close } from '@/routes/pos/shifts';
import { report } from '@/routes/shifts';
import { cashCents, signedCents, shiftMoney } from '@/lib/shift';
import type { PosDrawer } from '@/types/shift';

type Mode = 'open' | 'movement' | 'reconciliation' | 'close';
const props = defineProps<{
    drawer: PosDrawer;
    locationId: number;
    resolvedLocationId: number;
    locationName: string;
    currency: string;
    checkoutBusy: boolean;
}>();
const emit = defineEmits<{
    busy: [value: boolean];
    switchLocation: [id: number];
}>();
const modal = ref<Mode | null>(null);
const refreshing = ref(false);
const refreshError = ref('');
const lastClosed = ref<{ id: number; reference: string } | null>(null);
const opening = useForm({
    operation_key: crypto.randomUUID(),
    location_id: props.locationId,
    opening_cash: '',
    opening_note: '',
});
const movement = useForm({
    operation_key: crypto.randomUUID(),
    location_id: props.locationId,
    type: 'cash_in',
    amount: '',
    reason: '',
    supporting_reference: '',
});
const closing = useForm({
    location_id: props.locationId,
    actual_cash: '',
    variance_reason: '',
    closing_note: '',
});
const processing = computed(
    () => opening.processing || movement.processing || closing.processing,
);
const busy = computed(
    () => processing.value || refreshing.value || props.checkoutBusy,
);
const ready = computed(
    () =>
        props.drawer.location_id === props.locationId &&
        props.resolvedLocationId === props.locationId,
);
const active = computed(() => (ready.value ? props.drawer.shift : null));
const labels: Record<string, string> = {
    cash_in: 'Cash In',
    cash_out: 'Cash Out',
    expense: 'Add Expense',
    drop: 'Cash Drop',
};
const variance = computed(() => {
    if (!props.drawer.summary) {
        return null;
    }
    const actual = cashCents(closing.actual_cash);
    const expected = props.drawer.summary.expected_cash;
    const expectedCents = cashCents(expected.replace('-', ''));
    return actual === null || expectedCents === null
        ? null
        : signedCents(
              actual -
                  (expected.startsWith('-') ? -expectedCents : expectedCents),
          );
});
watch(
    () => processing.value || refreshing.value,
    (value) => emit('busy', value),
    { flush: 'sync' },
);
function suggestOpening(): void {
    if (
        ready.value &&
        !active.value &&
        !props.drawer.other_location &&
        props.drawer.permissions.open &&
        !processing.value
    ) {
        modal.value = 'open';
    }
}
onMounted(suggestOpening);
watch(
    () => props.locationId,
    () => {
        modal.value = null;
        opening.reset();
        opening.clearErrors();
        movement.reset();
        movement.clearErrors();
        closing.reset();
        closing.clearErrors();
        refreshError.value = '';
    },
);
watch(
    [
        () => props.drawer.location_id,
        () => props.drawer.shift?.id,
        () => props.resolvedLocationId,
    ],
    () => {
        if (!processing.value) {
            modal.value = null;
            suggestOpening();
        }
    },
);
function changeDialog(open: boolean): void {
    if (!open && !processing.value) {
        modal.value = null;
    }
}
function refresh(mode?: 'reconciliation' | 'close'): void {
    if (busy.value || !ready.value) {
        return;
    }
    refreshError.value = '';
    refreshing.value = true;
    if (mode && active.value) {
        modal.value = mode;
    }
    router.reload({
        only: ['drawer'],
        data: { location_id: props.locationId },
        onCancel: () => {
            refreshError.value =
                'Drawer refresh was interrupted. Close this panel and try again.';
        },
        onError: () => {
            refreshError.value = 'Unable to refresh the drawer. Try again.';
        },
        onFinish: () => {
            refreshing.value = false;
        },
    });
}
function openShift(): void {
    if (processing.value || !ready.value) {
        return;
    }
    opening.location_id = props.locationId;
    opening.post(store().url, {
        only: ['drawer'],
        preserveScroll: true,
        onSuccess: () => {
            opening.reset();
            opening.operation_key = crypto.randomUUID();
            modal.value = null;
        },
    });
}
function beginMovement(type: string): void {
    if (busy.value || !active.value) {
        return;
    }
    movement.reset();
    movement.clearErrors();
    movement.operation_key = crypto.randomUUID();
    movement.type = type;
    modal.value = 'movement';
}
function recordMovement(): void {
    if (processing.value || !active.value) {
        return;
    }
    movement.location_id = props.locationId;
    movement.post(movements(active.value.id).url, {
        only: ['drawer'],
        preserveScroll: true,
        onSuccess: () => {
            modal.value = null;
            movement.reset();
            movement.operation_key = crypto.randomUUID();
        },
    });
}
function closeShift(): void {
    if (
        processing.value ||
        refreshing.value ||
        refreshError.value ||
        !active.value
    ) {
        return;
    }
    closing.location_id = props.locationId;
    closing.post(close(active.value.id).url, {
        only: ['drawer'],
        preserveScroll: true,
        onFlash: (flash) => {
            if (flash.closedShift) {
                lastClosed.value = flash.closedShift as {
                    id: number;
                    reference: string;
                };
            }
        },
        onSuccess: () => {
            modal.value = null;
            closing.reset();
        },
    });
}
</script>

<template>
    <div class="flex shrink-0 items-center gap-3 overflow-x-auto">
        <section
            class="min-w-max flex-1 py-1"
            aria-label="Current Shift / Cash Drawer"
        >
            <div
                class="flex items-center justify-between gap-3 whitespace-nowrap"
            >
                <div class="flex min-w-0 items-center gap-2.5">
                    <div class="flex items-center gap-3">
                        <p
                            v-if="!ready"
                            class="text-xs text-muted-foreground"
                            role="status"
                        >
                            Checking selected location…
                        </p>
                        <p
                            v-else-if="active && drawer.summary"
                            class="text-xs text-muted-foreground"
                        >
                            <span
                                class="rounded-full bg-primary/10 px-2 py-1 font-medium text-primary"
                                :title="`${active.reference} · ${locationName}`"
                                >Shift open</span
                            >
                        </p>
                        <p v-else class="text-xs text-muted-foreground">
                            Open a shift for {{ locationName }} to enable
                            checkout.
                        </p>
                    </div>
                </div>
                <div class="flex shrink-0 items-center gap-1.5">
                    <template v-if="active">
                        <template v-if="drawer.permissions.movements">
                            <Button
                                v-for="(label, type) in labels"
                                :key="type"
                                type="button"
                                size="sm"
                                variant="outline"
                                :disabled="busy"
                                @click="beginMovement(type)"
                                >{{ label }}</Button
                            >
                        </template>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            :disabled="busy"
                            @click="refresh('reconciliation')"
                            >Reconciliation</Button
                        >
                    </template>
                    <Button
                        v-else-if="
                            ready &&
                            !drawer.other_location &&
                            drawer.permissions.open
                        "
                        type="button"
                        size="sm"
                        :disabled="processing"
                        @click="modal = 'open'"
                        >Open Shift</Button
                    >
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        class="size-8"
                        :disabled="busy || !ready"
                        aria-label="Refresh shift balance"
                        @click="refresh()"
                        ><RefreshCw
                            class="size-3.5"
                            :class="refreshing ? 'animate-spin' : ''"
                    /></Button>
                </div>
            </div>
            <p
                v-if="ready && drawer.other_location"
                class="mt-2 text-xs text-amber-700 dark:text-amber-300"
            >
                Your active shift is at {{ drawer.other_location.name }}. Close
                it before opening another shift.
                <button
                    type="button"
                    class="font-semibold underline underline-offset-2"
                    :disabled="busy"
                    @click="emit('switchLocation', drawer.other_location.id)"
                >
                    Switch to {{ drawer.other_location.name }}
                </button>
            </p>
            <p
                v-else-if="ready && !active && !drawer.permissions.open"
                class="mt-2 text-xs text-destructive"
            >
                You need shift access and opening permission. Ask an authorized
                manager.
            </p>
            <p
                v-if="lastClosed && !active"
                class="mt-2 text-xs text-muted-foreground"
            >
                Shift closed. Open a new shift before the next sale.
                <Link
                    :href="report(lastClosed.id)"
                    class="font-medium text-primary underline"
                    >View closing report</Link
                >
            </p>
            <p
                v-if="refreshError"
                class="mt-2 text-xs text-destructive"
                role="alert"
            >
                {{ refreshError }}
            </p>

            <Dialog :open="modal !== null" @update:open="changeDialog">
                <DialogContent
                    class="max-h-[90svh] overflow-y-auto sm:max-w-xl"
                    @escape-key-down="processing && $event.preventDefault()"
                    @pointer-down-outside="
                        processing && $event.preventDefault()
                    "
                >
                    <DialogHeader>
                        <DialogTitle>{{
                            modal === 'open'
                                ? 'Open Cashier Shift'
                                : modal === 'movement'
                                  ? labels[movement.type]
                                  : modal === 'close'
                                    ? 'Count cash & close shift'
                                    : 'Drawer Reconciliation'
                        }}</DialogTitle>
                        <DialogDescription
                            >{{ locationName }} ·
                            {{
                                active?.reference ??
                                'Confirm your opening cash to start selling.'
                            }}</DialogDescription
                        >
                    </DialogHeader>
                    <p
                        v-if="refreshing"
                        role="status"
                        class="text-sm text-muted-foreground"
                    >
                        Refreshing drawer totals…
                    </p>
                    <p
                        v-if="refreshError"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ refreshError }}
                    </p>

                    <form
                        v-if="modal === 'open'"
                        class="space-y-4"
                        @submit.prevent="openShift"
                    >
                        <div class="grid gap-2">
                            <Label for="pos-opening-cash"
                                >Opening cash ({{ currency }})</Label
                            ><Input
                                id="pos-opening-cash"
                                v-model="opening.opening_cash"
                                type="text"
                                inputmode="decimal"
                                autocomplete="off"
                                placeholder="0.00"
                                required
                                class="h-12 text-lg font-semibold tabular-nums"
                                aria-describedby="opening-help"
                            />
                            <p
                                id="opening-help"
                                class="text-xs text-muted-foreground"
                            >
                                Enter the physical float. Zero is allowed when
                                explicitly entered.
                            </p>
                        </div>
                        <div class="grid gap-2">
                            <Label for="pos-opening-note"
                                >Opening note (optional)</Label
                            ><textarea
                                id="pos-opening-note"
                                v-model="opening.opening_note"
                                rows="2"
                                maxlength="2000"
                                class="rounded-lg border bg-background p-3 text-sm"
                            />
                        </div>
                        <InputError
                            v-for="(error, key) in opening.errors"
                            :key="key"
                            :message="error"
                        />
                        <Button
                            type="submit"
                            class="w-full"
                            :disabled="opening.processing"
                            >{{
                                opening.processing
                                    ? 'Opening…'
                                    : 'Confirm opening cash & open shift'
                            }}</Button
                        >
                    </form>

                    <form
                        v-if="modal === 'movement' && active"
                        class="space-y-4"
                        @submit.prevent="recordMovement"
                    >
                        <p
                            v-if="movement.type === 'expense'"
                            class="rounded-lg bg-muted p-3 text-sm"
                        >
                            Payment source: <strong>Active cash drawer</strong>.
                            This expense is recorded once as a cash movement.
                        </p>
                        <div class="grid gap-2">
                            <Label for="pos-movement-amount"
                                >Amount ({{ currency }})</Label
                            ><Input
                                id="pos-movement-amount"
                                v-model="movement.amount"
                                type="text"
                                inputmode="decimal"
                                autocomplete="off"
                                required
                                placeholder="0.00"
                                class="h-11"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="pos-movement-reason">{{
                                movement.type === 'cash_in'
                                    ? 'Reason / source'
                                    : movement.type === 'drop'
                                      ? 'Reason / destination'
                                      : movement.type === 'expense'
                                        ? 'Description / reason'
                                        : 'Reason'
                            }}</Label
                            ><textarea
                                id="pos-movement-reason"
                                v-model="movement.reason"
                                required
                                minlength="5"
                                maxlength="500"
                                rows="2"
                                class="rounded-lg border bg-background p-3 text-sm"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="pos-movement-reference"
                                >Reference / supporting note (optional)</Label
                            ><Input
                                id="pos-movement-reference"
                                v-model="movement.supporting_reference"
                                maxlength="150"
                            />
                        </div>
                        <InputError
                            v-for="(error, key) in movement.errors"
                            :key="key"
                            :message="error"
                        />
                        <Button
                            type="submit"
                            class="w-full"
                            :disabled="movement.processing"
                            >{{
                                movement.processing
                                    ? 'Saving…'
                                    : 'Confirm & record movement'
                            }}</Button
                        >
                    </form>

                    <template
                        v-if="
                            (modal === 'reconciliation' || modal === 'close') &&
                            drawer.summary &&
                            active
                        "
                    >
                        <ShiftSummaryPanel
                            :summary="drawer.summary"
                            :currency="currency"
                        />
                        <ShiftPayments
                            v-if="modal === 'reconciliation'"
                            :summary="drawer.summary"
                            :currency="currency"
                        />
                        <form
                            v-if="modal === 'close'"
                            class="space-y-4"
                            @submit.prevent="closeShift"
                        >
                            <div class="grid gap-2">
                                <Label for="pos-actual-cash"
                                    >Actual physical cash counted ({{
                                        currency
                                    }})</Label
                                ><Input
                                    id="pos-actual-cash"
                                    v-model="closing.actual_cash"
                                    type="text"
                                    inputmode="decimal"
                                    autocomplete="off"
                                    placeholder="0.00"
                                    required
                                    class="h-12 text-lg font-semibold tabular-nums"
                                />
                            </div>
                            <div
                                v-if="variance !== null"
                                class="rounded-xl border p-3"
                                :class="
                                    variance === '0.00'
                                        ? 'border-success/30 bg-success/5'
                                        : 'border-destructive/30 bg-destructive/5'
                                "
                            >
                                <p class="text-sm font-medium">
                                    {{
                                        variance === '0.00'
                                            ? 'Balanced'
                                            : variance.startsWith('-')
                                              ? 'Cash shortage'
                                              : 'Cash surplus'
                                    }}
                                </p>
                                <p
                                    class="mt-1 text-xl font-semibold tabular-nums"
                                >
                                    {{ shiftMoney(variance, currency) }}
                                </p>
                            </div>
                            <div class="grid gap-2">
                                <Label for="pos-variance-reason"
                                    >Variance explanation
                                    {{
                                        variance !== null && variance !== '0.00'
                                            ? '(required)'
                                            : '(if applicable)'
                                    }}</Label
                                ><textarea
                                    id="pos-variance-reason"
                                    v-model="closing.variance_reason"
                                    :required="
                                        variance !== null && variance !== '0.00'
                                    "
                                    maxlength="2000"
                                    rows="2"
                                    class="rounded-lg border bg-background p-3 text-sm"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="pos-closing-note"
                                    >Closing note (optional)</Label
                                ><textarea
                                    id="pos-closing-note"
                                    v-model="closing.closing_note"
                                    maxlength="2000"
                                    rows="2"
                                    class="rounded-lg border bg-background p-3 text-sm"
                                />
                            </div>
                            <p class="text-xs leading-5 text-muted-foreground">
                                Confirm your physical count. Closing permanently
                                locks this shift and disables checkout until a
                                new shift is opened. Variances require another
                                authorized manager’s review.
                            </p>
                            <InputError
                                v-for="(error, key) in closing.errors"
                                :key="key"
                                :message="error"
                            />
                            <Button
                                type="submit"
                                class="w-full"
                                :disabled="
                                    closing.processing ||
                                    refreshing ||
                                    !!refreshError
                                "
                                >{{
                                    closing.processing
                                        ? 'Closing…'
                                        : 'Confirm count & close shift'
                                }}</Button
                            >
                        </form>
                    </template>
                </DialogContent>
            </Dialog>
        </section>
        <div class="ml-auto flex shrink-0 items-center gap-2 py-1">
            <Button
                v-if="active && drawer.permissions.close"
                type="button"
                size="sm"
                :disabled="busy"
                @click="refresh('close')"
                >Close Shift</Button
            >
        </div>
    </div>
</template>
