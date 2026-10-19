<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import ShiftLinks from '@/components/shifts/ShiftLinks.vue';
import ShiftPagination from '@/components/shifts/ShiftPagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { storeMovement, reverse } from '@/routes/shifts';
import { shiftDate, shiftMoney } from '@/lib/shift';
import type { Auth } from '@/types';
import type { ShiftDetails } from '@/types/shift';
const props = defineProps<ShiftDetails>();
const page = usePage<{ auth: Auth }>();
const form = useForm({
    operation_key: crypto.randomUUID(),
    type: 'cash_in',
    amount: '',
    reason: '',
    supporting_reference: '',
});
const reversal = useForm({ reason: '' });
const reversingId = ref<number | null>(null);
function submit(): void {
    form.post(storeMovement(props.shift.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            form.operation_key = crypto.randomUUID();
        },
    });
}
function submitReversal(): void {
    if (reversingId.value === null) {
        return;
    }
    reversal.post(
        reverse({ shift: props.shift.id, movement: reversingId.value }).url,
        {
            preserveScroll: true,
            onSuccess: () => {
                reversingId.value = null;
                reversal.reset();
            },
        },
    );
}
</script>
<template>
    <section class="page-shell space-y-6">
        <Head title="Cash movements" /><Heading
            title="Cash movements"
            :description="`${shift.reference} · ${shift.location.name}`"
        />
        <ShiftLinks :shift="shift" :can-manage="canManage" />
        <form
            v-if="
                canManage &&
                page.props.auth.permissions.includes('shifts.movements')
            "
            class="surface-panel grid gap-4 p-5 sm:grid-cols-2"
            @submit.prevent="submit"
        >
            <div class="grid gap-2">
                <Label for="movement-type">Movement type</Label
                ><select
                    id="movement-type"
                    v-model="form.type"
                    class="h-10 rounded-lg border bg-background px-3"
                >
                    <option value="cash_in">Cash in · float top-up</option>
                    <option value="cash_out">Cash out</option>
                    <option value="expense">Cash expense</option>
                    <option value="drop">
                        Cash drop · safe / owner
                    </option></select
                ><InputError :message="form.errors.type" />
            </div>
            <div class="grid gap-2">
                <Label for="amount">Amount ({{ currency }})</Label
                ><Input
                    id="amount"
                    v-model="form.amount"
                    type="number"
                    min="0.01"
                    max="999999999999.99"
                    step="0.01"
                    required
                /><InputError :message="form.errors.amount" />
            </div>
            <div class="grid gap-2 sm:col-span-2">
                <Label for="reason">Reason / destination</Label
                ><Input
                    id="reason"
                    v-model="form.reason"
                    minlength="5"
                    maxlength="500"
                    required
                    placeholder="Describe the source, expense, or cash drop recipient"
                /><InputError :message="form.errors.reason" />
            </div>
            <div class="grid gap-2 sm:col-span-2">
                <Label for="reference">Supporting reference (optional)</Label
                ><Input
                    id="reference"
                    v-model="form.supporting_reference"
                    maxlength="150"
                    placeholder="Receipt number or supporting note"
                /><InputError :message="form.errors.supporting_reference" />
            </div>
            <InputError
                :message="page.props.errors.shift || form.errors.operation_key"
            />
            <div class="sm:col-span-2">
                <Button :disabled="form.processing">{{
                    form.processing ? 'Recording…' : 'Record movement'
                }}</Button>
            </div>
        </form>
        <p v-else class="surface-panel p-4 text-sm text-muted-foreground">
            {{
                shift.status === 'open'
                    ? 'Only the shift cashier with cash movement permission can record movements.'
                    : 'This shift is closed. Its cash movements are read-only.'
            }}
        </p>
        <form
            v-if="reversingId !== null && canCorrect"
            class="surface-panel space-y-3 border-destructive/30 p-4"
            @submit.prevent="submitReversal"
        >
            <Label for="reversal"
                >Reason for reversing movement #{{ reversingId }}</Label
            ><Input
                id="reversal"
                v-model="reversal.reason"
                minlength="5"
                maxlength="500"
                required
            /><InputError
                :message="reversal.errors.reason || page.props.errors.shift"
            />
            <div class="flex gap-2">
                <Button variant="destructive" :disabled="reversal.processing"
                    >Confirm reversal</Button
                ><Button
                    type="button"
                    variant="outline"
                    @click="reversingId = null"
                    >Cancel</Button
                >
            </div>
        </form>
        <section class="table-panel">
            <div class="overflow-x-auto">
                <table class="data-table min-w-[850px]">
                    <thead>
                        <tr>
                            <th>Reference / time</th>
                            <th>Type</th>
                            <th>Reason / reference</th>
                            <th>Recorded by</th>
                            <th class="text-right">Amount</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="movement in movements.data"
                            :key="movement.id"
                        >
                            <td class="text-xs">
                                {{ movement.reference
                                }}<span class="block text-muted-foreground">{{
                                    shiftDate(movement.created_at, timezone)
                                }}</span>
                            </td>
                            <td class="capitalize">
                                {{ movement.reverses_id ? 'Reversal · ' : ''
                                }}{{ movement.type.replaceAll('_', ' ')
                                }}<span
                                    v-if="movement.reverses_id"
                                    class="block text-xs"
                                    >Reverses #{{ movement.reverses_id }}</span
                                >
                            </td>
                            <td>
                                {{ movement.reason
                                }}<span
                                    class="block text-xs text-muted-foreground"
                                    >{{ movement.supporting_reference }}</span
                                >
                            </td>
                            <td>{{ movement.creator.name }}</td>
                            <td class="text-right tabular-nums">
                                {{ shiftMoney(movement.amount, currency) }}
                            </td>
                            <td>
                                <Button
                                    v-if="
                                        canCorrect &&
                                        !movement.reverses_id &&
                                        !movement.reversal_exists
                                    "
                                    size="sm"
                                    variant="outline"
                                    @click="
                                        reversingId = movement.id;
                                        reversal.clearErrors();
                                    "
                                    >Reverse</Button
                                >
                                <span
                                    v-if="movement.reversal_exists"
                                    class="text-xs text-muted-foreground"
                                    >Reversed</span
                                >
                            </td>
                        </tr>
                        <tr v-if="!movements.data.length">
                            <td
                                colspan="6"
                                class="py-8 text-center text-muted-foreground"
                            >
                                No cash movements recorded.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <ShiftPagination :links="movements.links" />
        </section>
    </section>
</template>
