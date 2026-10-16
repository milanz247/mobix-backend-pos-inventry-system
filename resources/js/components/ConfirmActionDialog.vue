<script setup lang="ts">
import {
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogOverlay,
    AlertDialogPortal,
    AlertDialogRoot,
    AlertDialogTitle,
} from 'reka-ui';
import { Button } from '@/components/ui/button';

withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        description: string;
        confirmLabel: string;
        destructive?: boolean;
    }>(),
    { destructive: false },
);

const emit = defineEmits<{
    'update:open': [open: boolean];
    confirm: [];
}>();
</script>

<template>
    <AlertDialogRoot :open="open" @update:open="emit('update:open', $event)">
        <AlertDialogPortal>
            <AlertDialogOverlay
                class="fixed inset-0 z-50 bg-slate-950/45 backdrop-blur-[2px] data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0"
            />
            <AlertDialogContent
                class="fixed top-1/2 left-1/2 z-50 grid w-[calc(100%-2rem)] max-w-md -translate-x-1/2 -translate-y-1/2 gap-5 rounded-xl border border-border bg-card p-5 shadow-xl shadow-slate-950/15 outline-none sm:p-6 dark:shadow-black/40"
            >
                <div class="space-y-2">
                    <AlertDialogTitle class="text-base font-semibold">
                        {{ title }}
                    </AlertDialogTitle>
                    <AlertDialogDescription
                        class="text-sm leading-6 text-muted-foreground"
                    >
                        {{ description }}
                    </AlertDialogDescription>
                </div>
                <div
                    class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"
                >
                    <AlertDialogCancel as-child>
                        <Button type="button" variant="outline">Cancel</Button>
                    </AlertDialogCancel>
                    <Button
                        type="button"
                        :variant="destructive ? 'destructive' : 'default'"
                        @click="emit('confirm')"
                    >
                        {{ confirmLabel }}
                    </Button>
                </div>
            </AlertDialogContent>
        </AlertDialogPortal>
    </AlertDialogRoot>
</template>
