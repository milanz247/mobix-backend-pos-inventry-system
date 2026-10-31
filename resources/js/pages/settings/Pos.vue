<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { LayoutDashboard, MonitorCog, Printer } from '@lucide/vue';
import { reactive } from 'vue';
import PosSettingsController from '@/actions/App/Http/Controllers/Settings/PosSettingsController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/pos-settings';

type PosSettings = {
    show_sale_source: boolean;
    show_customer_name: boolean;
    show_customer_phone: boolean;
    show_customer_email: boolean;
    show_sale_notes: boolean;
    show_pay_exact: boolean;
    allow_price_override: boolean;
    require_imei_on_sale: boolean;
    show_date_time: boolean;
    show_sales_history: boolean;
    show_category_filter: boolean;
    show_brand_filter: boolean;
    show_location_filter: boolean;
    show_receipt_after_sale: boolean;
    auto_print_receipt: boolean;
    receipt_paper_size: 'a4' | 'thermal';
    receipt_thermal_width: 58 | 80;
    products_per_page: number;
};

const props = defineProps<{ settings: PosSettings; canUpdate: boolean }>();
const preferences = reactive({ ...props.settings });
const toggles = [
    {
        key: 'show_sale_source',
        name: 'pos_show_sale_source',
        title: 'Sale source',
        description: 'Show the source selector during checkout.',
    },
    {
        key: 'show_customer_name',
        name: 'pos_show_customer_name',
        title: 'Customer name',
        description: 'Show the customer name field during checkout.',
    },
    {
        key: 'show_customer_phone',
        name: 'pos_show_customer_phone',
        title: 'Customer phone',
        description: 'Show the customer phone field during checkout.',
    },
    {
        key: 'show_customer_email',
        name: 'pos_show_customer_email',
        title: 'Customer email',
        description: 'Show the customer email field during checkout.',
    },
    {
        key: 'show_sale_notes',
        name: 'pos_show_sale_notes',
        title: 'Sale notes',
        description: 'Show the notes field during checkout.',
    },
    {
        key: 'show_pay_exact',
        name: 'pos_show_pay_exact',
        title: 'Pay exact total',
        description: 'Show the shortcut that fills the exact cash total.',
    },
    {
        key: 'allow_price_override',
        name: 'pos_allow_price_override',
        title: 'Allow unit price editing',
        description:
            'Let permitted cashiers change a product price in the cart.',
    },
    {
        key: 'require_imei_on_sale',
        name: 'pos_require_imei_on_sale',
        title: 'Require IMEI when selling',
        description:
            'Ask the cashier to scan or enter an IMEI for serialized products. If unticked, the oldest available device is selected automatically.',
    },
    {
        key: 'show_date_time',
        name: 'pos_show_date_time',
        title: 'Date and time',
        description: 'Display the live clock in the POS header.',
    },
    {
        key: 'show_sales_history',
        name: 'pos_show_sales_history',
        title: 'Sales history',
        description: 'Show the quick link to previous sales.',
    },
    {
        key: 'show_category_filter',
        name: 'pos_show_category_filter',
        title: 'Category filter',
        description: 'Let cashiers narrow the product list by category.',
    },
    {
        key: 'show_brand_filter',
        name: 'pos_show_brand_filter',
        title: 'Brand filter',
        description: 'Let cashiers narrow the product list by brand.',
    },
    {
        key: 'show_location_filter',
        name: 'pos_show_location_filter',
        title: 'Location selector',
        description: 'Allow switching between accessible sales locations.',
    },
] as const;

defineOptions({
    layout: { breadcrumbs: [{ title: 'POS screen settings', href: edit() }] },
});
</script>

