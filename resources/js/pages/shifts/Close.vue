<script setup lang="ts">
import { Head, useForm, usePage, usePoll } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import ShiftLinks from '@/components/shifts/ShiftLinks.vue';
import ShiftSummaryPanel from '@/components/shifts/ShiftSummary.vue';
import ShiftPayments from '@/components/shifts/ShiftPayments.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { close } from '@/routes/shifts';
import { cashCents, signedCents, shiftMoney } from '@/lib/shift';
import type { ShiftDetails } from '@/types/shift';
const props = defineProps<ShiftDetails>();
const page = usePage();
const form = useForm({
    actual_cash: '',
    variance_reason: '',
    closing_note: '',
});
const variance = computed(() => {
    const actual = cashCents(form.actual_cash);
    const expected = props.summary.expected_cash;
    const expectedCents = cashCents(expected.replace('-', ''));
    return actual === null || expectedCents === null
        ? null
        : signedCents(
              actual -
                  (expected.startsWith('-') ? -expectedCents : expectedCents),
          );
});
function submit(): void {
    form.post(close(props.shift.id).url);
}
usePoll(15000, { only: ['summary', 'shift', 'canManage'] });
</script>
<template>
    <section class="page-shell space-y-6">
        <Head title="Close shift" /><Heading
            title="Close shift"
            description="Count the physical drawer. The server recalculates expected cash when you submit; the difference remains in the audit history."
        />
        <ShiftLinks :shift="shift" :can-manage="canManage" />
        <div class="grid gap-5 lg:grid-cols-2">
            <ShiftSummaryPanel
                :summary="summary"
                :currency="currency"
            /><ShiftPayments :summary="summary" :currency="currency" />
        </div>
        <form
            class="surface-panel max-w-3xl space-y-5 p-5 sm:p-6"
            @submit.prevent="submit"
        >
            <div class="grid gap-2">
                <Label for="actual"
                    >Actual physical cash counted ({{ currency }})</Label
                ><Input
                    id="actual"
                    v-model="form.actual_cash"
                    type="number"
                    min="0"
                    max="999999999999.99"
                    step="0.01"
                    required
                /><InputError :message="form.errors.actual_cash" />
            </div>
            <div
                v-if="variance !== null"
                class="rounded-xl border p-4"
                :class="
                    variance === '0.00'
                        ? 'border-success/30 bg-success/5'
                        : 'border-destructive/30 bg-destructive/5'
                "
            >
                <p class="text-sm font-medium">
                    {{
                        variance === '0.00'
                            ? 'Cash reconciles'
                            : variance.startsWith('-')
                              ? 'Cash shortage'
                              : 'Cash surplus'
                    }}
                </p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">
                    {{ shiftMoney(variance, currency) }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Actual cash − expected cash. Variances require another
                    authorized manager’s approval.
                </p>
            </div>
            <div class="grid gap-2">
                <Label for="variance-reason"
                    >Variance reason
                    {{
                        variance !== null && variance !== '0.00'
                            ? '(required)'
                            : '(if applicable)'
                    }}</Label
                ><textarea
                    id="variance-reason"
                    v-model="form.variance_reason"
                    :required="variance !== null && variance !== '0.00'"
                    maxlength="2000"
                    rows="3"
                    class="rounded-lg border bg-background p-3 text-sm"
                /><InputError :message="form.errors.variance_reason" />
            </div>
            <div class="grid gap-2">
                <Label for="closing-note">Closing note (optional)</Label
                ><textarea
                    id="closing-note"
                    v-model="form.closing_note"
                    maxlength="2000"
                    rows="3"
                    class="rounded-lg border bg-background p-3 text-sm"
                /><InputError :message="form.errors.closing_note" />
            </div>
            <InputError :message="page.props.errors.shift" />
            <p class="text-sm text-muted-foreground">
                Closing locks this shift permanently. Verify your count before
                submitting.
            </p>
            <Button :disabled="form.processing || !canManage">{{
                form.processing ? 'Closing…' : 'Confirm count & close shift'
            }}</Button>
        </form>
    </section>
</template>
