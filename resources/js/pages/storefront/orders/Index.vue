<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import ShiftPagination from '@/components/shifts/ShiftPagination.vue';
import { Input } from '@/components/ui/input';

type Order = {
    reference: string;
    submitted_at: string | null;
    customer_name: string | null;
    customer_phone: string | null;
    location: string | null;
    delivery_area: string | null;
    currency: string;
    total: string;
    payment_method: string;
    payment_status: string;
    status: string;
};
type PageLink = { url: string | null; label: string; active: boolean };
const props = defineProps<{
    orders: { data: Order[]; links: PageLink[]; total: number };
    filters: Record<string, string>;
}>();
const query = ref({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    payment_method: props.filters.payment_method ?? '',
    payment_status: props.filters.payment_status ?? '',
    date_from: props.filters.date_from ?? '',
    date_to: props.filters.date_to ?? '',
});
let timer: ReturnType<typeof setTimeout> | undefined;
watch(
    query,
    () => {
        if (timer) clearTimeout(timer);
        timer = setTimeout(
            () =>
                router.get(
                    '/storefront/orders',
                    Object.fromEntries(
                        Object.entries(query.value).filter(
                            ([, value]) => value,
                        ),
                    ),
                    {
                        preserveState: true,
                        replace: true,
                        only: ['orders', 'filters'],
                    },
                ),
            300,
        );
    },
    { deep: true },
);
onBeforeUnmount(() => {
    if (timer) clearTimeout(timer);
});
const label = (value: string) => value.replaceAll('_', ' ');
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Online orders', href: '/storefront/orders' }],
    },
});
</script>

<template>
    <Head title="Online orders" />
    <div class="page-shell space-y-6">
        <Heading
            title="Online orders"
            description="Review accepted web orders and their current payment and fulfillment states."
        />
        <section
            class="grid gap-3 rounded-xl border p-4 md:grid-cols-3 xl:grid-cols-6"
            aria-label="Order filters"
        >
            <label class="space-y-1 text-sm md:col-span-2"
                ><span>Reference, customer or phone</span
                ><Input
                    v-model="query.search"
                    maxlength="120"
                    placeholder="Search orders"
            /></label>
            <label class="space-y-1 text-sm"
                ><span>Order status</span
                ><select
                    v-model="query.status"
                    class="w-full rounded-md border bg-background p-2"
                >
                    <option value="">All statuses</option>
                    <option
                        v-for="value in [
                            'pending_confirmation',
                            'confirmed',
                            'cancelled',
                            'expired',
                            'price_approval_required',
                            'preparing',
                            'ready',
                            'dispatched',
                            'delivered',
                            'fulfilled',
                        ]"
                        :key="value"
                        :value="value"
                    >
                        {{ label(value) }}
                    </option>
                </select></label
            >
            <label class="space-y-1 text-sm"
                ><span>Payment method</span
                ><select
                    v-model="query.payment_method"
                    class="w-full rounded-md border bg-background p-2"
                >
                    <option value="">All methods</option>
                    <option value="cod">Cash on Delivery</option>
                    <option value="bank_transfer">Bank Transfer</option>
                </select></label
            >
            <label class="space-y-1 text-sm"
                ><span>Payment status</span
                ><select
                    v-model="query.payment_status"
                    class="w-full rounded-md border bg-background p-2"
                >
                    <option value="">All payment states</option>
                    <option
                        v-for="value in [
                            'cod_due',
                            'paid_collected',
                            'transfer_awaiting_proof',
                            'transfer_awaiting_verification',
                            'paid_verified',
                            'underpaid',
                            'rejected',
                            'refund_pending',
                            'refunded',
                        ]"
                        :key="value"
                        :value="value"
                    >
                        {{ label(value) }}
                    </option>
                </select></label
            >
            <div class="grid grid-cols-2 gap-2 md:col-span-3 xl:col-span-1">
                <label class="space-y-1 text-sm"
                    ><span>From</span
                    ><Input v-model="query.date_from" type="date" /></label
                ><label class="space-y-1 text-sm"
                    ><span>To</span><Input v-model="query.date_to" type="date"
                /></label>
            </div>
        </section>
        <section
            class="overflow-x-auto rounded-xl border bg-background"
            aria-label="Web orders"
        >
            <table class="w-full min-w-[900px] text-left text-sm">
                <thead
                    class="border-b bg-muted/40 text-xs text-muted-foreground uppercase"
                >
                    <tr>
                        <th class="p-3">Order</th>
                        <th class="p-3">Customer</th>
                        <th class="p-3">Fulfillment</th>
                        <th class="p-3">Amount</th>
                        <th class="p-3">Payment</th>
                        <th class="p-3">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="order in orders.data"
                        :key="order.reference"
                        class="border-b last:border-0"
                    >
                        <td class="p-3">
                            <Link
                                :href="`/storefront/orders/${order.reference}`"
                                class="font-semibold underline"
                                >{{ order.reference }}</Link
                            >
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{
                                    order.submitted_at
                                        ? new Date(
                                              order.submitted_at,
                                          ).toLocaleString()
                                        : '—'
                                }}
                            </p>
                        </td>
                        <td class="p-3">
                            <p>{{ order.customer_name ?? 'Customer' }}</p>
                            <p class="text-muted-foreground">
                                {{ order.customer_phone ?? '—' }}
                            </p>
                        </td>
                        <td class="p-3">
                            <p>{{ order.location ?? '—' }}</p>
                            <p class="text-muted-foreground">
                                {{ order.delivery_area ?? '—' }}
                            </p>
                        </td>
                        <td class="p-3 font-semibold">
                            {{ order.currency }} {{ order.total }}
                        </td>
                        <td class="p-3 capitalize">
                            {{ label(order.payment_method) }}
                            <p class="text-xs text-muted-foreground">
                                {{ label(order.payment_status) }}
                            </p>
                        </td>
                        <td class="p-3 capitalize">
                            {{ label(order.status) }}
                        </td>
                    </tr>
                    <tr v-if="orders.data.length === 0">
                        <td
                            colspan="6"
                            class="p-8 text-center text-muted-foreground"
                        >
                            No online orders match these filters.
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
        <p class="text-sm text-muted-foreground">
            {{ orders.total }} matching orders
        </p>
        <ShiftPagination :links="orders.links" />
    </div>
</template>
