<script setup lang="ts">
import { computed } from 'vue';
import type { InvoiceBusiness, InvoiceSale } from '@/types/invoice';

const props = withDefaults(
    defineProps<{
        sale: InvoiceSale;
        business: InvoiceBusiness;
        preview?: boolean;
    }>(),
    { preview: false },
);

const thermal = computed(() => props.business.invoice_paper_size === 'thermal');
const thermalContentWidth = computed(() =>
    props.business.invoice_thermal_width === 58 ? '48mm' : '72mm',
);
const documentStyle = computed(() => ({
    '--invoice-accent': props.business.invoice_accent_color,
    '--receipt-width': `${props.business.invoice_thermal_width}mm`,
    '--receipt-content-width': thermalContentWidth.value,
}));

function money(value: string): string {
    const [whole = '0', fraction = '00'] = value.split('.', 2);

    return `${props.business.currency} ${BigInt(whole || '0').toLocaleString()}.${fraction.padEnd(2, '0').slice(0, 2)}`;
}

function minorUnits(value: string): bigint {
    const normalizedValue = value.trim();
    const isNegative = normalizedValue.startsWith('-');
    const unsignedValue = normalizedValue.replace(/^[+-]/, '');
    const [whole = '0', fraction = ''] = unsignedValue.split('.', 2);
    const amount =
        BigInt(whole || '0') * 100n +
        BigInt(fraction.padEnd(2, '0').slice(0, 2) || '0');

    return isNegative ? -amount : amount;
}

function moneyFromMinor(value: bigint): string {
    const sign = value < 0n ? '-' : '';
    const absoluteValue = value < 0n ? -value : value;

    return `${sign}${props.business.currency} ${(absoluteValue / 100n).toLocaleString()}.${(absoluteValue % 100n).toString().padStart(2, '0')}`;
}

const outstandingBalance = computed(() => {
    const balance =
        minorUnits(props.sale.total) -
        minorUnits(props.sale.returned_total) -
        (minorUnits(props.sale.paid_total) -
            minorUnits(props.sale.refund_total));

    return balance > 0n ? balance : 0n;
});
</script>

