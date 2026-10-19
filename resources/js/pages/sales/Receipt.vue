<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Printer } from '@lucide/vue';
import { onBeforeUnmount, onMounted } from 'vue';
import InvoiceDocument from '@/components/sales/InvoiceDocument.vue';
import { Button } from '@/components/ui/button';
import { show } from '@/routes/sales';
import type { InvoiceBusiness, InvoiceSale } from '@/types/invoice';

const props = defineProps<{
    sale: InvoiceSale;
    business: InvoiceBusiness;
    autoPrint: boolean;
}>();

let printTimer: ReturnType<typeof setTimeout> | undefined;

function printReceipt(): void {
    window.print();
}

function closeAutoPrintWindow(): void {
    if (props.autoPrint && window.self === window.top) {
        window.close();
    }
}

onMounted(async () => {
    window.addEventListener('afterprint', closeAutoPrintWindow);

    if (!props.autoPrint) {
        return;
    }

    await document.fonts?.ready;
    printTimer = setTimeout(printReceipt, 200);
});

onBeforeUnmount(() => {
    if (printTimer) {
        clearTimeout(printTimer);
    }

    window.removeEventListener('afterprint', closeAutoPrintWindow);
});
</script>

<template>
    <Head :title="`Receipt ${sale.sale_number}`" />
    <main
        id="receipt-print-root"
        class="min-h-svh bg-slate-100 p-4 text-slate-950 sm:p-6 print:min-h-0 print:bg-white print:p-0"
    >
        <div
            class="mx-auto mb-4 flex max-w-5xl flex-wrap justify-between gap-2 print:hidden"
        >
            <Button as-child variant="outline">
                <Link :href="show(sale.id)"><ArrowLeft /> Back to sale</Link>
            </Button>
            <div class="flex gap-2">
                <span
                    class="flex items-center rounded-md border bg-card px-3 text-sm text-muted-foreground"
                >
                    {{
                        business.invoice_paper_size === 'thermal'
                            ? `${business.invoice_thermal_width} mm thermal`
                            : 'A4 invoice'
                    }}
                </span>
                <Button @click="printReceipt"><Printer /> Print receipt</Button>
            </div>
        </div>

        <InvoiceDocument :sale="sale" :business="business" />
    </main>
</template>

<style scoped>
@media print {
    :global(html),
    :global(body),
    :global(#app) {
        min-height: 0 !important;
        height: auto !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
        background-image: none !important;
    }
}
</style>
