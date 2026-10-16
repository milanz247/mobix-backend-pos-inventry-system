<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import { useConfirmationDialog } from '@/composables/useConfirmationDialog';
import sales, { index, show } from '@/routes/sales';

type SaleLine = {
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
    total: string;
    returnable_quantity: string;
    returned_value: string;
    has_serialized_components: boolean;
};
type SaleData = {
    id: number;
    sale_number: string;
    location: { id: number; name: string };
    lines: SaleLine[];
};
type Option = { id: number; name: string; code: string };
type Method = { value: string; label: string };
const props = defineProps<{
    sale: SaleData;
    locations: Option[];
    currency: string;
    paymentMethods: Method[];
    canRefund: boolean;
    remainingPaid: string;
}>();
const confirmation = useConfirmationDialog();
const form = useForm({
    operation_key: crypto.randomUUID(),
    location_id: props.locations[0]?.id ?? '',
    reason: 'Customer return',
    notes: '',
    lines: props.sale.lines.map((line) => ({
        sale_line_id: line.id,
        quantity: '0.000',
        restock: true,
    })),
    refund_amount: '0.00',
    refund_method: 'cash',
    refund_reference: '',
});
const selectedLines = computed(() =>
    form.lines.filter((line) => quantityMilli(line.quantity) > 0n),
);
function minorUnits(value: string): bigint {
    const match = value.match(/^(\d+)(?:\.(\d{0,2}))?$/);
    if (!match) return 0n;
    return BigInt(match[1]) * 100n + BigInt((match[2] ?? '').padEnd(2, '0'));
}
function quantityMilli(value: string): bigint {
    const match = value.match(/^(\d+)(?:\.(\d{0,3}))?$/);
    if (!match) return 0n;
    return BigInt(match[1]) * 1000n + BigInt((match[2] ?? '').padEnd(3, '0'));
}
function formatMinor(value: bigint): string {
    return `${value / 100n}.${(value % 100n).toString().padStart(2, '0')}`;
}
function quantityString(value: bigint): string {
    return `${value / 1000n}.${(value % 1000n).toString().padStart(3, '0')}`;
}
function money(value: string | bigint): string {
    const text = typeof value === 'bigint' ? formatMinor(value) : value;
    const [whole = '0', fraction = '00'] = text.split('.', 2);
    return `${props.currency} ${BigInt(whole || '0').toLocaleString()}.${fraction.padEnd(2, '0').slice(0, 2)}`;
}
function returnableValue(line: SaleLine, quantity: string): bigint {
    const requested = quantityMilli(quantity);
    const remaining = quantityMilli(line.returnable_quantity);
    if (requested === 0n || remaining === 0n) return 0n;
    const remainingValue =
        minorUnits(line.total) - minorUnits(line.returned_value);
    if (requested === remaining) return remainingValue;
    return (
        (minorUnits(line.total) * requested +
            quantityMilli(line.quantity) / 2n) /
        quantityMilli(line.quantity)
    );
}
const returnTotal = computed(() =>
    selectedLines.value.reduce((sum, selected) => {
        const line = props.sale.lines.find(
            (item) => item.id === selected.sale_line_id,
        );
        return sum + (line ? returnableValue(line, selected.quantity) : 0n);
    }, 0n),
);
const expectedRefund = computed(() => {
    const remainingPaid = minorUnits(props.remainingPaid);
    return returnTotal.value < remainingPaid
        ? returnTotal.value
        : remainingPaid;
});
watch(
    expectedRefund,
    (value) => {
        form.refund_amount = formatMinor(value);
    },
    { immediate: true },
);
function lineInput(line: SaleLine): {
    sale_line_id: number;
    quantity: string;
    restock: boolean;
} {
    return (
        form.lines.find((item) => item.sale_line_id === line.id) ?? {
            sale_line_id: line.id,
            quantity: '0.000',
            restock: true,
        }
    );
}
function submitReturn(): void {
    form.lines = selectedLines.value;
    confirmation.request({
        title: `Process return for ${props.sale.sale_number}?`,
        description:
            'This will create a permanent return record, update location stock, and record any refund atomically.',
        confirmLabel: 'Process return',
        onConfirm: () =>
            form.post(sales.returns.store(props.sale.id).url, {
                preserveScroll: true,
            }),
    });
}
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Sales history', href: index() },
            { title: 'Process return' },
        ],
    },
});
</script>

