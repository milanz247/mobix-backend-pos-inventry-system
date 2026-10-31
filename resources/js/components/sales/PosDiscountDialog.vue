<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
    DialogFooter,
} from '@/components/ui/dialog';
const open = defineModel<boolean>('open', { required: true });
const discountMode = defineModel<'amount' | 'percentage'>('mode', {
    required: true,
});
const discountValue = defineModel<string>('value', { required: true });
defineProps<{
    lineName: string | null;
    currency: string;
    discountBase: bigint;
    discountPreview: bigint;
    money: (value: bigint) => string;
    minorUnits: (value: string) => bigint;
}>();
const emit = defineEmits<{ clear: []; apply: [] }>();
</script>
<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="max-h-[calc(100dvh-2rem)] gap-0 overflow-y-auto p-0 sm:max-w-md"
        >
            <DialogHeader class="border-b bg-muted/30 px-5 py-4 text-left">
                <DialogTitle>
                    {{ !lineName ? 'Total discount' : 'Product discount' }}
                </DialogTitle>
                <DialogDescription>
                    <template v-if="!lineName">
                        Apply a percentage or fixed amount to the whole order.
                    </template>
                    <template v-else>
                        {{ lineName }}
                    </template>
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-4 p-5">
                <div class="grid grid-cols-2 rounded-lg bg-muted p-1">
                    <Button
                        type="button"
                        size="sm"
                        :variant="
                            discountMode === 'percentage' ? 'default' : 'ghost'
                        "
                        @click="discountMode = 'percentage'"
                    >
                        Percentage
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        :variant="
                            discountMode === 'amount' ? 'default' : 'ghost'
                        "
                        @click="discountMode = 'amount'"
                    >
                        Fixed amount
                    </Button>
                </div>

                <div class="grid gap-1.5">
                    <Label for="discount-value">
                        {{
                            discountMode === 'percentage'
                                ? 'Discount percentage'
                                : `Discount amount (${currency})`
                        }}
                    </Label>
                    <div class="relative">
                        <Input
                            id="discount-value"
                            v-model="discountValue"
                            inputmode="decimal"
                            class="h-12 pr-14 text-lg font-semibold tabular-nums"
                            :placeholder="
                                discountMode === 'percentage' ? '0' : '0.00'
                            "
                        />
                        <span
                            class="pointer-events-none absolute top-1/2 right-4 -translate-y-1/2 text-sm font-semibold text-muted-foreground"
                        >
                            {{ discountMode === 'percentage' ? '%' : currency }}
                        </span>
                    </div>
                    <p
                        v-if="
                            discountMode === 'percentage' &&
                            minorUnits(discountValue) > 10000n
                        "
                        class="text-xs text-destructive"
                    >
                        Percentage is limited to 100%.
                    </p>
                </div>

                <div
                    class="space-y-2 rounded-lg border bg-muted/20 p-3 text-sm"
                >
                    <div class="flex justify-between gap-3">
                        <span class="text-muted-foreground"
                            >Before discount</span
                        >
                        <span class="font-medium tabular-nums">{{
                            money(discountBase)
                        }}</span>
                    </div>
                    <div class="flex justify-between gap-3">
                        <span class="text-muted-foreground">Discount</span>
                        <span class="font-medium text-primary tabular-nums"
                            >-{{ money(discountPreview) }}</span
                        >
                    </div>
                    <div class="flex justify-between gap-3 border-t pt-2">
                        <span class="font-semibold">After discount</span>
                        <span class="font-bold tabular-nums">{{
                            money(discountBase - discountPreview)
                        }}</span>
                    </div>
                </div>
            </div>

            <DialogFooter
                class="flex-row justify-between border-t bg-muted/20 p-3"
            >
                <Button type="button" variant="ghost" @click="emit('clear')">
                    Clear
                </Button>
                <Button type="button" @click="emit('apply')">
                    Apply discount
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
