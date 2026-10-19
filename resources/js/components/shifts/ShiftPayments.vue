<script setup lang="ts">
import { shiftMoney } from '@/lib/shift';
import type { ShiftSummary } from '@/types/shift';
defineProps<{ summary: ShiftSummary; currency: string }>();
</script>
<template>
    <section class="surface-panel p-4">
        <h2 class="mb-4 font-semibold">Payments and refunds by method</h2>
        <div class="overflow-x-auto">
            <table class="data-table min-w-[360px]">
                <thead>
                    <tr>
                        <th>Method</th>
                        <th class="text-right">Received</th>
                        <th class="text-right">Refunded</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="method in [
                            ...new Set([
                                ...Object.keys(summary.payments),
                                ...Object.keys(summary.refunds),
                            ]),
                        ]"
                        :key="method"
                    >
                        <td class="capitalize">
                            {{ method.replaceAll('_', ' ') }}
                        </td>
                        <td class="text-right tabular-nums">
                            {{
                                shiftMoney(
                                    summary.payments[method] ?? '0.00',
                                    currency,
                                )
                            }}
                        </td>
                        <td class="text-right tabular-nums">
                            {{
                                shiftMoney(
                                    summary.refunds[method] ?? '0.00',
                                    currency,
                                )
                            }}
                        </td>
                    </tr>
                    <tr
                        v-if="
                            !Object.keys(summary.payments).length &&
                            !Object.keys(summary.refunds).length
                        "
                    >
                        <td
                            colspan="3"
                            class="py-6 text-center text-muted-foreground"
                        >
                            No payments or refunds yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="mt-4 text-xs text-muted-foreground">
            {{ summary.sales_count }} completed sales opened in this shift
            (including subsequently returned sales).
        </p>
    </section>
</template>
