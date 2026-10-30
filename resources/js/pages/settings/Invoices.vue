<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Eye, FileText, Printer } from '@lucide/vue';
import { computed, ref } from 'vue';
import InvoiceSettingsController from '@/actions/App/Http/Controllers/Settings/InvoiceSettingsController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import InvoiceDocument from '@/components/sales/InvoiceDocument.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/invoice-settings';
import type { InvoiceBusiness, InvoiceSale } from '@/types/invoice';

const props = defineProps<{
    canUpdate: boolean;
    invoice: {
        footer: string | null;
        return_policy: string | null;
        title: string;
        paper_size: 'a4' | 'thermal';
        thermal_width: 58 | 80;
        accent_color: string;
        show_logo: boolean;
        show_business_details: boolean;
        show_customer: boolean;
        show_cashier: boolean;
        show_payment_details: boolean;
        show_tax: boolean;
        show_product_codes: boolean;
        show_line_discounts: boolean;
    };
    business: InvoiceBusiness;
}>();

const title = ref(props.invoice.title);
const paperSize = ref(props.invoice.paper_size);
const thermalWidth = ref(props.invoice.thermal_width);
const accentColor = ref(props.invoice.accent_color);
const footerMessage = ref(props.invoice.footer ?? '');
const returnPolicy = ref(props.invoice.return_policy ?? '');
const showLogo = ref(props.invoice.show_logo);
const showBusinessDetails = ref(props.invoice.show_business_details);
const showCustomer = ref(props.invoice.show_customer);
const showCashier = ref(props.invoice.show_cashier);
const showPaymentDetails = ref(props.invoice.show_payment_details);
const showTax = ref(props.invoice.show_tax);
const showProductCodes = ref(props.invoice.show_product_codes);
const showLineDiscounts = ref(props.invoice.show_line_discounts);

const previewBusiness = computed<InvoiceBusiness>(() => ({
    ...props.business,
    invoice_footer: footerMessage.value || null,
    invoice_return_policy: returnPolicy.value || null,
    invoice_title: title.value,
    invoice_paper_size: paperSize.value,
    invoice_thermal_width: thermalWidth.value,
    invoice_accent_color: accentColor.value,
    invoice_show_logo: showLogo.value,
    invoice_show_business_details: showBusinessDetails.value,
    invoice_show_customer: showCustomer.value,
    invoice_show_cashier: showCashier.value,
    invoice_show_payment_details: showPaymentDetails.value,
    invoice_show_tax: showTax.value,
    invoice_show_product_codes: showProductCodes.value,
    invoice_show_line_discounts: showLineDiscounts.value,
}));

const previewSale: InvoiceSale = {
    id: 0,
    sale_number: 'PREVIEW-000123',
    posted_at: '2026-10-16 14:30:00',
    cashier: 'Staff member',
    location: { name: 'Main Shop', code: 'MAIN' },
    customer: {
        name: 'Walk-in customer',
        phone: null,
        email: null,
    },
    subtotal: '5000.00',
    discount_total: '500.00',
    tax_total: '90.00',
    total: '4590.00',
    paid_total: '4590.00',
    returned_total: '0.00',
    refund_total: '0.00',
    lines: [
        {
            id: 1,
            product_name: 'USB-C Fast Charger with Extra-Long Product Name',
            variant_name: '33W · New',
            sku: 'DEMO-CHG-33W',
            quantity: '1.000',
            unit_price: '5000.00',
            discount_amount: '500.00',
            total: '4500.00',
            imei: '352099001761481',
            is_combo: false,
            components: [],
        },
    ],
    payments: [
        {
            amount: '4590.00',
            cash_tendered: '5000.00',
            change_given: '410.00',
            method: 'cash',
            reference: null,
        },
    ],
};

const layoutOptions = [
    { value: 'a4', label: 'A4 invoice', description: 'Full-page print layout' },
    {
        value: 'thermal',
        label: 'Thermal receipt',
        description: 'Narrow counter-printer layout',
    },
] as const;

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Invoice settings', href: edit() }],
    },
});
</script>

