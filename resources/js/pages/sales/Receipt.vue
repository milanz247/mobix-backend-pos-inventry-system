<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Printer } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted } from 'vue';
import { Button } from '@/components/ui/button';
import sales, { index, show } from '@/routes/sales';

type ReceiptSale = {
    id: number;
    sale_number: string;
    posted_at: string;
    cashier: string;
    location: { name: string; code: string };
    customer: {
        name: string | null;
        phone: string | null;
        email: string | null;
    };
    subtotal: string;
    discount_total: string;
    tax_total: string;
    total: string;
    paid_total: string;
    returned_total: string;
    refund_total: string;
    lines: {
        id: number;
        product_name: string;
        variant_name: string;
        sku: string;
        quantity: string;
        unit_price: string;
        discount_amount: string;
        total: string;
        imei: string | null;
        is_combo: boolean;
        components: { name: string; quantity: string; imei: string | null }[];
    }[];
    payments: {
        amount: string;
        cash_tendered: string | null;
        change_given: string;
        method: string;
        reference: string | null;
    }[];
};
type Business = {
    name: string;
    phone: string;
    email: string | null;
    address: string;
    city: string;
    logo_url: string | null;
    currency: string;
    invoice_footer: string | null;
    invoice_return_policy: string | null;
    invoice_title: string;
    invoice_paper_size: 'a4' | 'thermal';
    invoice_thermal_width: 58 | 80;
    invoice_accent_color: string;
    invoice_show_logo: boolean;
    invoice_show_business_details: boolean;
    invoice_show_customer: boolean;
    invoice_show_cashier: boolean;
    invoice_show_payment_details: boolean;
    invoice_show_tax: boolean;
    invoice_show_product_codes: boolean;
    invoice_show_line_discounts: boolean;
};
const props = defineProps<{
    sale: ReceiptSale;
    business: Business;
    autoPrint: boolean;
}>();
const thermal = computed(() => props.business.invoice_paper_size === 'thermal');
const receiptStyle = computed(() => ({
    '--invoice-accent': props.business.invoice_accent_color,
    '--receipt-width': `${props.business.invoice_thermal_width}mm`,
}));
let printTimer: ReturnType<typeof setTimeout> | undefined;
function money(value: string): string {
    const [whole = '0', fraction = '00'] = value.split('.', 2);
    return `${props.business.currency} ${BigInt(whole || '0').toLocaleString()}.${fraction.padEnd(2, '0').slice(0, 2)}`;
}
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
    if (printTimer) clearTimeout(printTimer);
    window.removeEventListener('afterprint', closeAutoPrintWindow);
});
defineOptions({
    layout: { breadcrumbs: [{ title: 'Sales history', href: index() }] },
});
</script>

