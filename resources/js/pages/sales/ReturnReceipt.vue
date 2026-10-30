<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Printer, ArrowLeft } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import ReturnDocument from '@/components/sales/ReturnDocument.vue';
import type { InvoiceBusiness } from '@/types/invoice';
import type { ReturnReceipt } from '@/types/return-receipt';
defineProps<{ receipt: ReturnReceipt; business: InvoiceBusiness }>();
async function printReceipt(): Promise<void> {
    await document.fonts?.ready;
    window.print();
}
</script>
<template>
    <Head
        :title="`${receipt.is_exchange ? 'Replacement' : 'Return'} ${receipt.return_number}`"
    />
    <main
        class="min-h-svh bg-slate-100 p-4 text-slate-950 sm:p-6 print:min-h-0 print:bg-white print:p-0"
    >
        <nav
            class="mx-auto mb-4 flex max-w-5xl flex-wrap justify-between gap-2 print:hidden"
        >
            <Button as-child variant="outline"
                ><Link :href="`/sales/${receipt.sale_id}`"
                    ><ArrowLeft /> Back to invoice</Link
                ></Button
            ><Button @click="printReceipt"
                ><Printer /> Print
                {{ receipt.is_exchange ? 'replacement' : 'return' }}
                receipt</Button
            >
        </nav>
        <ReturnDocument :receipt="receipt" :business="business" />
    </main>
</template>
<style scoped>
@media print {
    :global(html),
    :global(body),
    :global(#app) {
        margin: 0 !important;
        padding: 0 !important;
        min-height: 0 !important;
        height: auto !important;
        background: #fff !important;
    }
}
</style>
