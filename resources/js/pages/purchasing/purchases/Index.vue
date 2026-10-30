<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';
import { Eye, Pencil, Trash2 } from '@lucide/vue';
import { toast } from 'vue-sonner';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import Heading from '@/components/Heading.vue';
import RowActions from '@/components/management/RowActions.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useConfirmationDialog } from '@/composables/useConfirmationDialog';
import { create, index, show, edit } from '@/routes/purchases';
import purchaseRoutes from '@/routes/purchases';
import type { Auth } from '@/types';

type Purchase = {
    id: number;
    invoice_number: string;
    invoice_date: string;
    supplier: string;
    location: string;
    lines_count: number;
    total: string;
    paid_total: string;
    status: string;
    payment_status: string;
};
type PageLink = { url: string | null; label: string; active: boolean };
type Option = { value: string; label: string };
const props = defineProps<{
    purchases: { data: Purchase[]; links: PageLink[] };
    locations: { id: number; name: string }[];
    filters: {
        search: string;
        status: string;
        payment_status: string;
        location_id: number | string;
    };
    statuses: Option[];
    paymentStatuses: Option[];
    currency: string;
}>();
const query = ref({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    payment_status: props.filters.payment_status ?? '',
    location_id: String(props.filters.location_id ?? ''),
});
let timer: ReturnType<typeof setTimeout> | undefined;
watch(
    query,
    () => {
        if (timer) clearTimeout(timer);
        timer = setTimeout(
            () =>
                router.get(
                    index.url(),
                    {
                        search: query.value.search || undefined,
                        status: query.value.status || undefined,
                        payment_status: query.value.payment_status || undefined,
                        location_id: query.value.location_id || undefined,
                    },
                    {
                        preserveState: true,
                        preserveScroll: true,
                        replace: true,
                        only: ['purchases', 'filters'],
                    },
                ),
            250,
        );
    },
    { deep: true },
);
onBeforeUnmount(() => {
    if (timer) clearTimeout(timer);
});
const auth = usePage<{ auth: Auth }>().props.auth;
const can = (permission: string): boolean =>
    auth.permissions.includes(permission);
const confirmation = useConfirmationDialog();
function deletePurchase(purchase: Purchase): void {
    confirmation.request({
        title: `Delete ${purchase.invoice_number}?`,
        description:
            'This permanently deletes the draft invoice and its lines. Received or posted purchases must be handled with a purchase return.',
        confirmLabel: 'Delete draft',
        destructive: true,
        onConfirm: () =>
            router.delete(purchaseRoutes.destroy(purchase.id).url, {
                preserveScroll: true,
                onError: (errors) => {
                    if (errors.purchase) toast.error(errors.purchase);
                },
            }),
    });
}
function money(value: string): string {
    const [whole = '0', fraction = '00'] = value.split('.', 2);
    return `${props.currency} ${BigInt(whole || '0').toLocaleString()}.${fraction.padEnd(2, '0').slice(0, 2)}`;
}
defineOptions({
    layout: { breadcrumbs: [{ title: 'Purchases', href: index() }] },
});
</script>