<template>
    <Head title="Invoice designer" />

    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <Heading
                variant="small"
                title="Invoice designer"
                description="Shape the look and content of every customer receipt. Changes appear in the live preview."
            />
            <span
                class="inline-flex items-center gap-2 rounded-full border border-border/70 bg-card px-3 py-1.5 text-xs font-medium text-muted-foreground"
            >
                <span class="size-2 rounded-full bg-emerald-500" /> Saved
                template
            </span>
        </div>

        <Form
            v-bind="InvoiceSettingsController.update.form()"
            :options="{ preserveScroll: true }"
            set-defaults-on-success
            class="grid items-start gap-5 2xl:grid-cols-[minmax(0,1fr)_360px]"
            v-slot="{ errors, processing, recentlySuccessful }"
        >
            <p
                v-if="!canUpdate"
                class="mb-4 rounded-lg border bg-muted/50 p-3 text-sm text-muted-foreground"
            >
                View only — this role cannot change these settings.
            </p>
            <fieldset :disabled="!canUpdate" class="contents">
                <div class="space-y-5">
                    <section class="surface-panel space-y-5 p-5 sm:p-6">
                        <div class="flex items-start gap-3">
                            <span
                                class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"
                                ><FileText class="size-5"
                            /></span>
                            <div>
                                <h2 class="font-semibold">Page identity</h2>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    Choose the heading, page format, and brand
                                    color.
                                </p>
                            </div>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="invoice_title"
                                    >Document title</Label
                                >
                                <Input
                                    id="invoice_title"
                                    v-model="title"
                                    name="invoice_title"
                                    maxlength="100"
                                    placeholder="Sales receipt"
                                />
                                <InputError :message="errors.invoice_title" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="invoice_paper_size"
                                    >Default print format</Label
                                >
                                <select
                                    id="invoice_paper_size"
                                    v-model="paperSize"
                                    name="invoice_paper_size"
                                    class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                                >
                                    <option
                                        v-for="layout in layoutOptions"
                                        :key="layout.value"
                                        :value="layout.value"
                                    >
                                        {{ layout.label }} ·
                                        {{ layout.description }}
                                    </option>
                                </select>
                                <InputError
                                    :message="errors.invoice_paper_size"
                                />
                            </div>
                            <div
                                v-if="paperSize === 'thermal'"
                                class="grid gap-2"
                            >
                                <Label for="invoice_thermal_width"
                                    >Thermal paper width</Label
                                >
                                <select
                                    id="invoice_thermal_width"
                                    v-model="thermalWidth"
                                    name="invoice_thermal_width"
                                    class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                                >
                                    <option :value="58">58 mm compact</option>
                                    <option :value="80">80 mm standard</option>
                                </select>
                                <InputError
                                    :message="errors.invoice_thermal_width"
                                />
                            </div>
                            <input
                                v-else
                                type="hidden"
                                name="invoice_thermal_width"
                                :value="thermalWidth"
                            />
                            <div class="grid gap-2 sm:col-span-2">
                                <Label for="invoice_accent_color"
                                    >Brand accent</Label
                                >
                                <div class="flex items-center gap-3">
                                    <Input
                                        id="invoice_accent_color"
                                        v-model="accentColor"
                                        name="invoice_accent_color"
                                        type="color"
                                        class="h-11 w-16 cursor-pointer p-1"
                                    />
                                    <span
                                        class="font-mono text-sm text-muted-foreground uppercase"
                                        >{{ accentColor }}</span
                                    >
                                </div>
                                <InputError
                                    :message="errors.invoice_accent_color"
                                />
                            </div>
                        </div>
                    </section>

                    <section class="surface-panel space-y-4 p-5 sm:p-6">
                        <div>
                            <h2 class="font-semibold">
                                What appears on the invoice
                            </h2>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Keep the printed copy focused on the details
                                your customers need.
                            </p>
                        </div>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <label
                                for="invoice_show_logo"
                                class="flex cursor-pointer items-center gap-3 rounded-xl border border-border/70 p-3.5 transition-colors hover:bg-muted/40"
                            >
                                <Checkbox
                                    id="invoice_show_logo"
                                    v-model:checked="showLogo"
                                    name="invoice_show_logo"
                                    value="1"
                                />
                                <span
                                    ><span class="block text-sm font-medium"
                                        >Business logo</span
                                    ><span class="text-xs text-muted-foreground"
                                        >Use the logo from Business
                                        settings</span
                                    ></span
                                >
                            </label>
                            <label
                                for="invoice_show_business_details"
                                class="flex cursor-pointer items-center gap-3 rounded-xl border border-border/70 p-3.5 transition-colors hover:bg-muted/40"
                            >
                                <Checkbox
                                    id="invoice_show_business_details"
                                    v-model:checked="showBusinessDetails"
                                    name="invoice_show_business_details"
                                    value="1"
                                />
                                <span
                                    ><span class="block text-sm font-medium"
                                        >Contact details</span
                                    ><span class="text-xs text-muted-foreground"
                                        >Address, phone, and email</span
                                    ></span
                                >
                            </label>
                            <label
                                for="invoice_show_customer"
                                class="flex cursor-pointer items-center gap-3 rounded-xl border border-border/70 p-3.5 transition-colors hover:bg-muted/40"
                            >
                                <Checkbox
                                    id="invoice_show_customer"
                                    v-model:checked="showCustomer"
                                    name="invoice_show_customer"
                                    value="1"
                                />
                                <span
                                    ><span class="block text-sm font-medium"
                                        >Customer information</span
                                    ><span class="text-xs text-muted-foreground"
                                        >Name and contact number</span
                                    ></span
                                >
                            </label>
                            <label
                                for="invoice_show_cashier"
                                class="flex cursor-pointer items-center gap-3 rounded-xl border border-border/70 p-3.5 transition-colors hover:bg-muted/40"
                            >
                                <Checkbox
                                    id="invoice_show_cashier"
                                    v-model:checked="showCashier"
                                    name="invoice_show_cashier"
                                    value="1"
                                />
                                <span
                                    ><span class="block text-sm font-medium"
                                        >Cashier name</span
                                    ><span class="text-xs text-muted-foreground"
                                        >Show which team member completed the
                                        sale</span
                                    ></span
                                >
                            </label>
                            <label
                                for="invoice_show_payment_details"
                                class="flex cursor-pointer items-center gap-3 rounded-xl border border-border/70 p-3.5 transition-colors hover:bg-muted/40"
                            >
                                <Checkbox
                                    id="invoice_show_payment_details"
                                    v-model:checked="showPaymentDetails"
                                    name="invoice_show_payment_details"
                                    value="1"
                                />
                                <span
                                    ><span class="block text-sm font-medium"
                                        >Payment details</span
                                    ><span class="text-xs text-muted-foreground"
                                        >Payment method and amount</span
                                    ></span
                                >
                            </label>
                            <label
                                for="invoice_show_tax"
                                class="flex cursor-pointer items-center gap-3 rounded-xl border border-border/70 p-3.5 transition-colors hover:bg-muted/40"
                            >
                                <Checkbox
                                    id="invoice_show_tax"
                                    v-model:checked="showTax"
                                    name="invoice_show_tax"
                                    value="1"
                                />
                                <span
                                    ><span class="block text-sm font-medium"
                                        >Tax row</span
                                    ><span class="text-xs text-muted-foreground"
                                        >Show the tax total</span
                                    ></span
                                >
                            </label>
                            <label
                                for="invoice_show_product_codes"
                                class="flex cursor-pointer items-center gap-3 rounded-xl border border-border/70 p-3.5 transition-colors hover:bg-muted/40"
                            >
                                <Checkbox
                                    id="invoice_show_product_codes"
                                    v-model:checked="showProductCodes"
                                    name="invoice_show_product_codes"
                                    value="1"
                                />
                                <span
                                    ><span class="block text-sm font-medium"
                                        >SKU and IMEI</span
                                    ><span class="text-xs text-muted-foreground"
                                        >Print product codes and device
                                        identifiers</span
                                    ></span
                                >
                            </label>
                            <label
                                for="invoice_show_line_discounts"
                                class="flex cursor-pointer items-center gap-3 rounded-xl border border-border/70 p-3.5 transition-colors hover:bg-muted/40"
                            >
                                <Checkbox
                                    id="invoice_show_line_discounts"
                                    v-model:checked="showLineDiscounts"
                                    name="invoice_show_line_discounts"
                                    value="1"
                                />
                                <span
                                    ><span class="block text-sm font-medium"
                                        >Line discounts</span
                                    ><span class="text-xs text-muted-foreground"
                                        >Show each product discount below its
                                        item</span
                                    ></span
                                >
                            </label>
                        </div>
                    </section>

                    <section class="surface-panel space-y-5 p-5 sm:p-6">
                        <div>
                            <h2 class="font-semibold">Messages and policies</h2>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Add your own customer-facing wording to the
                                bottom of each printout.
                            </p>
                        </div>
                        <div class="grid gap-4">
                            <div class="grid gap-2">
                                <Label for="invoice_footer"
                                    >Thank-you message</Label
                                >
                                <textarea
                                    id="invoice_footer"
                                    v-model="footerMessage"
                                    name="invoice_footer"
                                    rows="3"
                                    maxlength="2000"
                                    placeholder="Thank you for your purchase."
                                    class="w-full resize-y"
                                />
                                <p class="text-xs text-muted-foreground">
                                    Leave blank to use the standard thank-you
                                    message.
                                </p>
                                <InputError :message="errors.invoice_footer" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="invoice_return_policy"
                                    >Return and service policy</Label
                                >
                                <textarea
                                    id="invoice_return_policy"
                                    v-model="returnPolicy"
                                    name="invoice_return_policy"
                                    rows="3"
                                    maxlength="2000"
                                    placeholder="Keep this receipt for service and return reference."
                                    class="w-full resize-y"
                                />
                                <InputError
                                    :message="errors.invoice_return_policy"
                                />
                            </div>
                        </div>
                    </section>

                    <div
                        class="flex flex-wrap items-center gap-4 border-t border-border/70 pt-5"
                    >
                        <Button v-if="canUpdate" :disabled="processing"
                            ><Printer class="size-4" />{{
                                processing ? 'Saving…' : 'Save invoice design'
                            }}</Button
                        >
                        <p
                            v-if="recentlySuccessful"
                            role="status"
                            class="text-sm text-emerald-700 dark:text-emerald-400"
                        >
                            Invoice design saved.
                        </p>
                    </div>
                </div>

                <aside class="space-y-3 2xl:sticky 2xl:top-20">
                    <div class="flex items-center gap-2 text-sm font-semibold">
                        <Eye class="size-4 text-primary" /> Live preview
                    </div>
                    <div
                        class="max-h-[calc(100vh-10rem)] overflow-auto rounded-2xl border border-border/70 bg-muted/30 p-4 sm:p-5"
                    >
                        <div
                            class="mb-3 rounded-md bg-amber-100 px-2 py-1 text-center text-[10px] font-semibold tracking-wide text-amber-900 uppercase"
                        >
                            Preview data — not a completed sale
                        </div>
                        <InvoiceDocument
                            :sale="previewSale"
                            :business="previewBusiness"
                            preview
                        />
                    </div>
                    <p class="text-xs leading-5 text-muted-foreground">
                        Preview uses sample sale data. Your saved template will
                        be applied to printed sale receipts.
                    </p>
                </aside>
            </fieldset>
        </Form>
    </div>
</template>
