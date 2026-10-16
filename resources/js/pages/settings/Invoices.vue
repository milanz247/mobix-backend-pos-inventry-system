<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { Eye, FileText, Printer } from '@lucide/vue';
import { ref } from 'vue';
import InvoiceSettingsController from '@/actions/App/Http/Controllers/Settings/InvoiceSettingsController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/invoice-settings';

const props = defineProps<{
    invoice: {
        footer: string | null;
        return_policy: string | null;
        title: string;
        paper_size: 'a4' | 'thermal';
        accent_color: string;
        show_logo: boolean;
        show_business_details: boolean;
        show_customer: boolean;
        show_cashier: boolean;
        show_payment_details: boolean;
        show_tax: boolean;
    };
}>();

const page = usePage();
const businessName =
    (page.props.businessPreferences as { name?: string } | null)?.name ??
    page.props.name;
const title = ref(props.invoice.title);
const paperSize = ref(props.invoice.paper_size);
const accentColor = ref(props.invoice.accent_color);
const footerMessage = ref(props.invoice.footer ?? '');
const returnPolicy = ref(props.invoice.return_policy ?? '');
const showLogo = ref(props.invoice.show_logo);
const showBusinessDetails = ref(props.invoice.show_business_details);
const showCustomer = ref(props.invoice.show_customer);
const showCashier = ref(props.invoice.show_cashier);
const showPaymentDetails = ref(props.invoice.show_payment_details);
const showTax = ref(props.invoice.show_tax);

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
                            <Label for="invoice_title">Document title</Label>
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
                            <InputError :message="errors.invoice_paper_size" />
                        </div>
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
                            Keep the printed copy focused on the details your
                            customers need.
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
                                    >Use the logo from Business settings</span
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
                    </div>
                </section>

                <section class="surface-panel space-y-5 p-5 sm:p-6">
                    <div>
                        <h2 class="font-semibold">Messages and policies</h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Add your own customer-facing wording to the bottom
                            of each printout.
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
                    <Button :disabled="processing"
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
                    class="rounded-2xl border border-border/70 bg-muted/30 p-4 sm:p-5"
                >
                    <article
                        :class="
                            paperSize === 'thermal'
                                ? 'mx-auto max-w-[260px]'
                                : 'mx-auto max-w-sm'
                        "
                        class="space-y-4 rounded-xl border bg-white p-5 text-slate-800 shadow-sm transition-all"
                        :style="{
                            borderTopColor: accentColor,
                            borderTopWidth: '4px',
                        }"
                    >
                        <header class="space-y-1 text-center">
                            <div
                                v-if="showLogo"
                                class="mx-auto mb-2 flex size-10 items-center justify-center rounded-lg bg-slate-100 text-[10px] font-bold text-slate-400"
                            >
                                LOGO
                            </div>
                            <p
                                class="text-base font-bold"
                                :style="{ color: accentColor }"
                            >
                                {{ businessName }}
                            </p>
                            <div
                                v-if="showBusinessDetails"
                                class="text-[10px] leading-4 text-slate-500"
                            >
                                12 Main Street · Colombo<br />+94 11 234 5678 ·
                                hello@example.com
                            </div>
                            <div class="my-3 border-t border-dashed" />
                            <p class="font-semibold">
                                {{ title || 'Sales receipt' }}
                            </p>
                            <p class="text-[10px] text-slate-500">
                                SAL-2026-0000123 · 16 Oct 2026
                            </p>
                        </header>
                        <div
                            class="space-y-2 border-y border-slate-200 py-3 text-[11px]"
                        >
                            <div class="flex justify-between font-medium">
                                <span>USB-C Fast Charger</span
                                ><span>LKR 4,500.00</span>
                            </div>
                            <p class="text-slate-500">1 × LKR 4,500.00</p>
                        </div>
                        <div class="space-y-1 text-xs">
                            <p class="flex justify-between">
                                <span>Subtotal</span><span>LKR 4,500.00</span>
                            </p>
                            <p
                                v-if="showTax"
                                class="flex justify-between text-slate-500"
                            >
                                <span>Tax</span><span>LKR 0.00</span>
                            </p>
                            <p
                                class="flex justify-between border-t border-slate-200 pt-2 text-sm font-bold"
                                :style="{ color: accentColor }"
                            >
                                <span>Total</span><span>LKR 4,500.00</span>
                            </p>
                        </div>
                        <div
                            v-if="showCustomer || showCashier"
                            class="space-y-1 border-t border-dashed pt-3 text-[10px] text-slate-500"
                        >
                            <p v-if="showCustomer">
                                Customer · Walk-in customer
                            </p>
                            <p v-if="showCashier">
                                Location · Main Shop
                                <span class="float-right"
                                    >Cashier · Staff member</span
                                >
                            </p>
                        </div>
                        <p
                            v-if="showPaymentDetails"
                            class="text-[10px] text-slate-500"
                        >
                            Payment · Cash
                            <span class="float-right">LKR 4,500.00</span>
                        </p>
                        <footer
                            class="border-t border-dashed pt-3 text-center text-[10px] text-slate-500"
                        >
                            <p>
                                {{
                                    footerMessage ||
                                    'Thank you for your purchase.'
                                }}
                            </p>
                            <p class="mt-1">
                                {{
                                    returnPolicy ||
                                    'Keep this receipt for service and return reference.'
                                }}
                            </p>
                        </footer>
                    </article>
                </div>
                <p class="text-xs leading-5 text-muted-foreground">
                    Preview uses sample sale data. Your saved template will be
                    applied to printed sale receipts.
                </p>
            </aside>
        </Form>
    </div>
</template>
