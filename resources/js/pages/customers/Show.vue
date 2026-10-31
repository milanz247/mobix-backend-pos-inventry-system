<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import ShiftPagination from '@/components/shifts/ShiftPagination.vue';
import CustomerFormDialog from '@/components/sales/CustomerFormDialog.vue';
import type { Customer } from '@/types/customer';
type Links = { url: string | null; label: string; active: boolean }[];
type Bill = {
    id: number;
    sale_number: string;
    posted_at: string;
    due_date: string | null;
    total: string;
    paid_total: string;
    balance_due: string;
    location: { name: string };
};
type Purchase = {
    id: number;
    invoice_id: number;
    invoice: string;
    date: string;
    branch: string;
    product: string;
    variant: string;
    sku: string;
    imei: string | null;
    quantity: string;
    returned: string;
    total: string;
};
const props = defineProps<{
    customer: Customer;
    currency: string;
    canUpdate: boolean;
    canViewSales: boolean;
    outstanding: string;
    aging: Record<string, string>;
    view: 'purchases' | 'credit';
    purchases: { data: Purchase[]; links: Links };
    sales: { data: Bill[]; links: Links };
}>();
const editOpen = ref(false);
const money = (value: string) =>
    `${props.currency} ${Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const agingLabels: Record<string, string> = {
    not_due: 'Not due / undated',
    '1_30': '1–30 days overdue',
    '31_60': '31–60 days overdue',
    '61_90': '61–90 days overdue',
    over_90: 'Over 90 days overdue',
};
defineOptions({
    layout: { breadcrumbs: [{ title: 'Customers', href: '/customers' }] },
});
</script>
<template>
    <Head :title="customer.name" />
    <section class="page-shell">
        <div class="flex flex-wrap justify-between gap-3">
            <Heading
                :title="customer.name"
                description="Customer details, purchased items and credit invoices."
            />
            <div class="flex gap-2">
                <Button as-child variant="outline"
                    ><Link href="/customers">Back to customers</Link></Button
                ><Button v-if="canUpdate" @click="editOpen = true"
                    >Edit customer</Button
                >
            </div>
        </div>
        <div class="surface-panel grid gap-5 p-5 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <p class="text-xs text-muted-foreground">Contact</p>
                <p class="font-semibold">{{ customer.phone }}</p>
                <p class="text-sm break-all">
                    {{ customer.email || 'No email' }}
                </p>
            </div>
            <div>
                <p class="text-xs text-muted-foreground">Credit limit</p>
                <p class="font-semibold">
                    {{
                        customer.credit_limit === null
                            ? 'Not configured'
                            : money(customer.credit_limit)
                    }}
                </p>
                <p class="text-sm">
                    {{ customer.credit_days }} day payment terms
                </p>
            </div>
            <div>
                <p class="text-xs text-muted-foreground">
                    Outstanding in accessible branches
                </p>
                <p class="text-xl font-semibold">{{ money(outstanding) }}</p>
            </div>
            <div class="text-sm">
                <p
                    v-for="(amount, key) in aging"
                    :key="key"
                    class="flex justify-between gap-2"
                >
                    <span class="text-muted-foreground">{{
                        agingLabels[key]
                    }}</span
                    ><span>{{ money(amount) }}</span>
                </p>
            </div>
        </div>
        <nav class="flex gap-2 border-b pb-3" aria-label="Customer history">
            <Button
                as-child
                :variant="view === 'purchases' ? 'default' : 'outline'"
                ><Link :href="`/customers/${customer.id}?view=purchases`"
                    >View purchases</Link
                ></Button
            ><Button
                as-child
                :variant="view === 'credit' ? 'default' : 'outline'"
                ><Link :href="`/customers/${customer.id}?view=credit`"
                    >Credit bills</Link
                ></Button
            >
        </nav>
        <template v-if="view === 'purchases'"
            ><div class="table-panel">
                <table class="data-table responsive-table">
                    <thead>
                        <tr>
                            <th>Purchased item</th>
                            <th>Invoice / branch</th>
                            <th>Quantity</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in purchases.data" :key="item.id">
                            <td data-label="Item">
                                <div>
                                    <p class="font-semibold">
                                        {{ item.product }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ item.variant }} · {{ item.sku }}
                                    </p>
                                    <p v-if="item.imei" class="text-xs">
                                        IMEI {{ item.imei }}
                                    </p>
                                </div>
                            </td>
                            <td data-label="Invoice">
                                <div>
                                    <Link
                                        v-if="canViewSales"
                                        :href="`/sales/${item.invoice_id}`"
                                        class="text-primary"
                                        >{{ item.invoice }}</Link
                                    ><span v-else>{{ item.invoice }}</span>
                                    <p class="text-xs text-muted-foreground">
                                        {{ item.date.slice(0, 10) }} ·
                                        {{ item.branch }}
                                    </p>
                                </div>
                            </td>
                            <td data-label="Quantity">
                                <div>
                                    {{ item.quantity }}
                                    <p
                                        v-if="Number(item.returned) > 0"
                                        class="text-xs text-muted-foreground"
                                    >
                                        Returned: {{ item.returned }}
                                    </p>
                                </div>
                            </td>
                            <td data-label="Total">{{ money(item.total) }}</td>
                        </tr>
                        <tr v-if="!purchases.data.length">
                            <td
                                colspan="4"
                                class="py-10 text-center text-muted-foreground"
                            >
                                No purchased items in your accessible branches.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <ShiftPagination :links="purchases.links"
        /></template>
        <template v-else
            ><div class="table-panel">
                <table class="data-table responsive-table">
                    <thead>
                        <tr>
                            <th>Credit invoice</th>
                            <th>Branch / due date</th>
                            <th>Invoice total</th>
                            <th>Paid</th>
                            <th>Balance due</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="bill in sales.data" :key="bill.id">
                            <td data-label="Invoice">
                                <div>
                                    <p class="font-semibold">
                                        {{ bill.sale_number }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ bill.posted_at.slice(0, 10) }}
                                    </p>
                                </div>
                            </td>
                            <td data-label="Due">
                                <div>
                                    {{ bill.location.name }}
                                    <p class="text-xs text-muted-foreground">
                                        {{
                                            bill.due_date?.slice(0, 10) ||
                                            'No due date'
                                        }}
                                    </p>
                                </div>
                            </td>
                            <td data-label="Total">{{ money(bill.total) }}</td>
                            <td data-label="Paid">
                                {{ money(bill.paid_total) }}
                            </td>
                            <td data-label="Balance" class="font-semibold">
                                {{ money(bill.balance_due) }}
                            </td>
                            <td data-label="Actions">
                                <Button
                                    v-if="canViewSales"
                                    as-child
                                    size="sm"
                                    variant="outline"
                                    ><Link :href="`/sales/${bill.id}`"
                                        >View bill</Link
                                    ></Button
                                >
                            </td>
                        </tr>
                        <tr v-if="!sales.data.length">
                            <td
                                colspan="6"
                                class="py-10 text-center text-muted-foreground"
                            >
                                No credit bills in your accessible branches.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <ShiftPagination :links="sales.links" /></template
        ><CustomerFormDialog
            v-model:open="editOpen"
            :customer="customer"
            :currency="currency"
        />
    </section>
</template>