<template>
    <Head :title="`Return ${sale.sale_number}`" />
    <section class="page-shell space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                :title="`Return · ${sale.sale_number}`"
                description="Select sold items and record the refund. Returned quantities are restocked transactionally at the original location."
            /><Button as-child variant="outline"
                ><Link :href="show(sale.id)"
                    ><ArrowLeft /> Back to sale</Link
                ></Button
            >
        </div>
        <form
            class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_340px]"
            @submit.prevent="submitReturn"
        >
            <div class="table-panel overflow-x-auto">
                <table class="data-table min-w-[780px]">
                    <thead>
                        <tr>
                            <th>Sold item</th>
                            <th>IMEI / tracking</th>
                            <th>Sold</th>
                            <th>Already returned</th>
                            <th>Return quantity</th>
                            <th>Return value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="line in sale.lines" :key="line.id">
                            <td>
                                <div class="font-medium">
                                    {{ line.product_name }} ·
                                    {{ line.variant_name }}
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    {{ line.sku
                                    }}<span v-if="line.is_combo">
                                        · bundle components will also be
                                        returned</span
                                    >
                                </div>
                            </td>
                            <td class="text-xs">
                                {{
                                    line.imei ||
                                    (line.tracking_mode === 'serialized'
                                        ? 'IMEI tracked'
                                        : 'Quantity tracked')
                                }}
                                <p
                                    v-if="line.has_serialized_components"
                                    class="mt-1 text-amber-700"
                                >
                                    Must return the full bundle
                                </p>
                            </td>
                            <td>{{ line.quantity }}</td>
                            <td>
                                {{
                                    quantityString(
                                        quantityMilli(line.quantity) -
                                            quantityMilli(
                                                line.returnable_quantity,
                                            ),
                                    )
                                }}
                            </td>
                            <td>
                                <div
                                    v-if="
                                        quantityMilli(
                                            line.returnable_quantity,
                                        ) > 0n
                                    "
                                    class="space-y-2"
                                >
                                    <Input
                                        v-model="lineInput(line).quantity"
                                        inputmode="decimal"
                                        :aria-label="`Return quantity for ${line.product_name}`"
                                        :max="line.returnable_quantity"
                                    /><label
                                        class="flex items-center gap-2 text-xs"
                                        ><input
                                            v-model="lineInput(line).restock"
                                            type="checkbox"
                                            class="rounded border-input"
                                        />
                                        Return to saleable stock</label
                                    ><span
                                        v-if="!lineInput(line).restock"
                                        class="block text-amber-700"
                                        >Not added to available stock</span
                                    >
                                </div>
                                <span
                                    v-else
                                    class="text-sm text-muted-foreground"
                                    >Fully returned</span
                                ><InputError
                                    :message="
                                        form.errors[
                                            `lines.${sale.lines.indexOf(line)}.quantity`
                                        ]
                                    "
                                />
                            </td>
                            <td class="tabular-nums">
                                {{
                                    money(
                                        returnableValue(
                                            line,
                                            lineInput(line).quantity,
                                        ),
                                    )
                                }}
                            </td>
                        </tr>
                        <tr v-if="sale.lines.length === 0">
                            <td
                                colspan="6"
                                class="py-10 text-center text-muted-foreground"
                            >
                                There are no returnable sale lines.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <aside class="surface-panel h-fit space-y-4 p-4">
                <div>
                    <Label for="return-location">Return location</Label
                    ><select
                        id="return-location"
                        v-model="form.location_id"
                        class="mt-1 h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                        disabled
                    >
                        <option
                            v-for="location in locations"
                            :key="location.id"
                            :value="location.id"
                        >
                            {{ location.name }} · {{ location.code }}
                        </option></select
                    ><InputError :message="form.errors.location_id" />
                </div>
                <div>
                    <Label for="return-reason">Reason</Label
                    ><select
                        id="return-reason"
                        v-model="form.reason"
                        class="mt-1 h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                    >
                        <option>Customer return</option>
                        <option>Faulty item</option>
                        <option>Incorrect item</option>
                        <option>Other</option></select
                    ><InputError :message="form.errors.reason" />
                </div>
                <div>
                    <Label for="return-notes">Notes</Label
                    ><textarea
                        id="return-notes"
                        v-model="form.notes"
                        class="mt-1 min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    />
                </div>
                <div class="space-y-2 border-t pt-3">
                    <div class="flex justify-between text-sm">
                        <span>Return total</span
                        ><span class="font-semibold">{{
                            money(returnTotal)
                        }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span>Refundable paid amount</span
                        ><span>{{ money(expectedRefund) }}</span>
                    </div>
                    <template v-if="expectedRefund > 0n"
                        ><div
                            v-if="!canRefund"
                            class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900"
                        >
                            Refund permission is required to process this
                            return.
                        </div>
                        <div v-else>
                            <Label for="refund-method">Refund method</Label
                            ><select
                                id="refund-method"
                                v-model="form.refund_method"
                                class="mt-1 h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option
                                    v-for="method in paymentMethods"
                                    :key="method.value"
                                    :value="method.value"
                                >
                                    {{ method.label }}
                                </option></select
                            ><Input
                                v-model="form.refund_amount"
                                class="mt-2"
                                readonly
                                aria-label="Refund amount"
                            /><Input
                                v-if="form.refund_method !== 'cash'"
                                v-model="form.refund_reference"
                                class="mt-2"
                                placeholder="Refund reference (optional)"
                            /><InputError
                                :message="form.errors.refund_amount"
                            /></div
                    ></template>
                </div>
                <InputError
                    v-for="(message, field) in form.errors"
                    :key="field"
                    :message="message"
                />
                <p class="text-xs text-muted-foreground">
                    Inspect returned items before restocking. The current
                    inventory model tracks saleable quantities; damaged-unit
                    quarantine is not configured.
                </p>
                <Button
                    class="w-full"
                    :disabled="
                        form.processing ||
                        selectedLines.length === 0 ||
                        (expectedRefund > 0n && !canRefund)
                    "
                    >{{
                        form.processing ? 'Processing…' : 'Confirm return'
                    }}</Button
                >
            </aside>
        </form>
    </section>
    <ConfirmActionDialog
        :open="confirmation.isOpen.value"
        :title="confirmation.title.value"
        :description="confirmation.description.value"
        :confirm-label="confirmation.confirmLabel.value"
        :destructive="confirmation.destructive.value"
        @update:open="confirmation.setOpen"
        @confirm="confirmation.confirm"
    />
</template>
