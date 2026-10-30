<script setup lang="ts">
import { computed } from 'vue';
import type { InvoiceBusiness } from '@/types/invoice';
import type { ReturnReceipt } from '@/types/return-receipt';
import { damageMoney, damageQuantity } from '@/types/damage';
const props = defineProps<{
    receipt: ReturnReceipt;
    business: InvoiceBusiness;
}>();
const thermal = computed(() => props.business.invoice_paper_size === 'thermal');
const documentStyle = computed(() => ({
    '--receipt-width': `${props.business.invoice_thermal_width}mm`,
    '--content-width':
        props.business.invoice_thermal_width === 58 ? '48mm' : '72mm',
}));
</script>
<template>
    <article
        class="return-document mx-auto bg-white p-6 text-slate-950 shadow-sm"
        :class="thermal ? 'return-thermal' : 'return-a4'"
        :style="documentStyle"
    >
        <header class="border-b border-dashed pb-4 text-center">
            <img
                v-if="business.invoice_show_logo && business.logo_url"
                :src="business.logo_url"
                alt="Business logo"
                class="mx-auto mb-2 max-h-16 max-w-40"
            />
            <h1 class="text-lg font-bold">{{ business.name }}</h1>
            <template v-if="business.invoice_show_business_details"
                ><p class="text-xs">
                    {{ business.address }} · {{ business.city }}
                </p>
                <p class="text-xs">{{ business.phone }}</p></template
            >
            <h2 class="mt-3 font-bold uppercase">
                {{
                    receipt.is_exchange
                        ? 'Replacement receipt'
                        : 'Return receipt'
                }}
            </h2>
            <p class="font-mono text-sm">{{ receipt.return_number }}</p>
        </header>
        <dl class="my-4 space-y-1 text-xs">
            <div>
                <dt class="inline font-semibold">Original invoice:</dt>
                <dd class="inline">{{ receipt.sale_number }}</dd>
            </div>
            <div>
                <dt class="inline font-semibold">Date:</dt>
                <dd class="inline">{{ receipt.date }}</dd>
            </div>
            <div>
                <dt class="inline font-semibold">Location:</dt>
                <dd class="inline">{{ receipt.location }}</dd>
            </div>
            <div v-if="business.invoice_show_customer">
                <dt class="inline font-semibold">Customer:</dt>
                <dd class="inline">
                    {{ receipt.customer.name
                    }}<span v-if="receipt.customer.phone">
                        · {{ receipt.customer.phone }}</span
                    >
                </dd>
            </div>
            <div v-if="business.invoice_show_cashier">
                <dt class="inline font-semibold">Processed by:</dt>
                <dd class="inline">{{ receipt.processed_by }}</dd>
            </div>
            <div>
                <dt class="inline font-semibold">Reason:</dt>
                <dd class="inline">{{ receipt.reason }}</dd>
            </div>
        </dl>
        <h3 class="border-b py-2 text-sm font-bold">Items returned</h3>
        <section
            v-for="line in receipt.lines"
            :key="line.id"
            class="return-section border-b border-dashed py-3 text-sm"
        >
            <p class="font-semibold">{{ line.product }} · {{ line.variant }}</p>
            <p class="text-xs">
                SKU {{ line.sku
                }}<span v-if="line.model_number">
                    · Model {{ line.model_number }}</span
                >
            </p>
            <p v-if="line.imei" class="text-xs">IMEI {{ line.imei }}</p>
            <div class="mt-1 flex justify-between gap-3">
                <span>Qty {{ damageQuantity(line.quantity) }}</span
                ><span class="font-semibold">{{
                    damageMoney(line.total, business.currency)
                }}</span>
            </div>
            <p class="mt-1 text-xs">{{ line.condition }}</p>
        </section>
        <template v-if="receipt.replacements.length"
            ><h3 class="mt-4 border-b py-2 text-sm font-bold">
                Replacement items issued
            </h3>
            <section
                v-for="line in receipt.replacements"
                :key="line.id"
                class="return-section border-b border-dashed py-3 text-sm"
            >
                <p class="font-semibold">
                    {{ line.product }} · {{ line.variant }}
                </p>
                <p class="text-xs">
                    SKU {{ line.sku
                    }}<span v-if="line.model_number">
                        · Model {{ line.model_number }}</span
                    >
                </p>
                <p v-if="line.imei" class="text-xs">
                    Replacement IMEI {{ line.imei }}
                </p>
                <div class="mt-1 flex justify-between gap-3">
                    <span>Qty {{ damageQuantity(line.quantity) }}</span
                    ><span>{{
                        damageMoney(line.total, business.currency)
                    }}</span>
                </div>
            </section></template
        >
        <section class="return-section mt-4 space-y-2 text-sm">
            <div class="flex justify-between">
                <span>Return credit</span
                ><strong>{{
                    damageMoney(receipt.total, business.currency)
                }}</strong>
            </div>
            <div v-if="receipt.is_exchange" class="flex justify-between">
                <span>Replacement value</span
                ><span>{{
                    damageMoney(receipt.total, business.currency)
                }}</span>
            </div>
            <div class="flex justify-between">
                <span>Refund issued</span
                ><strong>{{
                    damageMoney(receipt.refund_total, business.currency)
                }}</strong>
            </div>
            <p v-if="receipt.is_exchange" class="text-xs">
                Same-item replacement. No cash refund. Previous payments and the
                invoice balance are unchanged.
            </p>
            <div
                v-for="(refund, index) in receipt.refunds"
                :key="index"
                class="flex justify-between text-xs"
            >
                <span class="capitalize"
                    >{{ refund.method.replaceAll('_', ' ')
                    }}<span v-if="refund.reference">
                        · {{ refund.reference }}</span
                    ></span
                ><span>{{
                    damageMoney(refund.amount, business.currency)
                }}</span>
            </div>
            <div class="flex justify-between border-t pt-2">
                <span>{{
                    receipt.balance_is_snapshot
                        ? 'Invoice value after return'
                        : 'Current invoice value'
                }}</span
                ><span>{{
                    damageMoney(receipt.net_total, business.currency)
                }}</span>
            </div>
            <div class="flex justify-between">
                <span>Net payments</span
                ><span>{{
                    damageMoney(receipt.net_paid, business.currency)
                }}</span>
            </div>
            <div class="flex justify-between border-t pt-2 font-bold">
                <span>{{
                    receipt.balance_is_snapshot
                        ? 'Balance after return'
                        : 'Current balance'
                }}</span
                ><span>{{
                    damageMoney(receipt.balance, business.currency)
                }}</span>
            </div>
        </section>
        <p
            v-if="receipt.notes"
            class="return-section mt-4 text-xs whitespace-pre-line"
        >
            {{ receipt.notes }}
        </p>
        <footer
            class="return-section mt-5 border-t border-dashed pt-3 text-center text-xs"
        >
            <p class="whitespace-pre-line">
                {{ business.invoice_footer || 'Please retain this receipt.' }}
            </p>
            <p class="mt-2 whitespace-pre-line">
                {{ business.invoice_return_policy }}
            </p>
            <p class="mt-3">
                {{ receipt.return_number }} · {{ receipt.sale_number }}
            </p>
        </footer>
    </article>
</template>
<style scoped>
.return-a4 {
    width: 100%;
    max-width: 186mm;
    page: return-a4;
}
.return-thermal {
    width: var(--receipt-width);
    max-width: 100%;
    padding: 4mm calc((var(--receipt-width) - var(--content-width)) / 2);
    page: return-roll;
}
.return-thermal :deep(p),
.return-thermal :deep(dd) {
    overflow-wrap: anywhere;
}
.return-document dt {
    margin-right: 0.25rem;
}
.return-document .flex > :last-child {
    flex-shrink: 0;
    white-space: nowrap;
}
.return-thermal .text-sm {
    font-size: 0.75rem;
    line-height: 1.4;
}
.return-thermal .text-xs {
    font-size: 0.65rem;
    line-height: 1.4;
}
.return-section {
    break-inside: avoid;
}
@page return-a4 {
    size: A4;
    margin: 12mm;
}
@page return-roll {
    size: auto;
    margin: 0;
}
@media print {
    .return-document {
        margin: 0 auto;
        box-shadow: none;
        color: #000;
    }
    .return-a4 {
        max-width: none;
        padding: 0;
    }
    .return-thermal {
        max-width: var(--receipt-width);
    }
}
</style>
