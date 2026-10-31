<script setup lang="ts">
import { CircleCheckBig, Printer } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
    DialogFooter,
} from '@/components/ui/dialog';
import type { CompletedSale } from '@/types/pos';
const open = defineModel<boolean>('open', { required: true });
defineProps<{
    completedSale: CompletedSale | null;
    canPrintReceipt: boolean;
    money: (value: bigint) => string;
    minorUnits: (value: string) => bigint;
}>();
const emit = defineEmits<{ print: [] }>();
</script>
<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="max-h-[calc(100dvh-2rem)] gap-0 overflow-y-auto p-0 sm:max-w-sm"
        >
            <DialogHeader class="items-center border-b px-5 py-5 text-center">
                <span
                    class="mb-1 grid size-12 place-items-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"
                >
                    <CircleCheckBig class="size-7" />
                </span>
                <DialogTitle>Sale completed</DialogTitle>
                <DialogDescription v-if="completedSale">
                    {{ completedSale.sale_number }} ·
                    {{ completedSale.customer }}
                </DialogDescription>
            </DialogHeader>

            <div v-if="completedSale" class="space-y-3 p-5">
                <div class="flex justify-between gap-3 text-sm">
                    <span class="text-muted-foreground">Sale total</span>
                    <span class="font-semibold tabular-nums">{{
                        money(minorUnits(completedSale.total))
                    }}</span>
                </div>
                <div
                    v-if="completedSale.cash_tendered"
                    class="flex justify-between gap-3 text-sm"
                >
                    <span class="text-muted-foreground">Cash received</span>
                    <span class="font-semibold tabular-nums">{{
                        money(minorUnits(completedSale.cash_tendered))
                    }}</span>
                </div>
                <div
                    class="rounded-xl bg-primary px-4 py-4 text-center text-primary-foreground"
                >
                    <p class="text-xs font-medium text-primary-foreground/75">
                        {{
                            minorUnits(completedSale.outstanding) > 0n
                                ? 'Credit balance due'
                                : 'Change to customer'
                        }}
                    </p>
                    <p class="mt-1 text-3xl font-bold tabular-nums">
                        {{
                            money(
                                minorUnits(
                                    minorUnits(completedSale.outstanding) > 0n
                                        ? completedSale.outstanding
                                        : completedSale.change_given,
                                ),
                            )
                        }}
                    </p>
                </div>
            </div>

            <DialogFooter class="flex-row border-t bg-muted/30 p-3">
                <Button
                    type="button"
                    variant="outline"
                    class="flex-1"
                    @click="open = false"
                >
                    Next sale
                </Button>
                <Button
                    v-if="completedSale && canPrintReceipt"
                    type="button"
                    class="flex-1"
                    @click="emit('print')"
                >
                    <Printer class="size-4" />
                    Print receipt
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