<template>
    <article
        :id="preview ? undefined : 'sale-receipt'"
        :class="[
            thermal ? 'invoice-thermal' : 'invoice-a4',
            thermal && business.invoice_thermal_width === 58
                ? 'invoice-thermal-compact'
                : '',
            preview ? 'invoice-preview' : '',
        ]"
        class="invoice-document mx-auto box-border overflow-hidden rounded-lg border border-slate-200 bg-white p-6 text-slate-950 shadow-sm print:rounded-none print:border-0 print:shadow-none"
        :style="documentStyle"
    >
        <header class="space-y-0.5 text-center">
            <img
                v-if="business.invoice_show_logo && business.logo_url"
                :src="business.logo_url"
                :alt="`${business.name} logo`"
                class="mx-auto mb-2 max-h-14 max-w-36 object-contain print:max-h-12"
            />
            <h1
                class="invoice-accent text-lg leading-tight font-bold break-words"
            >
                {{ business.name }}
            </h1>
            <div
                v-if="business.invoice_show_business_details"
                class="space-y-0.5 text-xs leading-snug"
            >
                <p class="break-words">
                    {{ business.address
                    }}<template v-if="business.city"
                        >, {{ business.city }}</template
                    >
                </p>
                <p v-if="business.phone">Tel: {{ business.phone }}</p>
                <p v-if="business.email" class="break-all">
                    {{ business.email }}
                </p>
            </div>
            <hr class="invoice-accent-border my-3 border-t border-dashed" />
            <h2
                class="invoice-accent text-sm font-bold tracking-[0.12em] uppercase"
            >
                {{ business.invoice_title || 'Sales receipt' }}
            </h2>
        </header>

        <div class="mt-3 space-y-1 border-b border-dashed pb-3 text-xs">
            <p class="invoice-detail-row">
                <span>Receipt</span><strong>{{ sale.sale_number }}</strong>
            </p>
            <p class="invoice-detail-row">
                <span>Date</span><span>{{ sale.posted_at }}</span>
            </p>
            <p class="invoice-detail-row">
                <span>Branch</span>
                <span class="text-right break-words">
                    {{ sale.location.name }} ({{ sale.location.code }})
                </span>
            </p>
            <p v-if="business.invoice_show_cashier" class="invoice-detail-row">
                <span>Cashier</span
                ><span class="text-right break-words">{{ sale.cashier }}</span>
            </p>
            <p v-if="business.invoice_show_customer" class="invoice-detail-row">
                <span>Customer</span>
                <span class="text-right break-words">
                    {{ sale.customer.name || 'Walk-in customer' }}
                    <template v-if="sale.customer.phone"
                        ><br />{{ sale.customer.phone }}</template
                    >
                    <template v-if="sale.customer.email"
                        ><br />{{ sale.customer.email }}</template
                    >
                </span>
            </p>
        </div>

        <div
            v-if="thermal"
            class="mt-4 divide-y divide-dashed border-b border-dashed text-xs"
        >
            <div
                v-for="line in sale.lines"
                :key="line.id"
                class="invoice-section space-y-1 py-2.5"
            >
                <p class="leading-snug font-semibold break-words">
                    {{ line.product_name
                    }}<template v-if="line.variant_name">
                        · {{ line.variant_name }}</template
                    >
                </p>
                <p
                    v-if="
                        line.returned_quantity &&
                        line.returned_quantity !== '0.000'
                    "
                    class="text-xs"
                >
                    Returned quantity: {{ line.returned_quantity }}
                </p>
                <p v-if="line.is_replacement" class="text-xs font-medium">
                    Warranty replacement
                </p>
                <p
                    v-if="business.invoice_show_product_codes"
                    class="text-[0.68rem] break-all text-slate-600"
                >
                    {{ line.sku
                    }}<span v-if="line.imei"> · IMEI {{ line.imei }}</span>
                </p>
                <div class="flex items-start justify-between gap-2">
                    <span class="text-slate-600"
                        >{{ line.quantity }} ×
                        {{ money(line.unit_price) }}</span
                    >
                    <strong class="shrink-0 tabular-nums">{{
                        money(line.total)
                    }}</strong>
                </div>
                <div
                    v-if="
                        business.invoice_show_line_discounts &&
                        line.discount_amount !== '0.00'
                    "
                    class="flex justify-between gap-2 text-slate-600"
                >
                    <span>Line discount</span>
                    <span class="shrink-0 text-right tabular-nums"
                        >−{{ money(line.discount_amount) }}</span
                    >
                </div>
                <div
                    v-for="component in line.components"
                    :key="component.name + (component.imei || '')"
                    class="flex justify-between gap-2 pl-2 text-[0.68rem] text-slate-600"
                >
                    <span class="break-words">
                        ↳ {{ component.name }}
                        <template
                            v-if="
                                business.invoice_show_product_codes &&
                                component.imei
                            "
                        >
                            · IMEI {{ component.imei }}</template
                        >
                    </span>
                    <span class="shrink-0"
                        >{{ component.quantity }} · Included</span
                    >
                </div>
            </div>
        </div>

        <div v-else class="mt-5 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="invoice-accent-border border-y text-left">
                        <th class="py-2">Item</th>
                        <th class="px-2 py-2 text-right">Qty</th>
                        <th class="px-2 py-2 text-right">Price</th>
                        <th class="py-2 text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="line in sale.lines" :key="line.id">
                        <tr class="invoice-section border-b align-top">
                            <td class="py-2">
                                <p
                                    v-if="
                                        line.returned_quantity &&
                                        line.returned_quantity !== '0.000'
                                    "
                                    class="text-xs"
                                >
                                    Returned quantity:
                                    {{ line.returned_quantity }}
                                </p>
                                <p
                                    v-if="line.is_replacement"
                                    class="text-xs font-medium"
                                >
                                    Warranty replacement
                                </p>
                                <div class="font-medium break-words">
                                    {{ line.product_name
                                    }}<template v-if="line.variant_name">
                                        · {{ line.variant_name }}</template
                                    >
                                </div>
                                <div
                                    v-if="business.invoice_show_product_codes"
                                    class="text-xs break-all text-slate-600"
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
                                    class="text-xs text-slate-600"
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
                            class="invoice-section border-b text-xs"
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
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div class="invoice-section mt-4 ml-auto max-w-xs space-y-1 text-sm">
            <div class="flex justify-between">
                <span>Subtotal</span><span>{{ money(sale.subtotal) }}</span>
            </div>
            <div
                v-if="sale.discount_total !== '0.00'"
                class="flex justify-between"
            >
                <span>Discount</span
                ><span>−{{ money(sale.discount_total) }}</span>
            </div>
            <div
                v-if="business.invoice_show_tax && sale.tax_total !== '0.00'"
                class="flex justify-between"
            >
                <span>Tax</span><span>{{ money(sale.tax_total) }}</span>
            </div>
            <div
                class="invoice-accent invoice-accent-border mt-2 flex justify-between border-y py-2 text-base font-bold"
            >
                <span>{{
                    sale.returned_total !== '0.00' ? 'Gross total' : 'Total'
                }}</span
                ><span>{{ money(sale.total) }}</span>
            </div>
            <div
                v-if="sale.returned_total !== '0.00'"
                class="flex justify-between font-semibold"
            >
                <span>Total after returns</span
                ><span>{{
                    moneyFromMinor(
                        minorUnits(sale.total) -
                            minorUnits(sale.returned_total),
                    )
                }}</span>
            </div>
            <div class="flex justify-between">
                <span>Paid</span><span>{{ money(sale.paid_total) }}</span>
            </div>
            <div
                v-if="outstandingBalance > 0n"
                class="flex justify-between font-semibold"
            >
                <span>Balance due</span
                ><span>{{ moneyFromMinor(outstandingBalance) }}</span>
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
                <span>Refunded</span><span>{{ money(sale.refund_total) }}</span>
            </div>
        </div>

        <div
            v-if="
                business.invoice_show_payment_details &&
                sale.payments.length > 0
            "
            class="invoice-section mt-4 border-t border-dashed pt-3 text-sm"
        >
            <h3 class="font-semibold">Payment method(s)</h3>
            <div v-for="(payment, index) in sale.payments" :key="index">
                <p class="flex justify-between gap-2">
                    <span class="break-words capitalize">
                        {{ payment.method.replaceAll('_', ' ')
                        }}<span v-if="payment.reference">
                            · {{ payment.reference }}</span
                        >
                    </span>
                    <span class="shrink-0 tabular-nums">{{
                        money(payment.amount)
                    }}</span>
                </p>
                <p
                    v-if="payment.method === 'cash' && payment.cash_tendered"
                    class="flex justify-between gap-2 text-xs"
                >
                    <span>Cash received</span
                    ><span>{{ money(payment.cash_tendered) }}</span>
                </p>
                <p
                    v-if="
                        payment.method === 'cash' &&
                        payment.change_given !== '0.00'
                    "
                    class="flex justify-between gap-2 text-xs"
                >
                    <span>Change</span
                    ><span>{{ money(payment.change_given) }}</span>
                </p>
            </div>
        </div>

        <section
            v-if="sale.returns?.length"
            class="mt-4 border-t border-dashed pt-3 text-xs"
        >
            <h3 class="font-semibold">Returns &amp; replacements</h3>
            <div
                v-for="item in sale.returns"
                :key="item.id"
                class="invoice-section mt-2 border-b border-dashed pb-2"
            >
                <p class="font-semibold">
                    {{ item.return_number }} ·
                    {{
                        item.is_exchange
                            ? 'Replacement issued'
                            : 'Return / Refund'
                    }}
                </p>
                <p>{{ item.processed_at }} · {{ item.reason }}</p>
                <p class="flex justify-between">
                    <span>Return credit</span
                    ><span>{{ money(item.total) }}</span>
                </p>
                <p class="flex justify-between">
                    <span>Refund issued</span
                    ><span>{{ money(item.refund_total) }}</span>
                </p>
            </div>
        </section>

        <footer
            class="invoice-section mt-5 border-t border-dashed pt-3 text-center text-xs"
        >
            <p class="whitespace-pre-line">
                {{ business.invoice_footer || 'Thank you for your purchase.' }}
            </p>
            <p
                v-if="business.invoice_return_policy"
                class="mt-1 whitespace-pre-line"
            >
                {{ business.invoice_return_policy }}
            </p>
            <p class="mt-3 text-[10px]">
                {{ business.name }} · {{ sale.sale_number }}
            </p>
        </footer>
    </article>
