<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Toaster } from '@/components/ui/sonner';
import ShiftSummaryPanel from '@/components/shifts/ShiftSummary.vue';
import ShiftPayments from '@/components/shifts/ShiftPayments.vue';
import { shiftDate, shiftMoney } from '@/lib/shift';
import { index, approve, show } from '@/routes/shifts';
import type { ShiftDetails } from '@/types/shift';
const props = defineProps<ShiftDetails>();
const approving = ref(false);
function printReport(): void {
    window.print();
}
function approveClosure(): void {
    router.post(
        approve(props.shift.id).url,
        {},
        {
            preserveScroll: true,
            onStart: () => {
                approving.value = true;
            },
            onFinish: () => {
                approving.value = false;
            },
        },
    );
}
</script>
<template>
    <main
        class="min-h-svh bg-muted/30 px-4 py-6 text-foreground sm:px-8 print:bg-white print:p-0 print:text-black"
    >
        <Head title="Daily cash closing report" />
        <Toaster class="print:hidden" />
        <nav
            class="mx-auto mb-6 flex max-w-4xl flex-wrap gap-2 print:hidden"
            aria-label="Report actions"
        >
            <Button variant="outline" as-child
                ><Link :href="index()">All shifts</Link></Button
            ><Button variant="outline" as-child
                ><Link :href="show(shift.id)">Audit trail</Link></Button
            ><Button @click="printReport">Print / Save as PDF</Button
            ><Button
                v-if="canApprove"
                :disabled="approving"
                @click="approveClosure"
                >{{ approving ? 'Approving…' : 'Approve closure' }}</Button
            >
        </nav>
        <article
            class="report mx-auto max-w-4xl space-y-6 rounded-2xl border bg-card p-6 sm:p-10 print:max-w-none print:rounded-none print:border-0 print:bg-white print:p-0"
        >
            <header class="flex flex-wrap justify-between gap-4 border-b pb-5">
                <div>
                    <p
                        class="text-xs font-semibold tracking-widest text-muted-foreground uppercase"
                    >
                        Daily cash closing
                    </p>
                    <h1 class="mt-2 text-2xl font-semibold">
                        {{ summary.identity?.business }}
                    </h1>
                    <p class="mt-1 text-sm">
                        {{ summary.identity?.location }} ·
                        {{ summary.identity?.location_code }}
                    </p>
                </div>
                <div class="text-sm">
                    <p class="font-semibold">{{ shift.reference }}</p>
                    <p class="mt-1 capitalize">
                        {{ shift.status.replaceAll('_', ' ') }}
                    </p>
                    <p
                        v-if="shift.status === 'pending_approval'"
                        class="mt-1 font-medium text-destructive"
                    >
                        Variance · awaiting manager approval
                    </p>
                </div>
            </header>
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-muted-foreground">Cashier</dt>
                    <dd class="font-medium">{{ summary.identity?.cashier }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Closing cashier</dt>
                    <dd class="font-medium">
                        {{ summary.identity?.closed_by }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">
                        Opened ({{ timezone }})
                    </dt>
                    <dd>
                        {{
                            shiftDate(
                                summary.opened_at ?? shift.opened_at,
                                timezone,
                            )
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">
                        Closed ({{ timezone }})
                    </dt>
                    <dd>
                        {{
                            shiftDate(
                                summary.closed_at ?? shift.closed_at,
                                timezone,
                            )
                        }}
                    </dd>
                </div>
            </dl>
            <ShiftSummaryPanel :summary="summary" :currency="currency" />
            <ShiftPayments :summary="summary" :currency="currency" />
            <section class="grid gap-4 rounded-xl border p-5 sm:grid-cols-3">
                <div>
                    <p class="text-sm text-muted-foreground">Expected cash</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums">
                        {{ shiftMoney(shift.expected_cash, currency) }}
                    </p>
                </div>
                <div>
                    <p class="text-sm text-muted-foreground">Actual counted</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums">
                        {{ shiftMoney(shift.actual_cash, currency) }}
                    </p>
                </div>
                <div>
                    <p class="text-sm text-muted-foreground">
                        {{
                            shift.variance === '0.00'
                                ? 'Balanced'
                                : shift.variance?.startsWith('-')
                                  ? 'Shortage'
                                  : 'Surplus'
                        }}
                    </p>
                    <p
                        class="mt-1 text-xl font-semibold tabular-nums"
                        :class="
                            shift.variance !== '0.00' ? 'text-destructive' : ''
                        "
                    >
                        {{ shiftMoney(shift.variance, currency) }}
                    </p>
                </div>
            </section>
            <dl class="space-y-4 text-sm">
                <div v-if="shift.opening_note">
                    <dt class="font-semibold">Opening note</dt>
                    <dd class="mt-1 whitespace-pre-wrap">
                        {{ shift.opening_note }}
                    </dd>
                </div>
                <div v-if="shift.variance_reason">
                    <dt class="font-semibold">Variance explanation</dt>
                    <dd class="mt-1 whitespace-pre-wrap">
                        {{ shift.variance_reason }}
                    </dd>
                </div>
                <div v-if="shift.closing_note">
                    <dt class="font-semibold">Closing note</dt>
                    <dd class="mt-1 whitespace-pre-wrap">
                        {{ shift.closing_note }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold">Manager review</dt>
                    <dd class="mt-1">
                        {{
                            shift.approver_name
                                ? `${shift.approver_name} · ${shiftDate(shift.approved_at, timezone)}`
                                : shift.status === 'pending_approval'
                                  ? 'Pending approval by another authorized manager'
                                  : 'No variance approval required'
                        }}
                    </dd>
                </div>
            </dl>
            <footer class="border-t pt-4 text-xs text-muted-foreground">
                Generated {{ shiftDate(generatedAt, timezone) }} · Totals and
                business details were saved at closing. Variances do not adjust
                sales or inventory.
            </footer>
        </article>
    </main>
</template>
<style scoped>
@media print {
    @page {
        size: A4;
        margin: 14mm;
    }
    .report {
        font-size: 11px;
    }
    .report :deep(section),
    .report header {
        break-inside: avoid;
    }
    .report :deep(.surface-panel) {
        box-shadow: none;
        border-color: #d1d5db;
        background: white;
        color: black;
    }
}
</style>