<template>
    <Head title="POS screen customize" />

    <div class="space-y-6">
        <Heading
            variant="small"
            title="POS screen settings"
            description="Choose which controls appear at checkout and how receipts print."
        />

        <Form
            v-bind="PosSettingsController.update.form()"
            :options="{ preserveScroll: true }"
            set-defaults-on-success
            class="space-y-4"
            v-slot="{ errors, processing, recentlySuccessful }"
        >
            <p
                v-if="!canUpdate"
                class="mb-4 rounded-lg border bg-muted/50 p-3 text-sm text-muted-foreground"
            >
                View only — this role cannot change these settings.
            </p>
            <fieldset :disabled="!canUpdate" class="contents">
                <section class="surface-panel space-y-4">
                    <div class="flex items-start gap-3">
                        <span
                            class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"
                        >
                            <MonitorCog class="size-5" />
                        </span>
                        <div>
                            <h2 class="font-semibold">Visible POS controls</h2>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Untick anything that is not needed at the
                                counter.
                            </p>
                        </div>
                    </div>

                    <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                        <label
                            v-for="toggle in toggles"
                            :key="toggle.key"
                            :for="toggle.name"
                            class="flex cursor-pointer items-start gap-3 rounded-xl border border-border/70 p-3.5 transition-colors hover:bg-muted/40"
                        >
                            <Checkbox
                                :id="toggle.name"
                                v-model="preferences[toggle.key]"
                                :name="toggle.name"
                                value="1"
                                class="mt-0.5"
                            />
                            <span>
                                <span class="block text-sm font-medium">{{
                                    toggle.title
                                }}</span>
                                <span
                                    class="mt-0.5 block text-xs leading-5 text-muted-foreground"
                                    >{{ toggle.description }}</span
                                >
                            </span>
                        </label>
                    </div>
                </section>

                <section class="surface-panel space-y-4">
                    <div class="flex items-start gap-3">
                        <span
                            class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"
                        >
                            <Printer class="size-5" />
                        </span>
                        <div>
                            <h2 class="font-semibold">Receipt after sale</h2>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Control the cashier prompt and the printer
                                format used immediately after checkout.
                            </p>
                        </div>
                    </div>

                    <div class="grid gap-3 lg:grid-cols-2">
                        <label
                            for="pos_show_receipt_after_sale"
                            class="flex cursor-pointer items-start gap-3 rounded-xl border border-border/70 p-3.5 transition-colors hover:bg-muted/40"
                        >
                            <Checkbox
                                id="pos_show_receipt_after_sale"
                                v-model="preferences.show_receipt_after_sale"
                                name="pos_show_receipt_after_sale"
                                value="1"
                                class="mt-0.5"
                            />
                            <span>
                                <span class="block text-sm font-medium"
                                    >Show sale-completed summary</span
                                >
                                <span
                                    class="mt-0.5 block text-xs leading-5 text-muted-foreground"
                                >
                                    Show totals, cash received, change, and the
                                    manual print button after checkout.
                                </span>
                            </span>
                        </label>

                        <label
                            for="pos_auto_print_receipt"
                            class="flex cursor-pointer items-start gap-3 rounded-xl border border-border/70 p-3.5 transition-colors hover:bg-muted/40"
                        >
                            <Checkbox
                                id="pos_auto_print_receipt"
                                v-model="preferences.auto_print_receipt"
                                name="pos_auto_print_receipt"
                                value="1"
                                class="mt-0.5"
                            />
                            <span>
                                <span class="block text-sm font-medium"
                                    >Prompt to print automatically</span
                                >
                                <span
                                    class="mt-0.5 block text-xs leading-5 text-muted-foreground"
                                >
                                    Open the browser print dialog as soon as a
                                    sale completes successfully.
                                </span>
                            </span>
                        </label>
                    </div>

                    <div
                        class="grid gap-4 border-t border-border/70 pt-5 sm:grid-cols-2"
                    >
                        <div class="grid gap-2">
                            <Label for="invoice_paper_size">Print format</Label>
                            <select
                                id="invoice_paper_size"
                                v-model="preferences.receipt_paper_size"
                                name="invoice_paper_size"
                                class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option value="a4">A4 invoice</option>
                                <option value="thermal">Thermal receipt</option>
                            </select>
                            <InputError :message="errors.invoice_paper_size" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="invoice_thermal_width"
                                >Thermal paper width</Label
                            >
                            <select
                                id="invoice_thermal_width"
                                v-model="preferences.receipt_thermal_width"
                                name="invoice_thermal_width"
                                :disabled="
                                    preferences.receipt_paper_size !== 'thermal'
                                "
                                class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <option :value="58">58 mm</option>
                                <option :value="80">80 mm</option>
                            </select>
                            <input
                                v-if="
                                    preferences.receipt_paper_size !== 'thermal'
                                "
                                type="hidden"
                                name="invoice_thermal_width"
                                :value="preferences.receipt_thermal_width"
                            />
                            <p class="text-xs text-muted-foreground">
                                Used only when the print format is Thermal
                                receipt.
                            </p>
                            <InputError
                                :message="errors.invoice_thermal_width"
                            />
                        </div>
                    </div>
                </section>

                <section class="surface-panel">
                    <div
                        class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="flex items-start gap-3">
                            <span
                                class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"
                            >
                                <LayoutDashboard class="size-5" />
                            </span>
                            <div>
                                <Label for="pos_products_per_page"
                                    >Products loaded per page</Label
                                >
                                <p class="mt-1 text-sm text-muted-foreground">
                                    More products reduce scrolling but take
                                    longer to load on slower devices.
                                </p>
                            </div>
                        </div>
                        <div class="w-full sm:w-48">
                            <select
                                id="pos_products_per_page"
                                v-model="preferences.products_per_page"
                                name="pos_products_per_page"
                                class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option :value="12">12 products</option>
                                <option :value="24">24 products</option>
                                <option :value="36">36 products</option>
                                <option :value="48">48 products</option>
                            </select>
                            <InputError
                                class="mt-1"
                                :message="errors.pos_products_per_page"
                            />
                        </div>
                    </div>
                </section>

                <div class="flex items-center justify-end gap-3">
                    <p
                        v-if="recentlySuccessful"
                        class="text-sm text-muted-foreground"
                    >
                        Saved.
                    </p>
                    <Button v-if="canUpdate" :disabled="processing"
                        >Save POS settings</Button
                    >
                </div>
            </fieldset>
        </Form>
    </div>
</template>