</template>

<style scoped>
.invoice-document {
    --invoice-accent: #1e293b;
}
.invoice-a4 {
    width: 100%;
    max-width: 186mm;
    page: invoice-a4;
}
.invoice-thermal {
    width: var(--receipt-width);
    max-width: 100%;
    padding-top: 4mm;
    padding-right: calc(
        (var(--receipt-width) - var(--receipt-content-width)) / 2
    );
    padding-bottom: 4mm;
    padding-left: calc(
        (var(--receipt-width) - var(--receipt-content-width)) / 2
    );
    page: invoice-roll;
}
.invoice-thermal-compact {
    padding-top: 3mm;
    padding-bottom: 3mm;
    font-size: 0.75rem;
}
.invoice-preview.invoice-a4 {
    max-width: 100%;
}
.invoice-preview.invoice-thermal {
    width: min(var(--receipt-width), 100%);
}
.invoice-accent {
    color: var(--invoice-accent);
}
.invoice-accent-border {
    border-color: var(--invoice-accent);
}
.invoice-detail-row {
    display: grid;
    grid-template-columns: minmax(3.5rem, auto) minmax(0, 1fr);
    gap: 0.5rem;
    align-items: start;
}
.invoice-section {
    break-inside: avoid;
    page-break-inside: avoid;
}
@page invoice-a4 {
    size: A4;
    margin: 12mm;
}
@page invoice-roll {
    size: auto;
    margin: 0;
}
@media print {
    .invoice-document {
        margin: 0 auto;
        color: #000;
        print-color-adjust: exact;
    }
    .invoice-a4 {
        width: 100%;
        max-width: none;
        padding: 0;
    }
    .invoice-thermal {
        width: var(--receipt-width);
        max-width: var(--receipt-width);
    }
}
</style>