<template>
    <Head title="Purchase invoices" />
    <section class="page-shell">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Purchase invoices"
                description="Supplier invoices, receiving status, payments, and returns."
            /><Button v-if="can('purchases.create')" as-child
                ><Link :href="create()">Create purchase</Link></Button
            >
        </div>
        <div class="surface-panel grid gap-2 p-2 sm:grid-cols-2 xl:grid-cols-4">
            <Input
                v-model="query.search"
                aria-label="Search purchases"
                placeholder="Search invoice or supplier"
            />
            <select
                v-model="query.status"
                aria-label="Filter by purchase status"
                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
            >
                <option value="">All purchase statuses</option>
                <option
                    v-for="item in statuses"
                    :key="item.value"
                    :value="item.value"
                >
                    {{ item.label }}
                </option>
            </select>
            <select
                v-model="query.payment_status"
                aria-label="Filter by payment status"
                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
            >
                <option value="">All payment statuses</option>
                <option
                    v-for="item in paymentStatuses"
                    :key="item.value"
                    :value="item.value"
                >
                    {{ item.label }}
                </option>
            </select>
            <select
                v-model="query.location_id"
                aria-label="Filter by location"
                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
            >
                <option value="">All accessible locations</option>
                <option
                    v-for="location in locations"
                    :key="location.id"
                    :value="String(location.id)"
                >
                    {{ location.name }}
                </option>
            </select>
        </div>
        <div class="table-panel">
            <div class="overflow-x-auto">
                <table class="data-table min-w-[980px]">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Supplier</th>
                            <th>Date / Location</th>
                            <th>Lines</th>
                            <th>Total</th>
                            <th>Paid</th>
                            <th>Status</th>
                            <th>Payment</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="purchase in purchases.data"
                            :key="purchase.id"
                        >
                            <td class="font-medium">
                                {{ purchase.invoice_number }}
                            </td>
                            <td>{{ purchase.supplier }}</td>
                            <td>
                                <div>{{ purchase.invoice_date }}</div>
                                <div class="text-xs text-muted-foreground">
                                    {{ purchase.location }}
                                </div>
                            </td>
                            <td>{{ purchase.lines_count }}</td>
                            <td>{{ money(purchase.total) }}</td>
                            <td>{{ money(purchase.paid_total) }}</td>
                            <td>
                                <Badge
                                    :variant="
                                        purchase.status === 'draft'
                                            ? 'warning'
                                            : purchase.status === 'returned'
                                              ? 'secondary'
                                              : 'success'
                                    "
                                    >{{
                                        purchase.status.replaceAll('_', ' ')
                                    }}</Badge
                                >
                            </td>
                            <td>
                                <Badge variant="outline">{{
                                    purchase.payment_status.replaceAll('_', ' ')
                                }}</Badge>
                            </td>
                            <td class="text-right">
                                <RowActions :label="purchase.invoice_number"
                                    ><Button
                                        as-child
                                        variant="outline"
                                        size="icon-sm"
                                        class="row-action"
                                        ><Link
                                            :href="show(purchase.id)"
                                            :aria-label="`View ${purchase.invoice_number}`"
                                            ><Eye /></Link></Button
                                    ><Button
                                        v-if="
                                            can('purchases.update') &&
                                            purchase.status === 'draft'
                                        "
                                        as-child
                                        variant="outline"
                                        size="icon-sm"
                                        class="row-action"
                                        ><Link
                                            :href="edit(purchase.id)"
                                            :aria-label="`Edit ${purchase.invoice_number}`"
                                            ><Pencil /></Link></Button
                                    ><Button
                                        v-if="
                                            can('purchases.delete') &&
                                            purchase.status === 'draft'
                                        "
                                        variant="destructive"
                                        size="icon-sm"
                                        class="row-action"
                                        :aria-label="`Delete ${purchase.invoice_number}`"
                                        @click="deletePurchase(purchase)"
                                        ><Trash2 /></Button
                                ></RowActions>
                            </td>
                        </tr>
                        <tr v-if="purchases.data.length === 0">
                            <td
                                colspan="9"
                                class="py-12 text-center text-muted-foreground"
                            >
                                No purchase invoices found.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <nav
            v-if="purchases.links.length > 3"
            aria-label="Purchase pages"
            class="flex flex-wrap justify-end gap-1"
        >
            <template v-for="link in purchases.links" :key="link.label"
                ><Link
                    v-if="link.url"
                    :href="link.url"
                    :class="[
                        'rounded-md border px-3 py-2 text-sm',
                        link.active
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'border-border hover:bg-muted',
                    ]"
                    v-html="link.label" /><span
                    v-else
                    class="pointer-events-none rounded-md border border-border px-3 py-2 text-sm opacity-50"
                    v-html="link.label"
            /></template>
        </nav>
        <ConfirmActionDialog
            :open="confirmation.isOpen.value"
            :title="confirmation.title.value"
            :description="confirmation.description.value"
            :confirm-label="confirmation.confirmLabel.value"
            :destructive="confirmation.destructive.value"
            @update:open="confirmation.setOpen"
            @confirm="confirmation.confirm"
        />
    </section>
</template>