<template>
    <Head :title="`Receipt ${sale.sale_number}`" />
    <section class="page-shell space-y-4 print:!block print:!p-0">
        <div class="flex flex-wrap justify-between gap-2 print:hidden">
            <Button as-child variant="outline"
                ><Link :href="show(sale.id)"
                    ><ArrowLeft /> Back to sale</Link
                ></Button
            >
            <div class="flex gap-2">
                <span
                    class="flex items-center rounded-md border bg-card px-3 text-sm text-muted-foreground"
                >
                    {{
                        thermal
                            ? `${business.invoice_thermal_width} mm thermal`
                            : 'A4 invoice'
                    }}
                </span>
                <Button @click="printReceipt"><Printer /> Print receipt</Button>
            </div>
        </div>
        <article
            id="sale-receipt"
            :class="[
                thermal ? 'receipt-thermal' : 'receipt-a4',
                thermal && business.invoice_thermal_width === 58
                    ? 'receipt-thermal-compact'
                    : '',
            ]"
            class="mx-auto overflow-hidden rounded-lg border border-slate-200 bg-white p-6 text-slate-900 shadow-sm print:border-0 print:shadow-none"
            :style="receiptStyle"
        >
            <div class="invoice-accent-bg mb-5 h-1.5 rounded-full" />
            <header class="space-y-1 text-center">
                <img
                    v-if="business.invoice_show_logo && business.logo_url"
                    :src="business.logo_url"
                    :alt="`${business.name} logo`"
                    class="mx-auto mb-2 max-h-16 max-w-40 object-contain"
                />
                <h1 class="text-xl font-bold">{{ business.name }}</h1>
                <div v-if="business.invoice_show_business_details">
                    <p class="text-sm">
                        {{ business.address }}, {{ business.city }}
                    </p>
                    <p class="text-sm">
                        {{ business.phone
                        }}<span v-if="business.email">
                            · {{ business.email }}</span
                        >
                    </p>
                </div>
                <hr class="my-4 border-dashed" />
                <h2 class="invoice-accent text-lg font-semibold">
                    {{ business.invoice_title }}
                </h2>
                <p class="font-medium">{{ sale.sale_number }}</p>
                <p class="text-sm">{{ sale.posted_at }}</p>
            </header>
            <div
                v-if="
                    business.invoice_show_customer ||
                    business.invoice_show_cashier
                "
                class="mt-4 grid grid-cols-2 gap-2 text-sm"
            >
                <p>
                    <span class="font-medium">Location:</span>
                    {{ sale.location.name }} ({{ sale.location.code }})
                </p>
                <p v-if="business.invoice_show_cashier" class="text-right">
                    <span class="font-medium">Cashier:</span> {{ sale.cashier }}
                </p>
                <p v-if="business.invoice_show_customer">
                    <span class="font-medium">Customer:</span>
                    {{ sale.customer.name || 'Walk-in customer' }}
                </p>
                <p v-if="business.invoice_show_customer" class="text-right">
                    {{ sale.customer.phone || '' }}
                </p>
            </div>
            <div
                v-if="thermal"
                class="mt-4 divide-y divide-dashed border-y border-dashed text-xs"
            >
                <div
                    v-for="line in sale.lines"
                    :key="line.id"
                    class="space-y-1 py-2.5"
                >
                    <div class="flex items-start justify-between gap-3">
                        <p class="min-w-0 font-semibold">
                            {{ line.product_name }} · {{ line.variant_name }}
                        </p>
                        <p class="shrink-0 font-bold tabular-nums">
                            {{ money(line.total) }}
                        </p>
                    </div>
                    <p
                        v-if="business.invoice_show_product_codes"
                        class="text-[0.68rem] break-all text-slate-500"
                    >
                        {{ line.sku }}
                        <span v-if="line.imei">· IMEI {{ line.imei }}</span>
                    </p>
                    <div
                        class="flex items-center justify-between gap-3 text-slate-600"
                    >
                        <span>
                            {{ line.quantity }} × {{ money(line.unit_price) }}
                        </span>
                        <span
                            v-if="
                                business.invoice_show_line_discounts &&
                                line.discount_amount !== '0.00'
                            "
                            class="text-emerald-700"
                        >
                            Discount −{{ money(line.discount_amount) }}
                        </span>
                    </div>
                    <div
                        v-for="component in line.components"
                        :key="component.name + (component.imei || '')"
                        class="flex justify-between gap-2 pl-2 text-[0.68rem] text-slate-500"
                    >
                        <span>
                            ↳ {{ component.name }}
                            <template
                                v-if="
                                    business.invoice_show_product_codes &&
                                    component.imei
                                "
                            >
                                · {{ component.imei }}
                            </template>
                        </span>
                        <span>{{ component.quantity }} · Included</span>
                    </div>
                </div>
            </div>
            <div v-else class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-y text-left">
                            <th class="py-2">Item</th>
                            <th class="px-2 py-2 text-right">Qty</th>
                            <th class="px-2 py-2 text-right">Price</th>
                            <th class="py-2 text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="line in sale.lines" :key="line.id"
                            ><tr class="border-b align-top">
                                <td class="py-2">
                                    <div class="font-medium">
                                        {{ line.product_name }} ·
                                        {{ line.variant_name }}
                                    </div>
                                    <div
                                        v-if="
                                            business.invoice_show_product_codes
                                        "
                                        class="text-xs text-slate-500"
                                    >
                                        {{ line.sku
                                        }}<span v-if="line.imei">
                                            · IMEI {{ line.imei }}</span
                                        >
                                    </div>
                                    <div
                                        v-if="
                                            business.invoice_show_line_discounts &&
                                            line.discount_amount !== '0.00'
                                        "
                                        class="text-xs text-emerald-700"
                                    >
                                        Line discount: −{{
                                            money(line.discount_amount)
                                        }}
                                    </div>
                                </td>
                                <td class="px-2 py-2 text-right tabular-nums">
                                    {{ line.quantity }}
                                </td>
                                <td class="px-2 py-2 text-right tabular-nums">
                                    {{ money(line.unit_price) }}
                                </td>
                                <td
                                    class="py-2 text-right font-medium tabular-nums"
                                >
                                    {{ money(line.total) }}
                                </td>
                            </tr>
                            <tr
                                v-for="component in line.components"
                                :key="`${line.id}-${component.name}-${component.imei}`"
                                class="border-b text-xs"
                            >
                                <td class="py-1 pl-3">
                                    Bundle · {{ component.name
                                    }}<span
                                        v-if="
                                            business.invoice_show_product_codes &&
                                            component.imei
                                        "
                                    >
                                        · IMEI {{ component.imei }}</span
                                    >
                                </td>
                                <td class="px-2 py-1 text-right">
                                    {{ component.quantity }}
                                </td>
                                <td colspan="2" class="py-1 text-right">
                                    Included
                                </td>
                            </tr></template
                        >
                    </tbody>
                </table>
            </div>
            <div class="mt-4 ml-auto max-w-xs space-y-1 text-sm">
                <div class="flex justify-between">
                    <span>Subtotal</span><span>{{ money(sale.subtotal) }}</span>
                </div>
                <div
                    v-if="sale.discount_total !== '0.00'"
                    class="flex justify-between text-emerald-700"
                >
                    <span>Discount</span
                    ><span>−{{ money(sale.discount_total) }}</span>
                </div>
                <div
                    v-if="business.invoice_show_tax"
                    class="flex justify-between"
                >
                    <span>Tax</span><span>{{ money(sale.tax_total) }}</span>
                </div>
                <div
                    class="invoice-accent flex justify-between border-t pt-2 text-base font-bold"
                >
                    <span>Total</span><span>{{ money(sale.total) }}</span>
                </div>
                <div class="flex justify-between">
                    <span>Paid</span><span>{{ money(sale.paid_total) }}</span>
                </div>
                <div
                    v-if="sale.returned_total !== '0.00'"
                    class="flex justify-between"
                >
                    <span>Returned</span
                    ><span>{{ money(sale.returned_total) }}</span>
                </div>
                <div
                    v-if="sale.refund_total !== '0.00'"
                    class="flex justify-between"
                >
                    <span>Refunded</span
                    ><span>{{ money(sale.refund_total) }}</span>
                </div>
            </div>
            <div
                v-if="business.invoice_show_payment_details"
                class="mt-4 border-t border-dashed pt-3 text-sm"
            >
                <h3 class="font-semibold">Payment method(s)</h3>
                <div v-for="(payment, index) in sale.payments" :key="index">
                    <p class="flex justify-between">
                        <span
                            >{{ payment.method.replaceAll('_', ' ')
                            }}<span v-if="payment.reference">
                                · {{ payment.reference }}</span
                            ></span
                        ><span>{{ money(payment.amount) }}</span>
                    </p>
                    <p
                        v-if="
                            payment.method === 'cash' && payment.cash_tendered
                        "
                        class="flex justify-between text-xs"
                    >
                        <span>Cash received · change</span>
                        <span
                            >{{ money(payment.cash_tendered) }} ·
                            {{ money(payment.change_given) }}</span
                        >
                    </p>
                </div>
            </div>
            <footer
                class="mt-6 border-t border-dashed pt-3 text-center text-xs"
            >
                <p>
                    {{
                        business.invoice_footer ||
                        'Thank you for your purchase.'
                    }}
                </p>
                <p class="mt-1">
                    {{
                        business.invoice_return_policy ||
                        'Keep this receipt for service and return reference.'
                    }}
                </p>
                <p class="mt-3 text-[10px]">
                    NalakaMobile · {{ sale.sale_number }}
                </p>
            </footer>
        </article>
    </section>
</template>

<style scoped>
.receipt-a4 {
    max-width: 210mm;
}
.receipt-thermal {
    width: var(--receipt-width);
    max-width: 100%;
    padding: 4mm;
}
.receipt-thermal-compact {
    padding: 3mm;
    font-size: 0.8rem;
}
.invoice-accent {
    color: var(--invoice-accent);
}
.invoice-accent-bg {
    background-color: var(--invoice-accent);
}
@media print {
    @page {
        size: auto;
        margin: 4mm;
    }
    .receipt-a4 {
        width: 100%;
        max-width: none;
    }
    .receipt-thermal {
        width: var(--receipt-width);
        max-width: var(--receipt-width);
        padding: 0;
    }
    #sale-receipt {
        print-color-adjust: exact;
    }
}
</style>
