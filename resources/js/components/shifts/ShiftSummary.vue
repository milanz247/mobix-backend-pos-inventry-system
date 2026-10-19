<script setup lang="ts">
import { shiftMoney } from '@/lib/shift';
import type { ShiftSummary } from '@/types/shift';
defineProps<{ summary: ShiftSummary; currency: string }>();
const labels: Record<string, string> = {
    cash_in: 'Cash in',
    cash_out: 'Cash out',
    expense: 'Cash expenses',
    drop: 'Cash drops',
};
</script>
<template>
    <section class="surface-panel overflow-hidden">
        <h2 class="border-b p-4 font-semibold">Drawer reconciliation</h2>
        <dl class="divide-y px-4">
            <div class="flex justify-between gap-4 py-3">
                <dt>Opening cash</dt>
                <dd class="font-medium tabular-nums">
                    {{ shiftMoney(summary.opening_cash, currency) }}
                </dd>
            </div>
            <div class="flex justify-between gap-4 py-3">
                <dt>+ Cash payments received</dt>
                <dd class="font-medium tabular-nums">
                    {{ shiftMoney(summary.cash_sales, currency) }}
                </dd>
            </div>
            <div class="flex justify-between gap-4 py-3">
                <dt>− Cash refunds</dt>
                <dd class="font-medium tabular-nums">
                    {{ shiftMoney(summary.cash_refunds, currency) }}
                </dd>
            </div>
            <div
                v-for="(label, key) in labels"
                :key="key"
                class="flex justify-between gap-4 py-3"
            >
                <dt>{{ key === 'cash_in' ? '+' : '−' }} {{ label }}</dt>
                <dd class="font-medium tabular-nums">
                    {{ shiftMoney(summary.movements[key] ?? '0.00', currency) }}
                </dd>
            </div>
            <div
                class="flex justify-between gap-4 py-4 text-lg font-semibold text-primary"
            >
                <dt>Expected cash</dt>
                <dd class="tabular-nums">
                    {{ shiftMoney(summary.expected_cash, currency) }}
                </dd>
            </div>
        </dl>
        <div
            class="border-t bg-muted/30 p-4 text-xs leading-5 text-muted-foreground"
        >
            Cash payments are net of change. Collections on earlier credit sales
            are included when received during this shift. Non-cash payments do
            not affect the drawer. Reversed movements cancel their original
            entries.
        </div>
    </section>
</template>
