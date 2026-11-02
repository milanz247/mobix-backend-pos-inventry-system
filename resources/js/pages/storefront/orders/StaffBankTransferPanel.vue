<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Decision = {
    decision: string;
    received_amount: string;
    actor_name_snapshot: string;
    internal_note: string | null;
    reviewed_at: string;
};
export type PaymentAttempt = {
    id: number;
    transfer_reference: string;
    submitted_amount: string | null;
    transfer_date: string | null;
    status: string;
    submitted_at: string;
    decision: Decision | null;
};
const props = defineProps<{
    reference: string;
    currency: string;
    expectedAmount: string;
    remainingAmount: string | null;
    paymentStatus: string;
    attempts: PaymentAttempt[];
    canView: boolean;
    canVerify: boolean;
}>();
const form = useForm({ decision: '', received_amount: '', internal_note: '' });
const error = computed(() => Object.values(form.errors)[0] ?? '');
const label = (value: string) => value.replaceAll('_', ' ');
function submit(attemptId: number) {
    if (form.processing) return;
    form.post(
        `/storefront/orders/${props.reference}/bank-proofs/${attemptId}/review`,
        {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        },
    );
}
</script>

<template>
    <section class="space-y-4 rounded-xl border p-5">
        <div>
            <h2 class="text-lg font-semibold">Bank transfer review</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Accepted total: {{ currency }} {{ expectedAmount }}. Remaining
                to verify: {{ currency }}
                {{ remainingAmount ?? expectedAmount }}. Current state:
                {{ label(paymentStatus) }}. Proof is not payment confirmation.
            </p>
        </div>
        <p v-if="!canView" class="text-sm text-muted-foreground">
            Payment evidence requires the proof-view permission.
        </p>
        <p v-else-if="!attempts.length" class="text-sm text-muted-foreground">
            No transfer proof has been submitted.
        </p>
        <div
            v-for="attempt in attempts"
            :key="attempt.id"
            class="space-y-3 border-t pt-4 text-sm"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="font-semibold capitalize">
                        {{ label(attempt.status) }}
                    </p>
                    <p class="mt-1">
                        Reference: {{ attempt.transfer_reference }}
                    </p>
                    <p>
                        Customer-reported amount:
                        {{
                            attempt.submitted_amount
                                ? `${currency} ${attempt.submitted_amount}`
                                : 'Not provided'
                        }}
                    </p>
                    <p v-if="attempt.transfer_date">
                        Transfer date: {{ attempt.transfer_date }}
                    </p>
                    <p class="text-muted-foreground">
                        Submitted
                        {{ new Date(attempt.submitted_at).toLocaleString() }}
                    </p>
                </div>
                <a
                    :href="`/storefront/orders/${reference}/bank-proofs/${attempt.id}`"
                    class="font-semibold underline"
                    >Download private proof</a
                >
            </div>
            <div v-if="attempt.decision" class="rounded-md bg-muted p-3">
                <p class="font-medium capitalize">
                    {{ label(attempt.decision.decision) }} · {{ currency }}
                    {{ attempt.decision.received_amount }}
                </p>
                <p class="text-muted-foreground">
                    Reviewed by {{ attempt.decision.actor_name_snapshot }} ·
                    {{
                        new Date(attempt.decision.reviewed_at).toLocaleString()
                    }}
                </p>
                <p
                    v-if="attempt.decision.internal_note"
                    class="mt-1 whitespace-pre-wrap"
                >
                    {{ attempt.decision.internal_note }}
                </p>
            </div>
            <form
                v-if="canVerify && attempt.status === 'awaiting_verification'"
                class="space-y-3 rounded-md border p-4"
                @submit.prevent="submit(attempt.id)"
            >
                <p class="font-medium">Record bank reconciliation decision</p>
                <label class="block"
                    >Decision<select
                        v-model="form.decision"
                        required
                        class="mt-1 w-full rounded-md border bg-background p-2"
                    >
                        <option value="">Choose decision</option>
                        <option value="verified">Verified</option>
                        <option value="underpaid">Underpaid</option>
                        <option value="rejected">Rejected</option>
                    </select></label
                >
                <label class="block"
                    >Actual amount received<Input
                        v-model="form.received_amount"
                        type="number"
                        min="0"
                        step="0.01"
                        required
                        class="mt-1"
                /></label>
                <label class="block"
                    >Internal reason or note<Input
                        v-model="form.internal_note"
                        maxlength="1000"
                        :required="
                            form.decision === 'underpaid' ||
                            form.decision === 'rejected'
                        "
                        class="mt-1"
                /></label>
                <p class="text-xs text-muted-foreground">
                    Only the exact remaining amount due can be verified. An
                    overpayment is recorded for manual review and remains
                    unverified.
                </p>
                <p v-if="error" role="alert" class="text-sm text-destructive">
                    {{ error }}
                </p>
                <Button type="submit" :disabled="form.processing">{{
                    form.processing ? 'Saving…' : 'Record decision'
                }}</Button>
            </form>
        </div>
    </section>
</template>
