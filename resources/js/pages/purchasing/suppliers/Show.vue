<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Eye, Pencil, Power, Trash2 } from '@lucide/vue';
import { toast } from 'vue-sonner';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useConfirmationDialog } from '@/composables/useConfirmationDialog';
import {
    create as purchaseCreate,
    index as purchasesIndex,
    show as purchaseShow,
} from '@/routes/purchases';
import { edit, index as suppliersIndex } from '@/routes/suppliers';
import suppliers from '@/routes/suppliers';
import type { Auth } from '@/types';

type Supplier = {
    id: number;
    name: string;
    contact_person: string | null;
    email: string | null;
    phone: string;
    alternate_phone: string | null;
    address: string | null;
    city: string | null;
    notes: string | null;
    status: string;
};
type Purchase = {
    id: number;
    invoice_number: string;
    invoice_date: string;
    location: string;
    total: string;
    status: string;
    payment_status: string;
};
const props = defineProps<{ supplier: Supplier; purchases: Purchase[] }>();
const auth = usePage<{ auth: Auth }>().props.auth;
const can = (permission: string): boolean =>
    auth.permissions.includes(permission);
const {
    isOpen: confirmationOpen,
    title: confirmationTitle,
    description: confirmationDescription,
    confirmLabel,
    destructive,
    request,
    setOpen: setConfirmationOpen,
    confirm: confirmAction,
} = useConfirmationDialog();

function changeStatus(): void {
    const nextStatus =
        props.supplier.status === 'active' ? 'inactive' : 'active';
    const action = nextStatus === 'inactive' ? 'Deactivate' : 'Activate';
    request({
        title: `${action} ${props.supplier.name}?`,
        description:
            nextStatus === 'inactive'
                ? 'This supplier cannot be selected for new purchases while inactive.'
                : 'This supplier will be available for new purchases again.',
        confirmLabel: action,
        destructive: nextStatus === 'inactive',
        onConfirm: () =>
            router.patch(
                suppliers.status.update(props.supplier.id).url,
                { status: nextStatus },
                { preserveScroll: true },
            ),
    });
}

function deleteSupplier(): void {
    request({
        title: `Delete ${props.supplier.name}?`,
        description:
            'This permanently deletes the supplier. Deletion is allowed only when the supplier has no purchase history.',
        confirmLabel: 'Delete supplier',
        destructive: true,
        onConfirm: () =>
            router.delete(suppliers.destroy(props.supplier.id).url, {
                onError: (errors) => {
                    if (errors.supplier) toast.error(errors.supplier);
                },
            }),
    });
}

defineOptions({
    layout: { breadcrumbs: [{ title: 'Suppliers', href: suppliersIndex() }] },
});
</script>

<template>
    <Head :title="supplier.name" />
    <section class="page-shell">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-2">
                <Heading
                    :title="supplier.name"
                    description="Supplier contact details and recent purchase invoices."
                /><Badge
                    :variant="
                        supplier.status === 'active' ? 'success' : 'secondary'
                    "
                    >{{ supplier.status }}</Badge
                >
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="can('purchases.create')"
                    as-child
                    variant="outline"
                    ><Link :href="purchaseCreate()">New purchase</Link></Button
                >
                <Button
                    v-if="can('suppliers.update')"
                    as-child
                    variant="outline"
                    ><Link :href="edit(supplier.id)"
                        ><Pencil /> Edit</Link
                    ></Button
                >
                <Button
                    v-if="can('suppliers.deactivate')"
                    variant="outline"
                    @click="changeStatus"
                    ><Power />
                    {{
                        supplier.status === 'active' ? 'Deactivate' : 'Activate'
                    }}</Button
                >
                <Button
                    v-if="
                        can('suppliers.delete') &&
                        supplier.status === 'inactive'
                    "
                    variant="destructive"
                    @click="deleteSupplier"
                    ><Trash2 /> Delete supplier</Button
                >
            </div>
        </div>
        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
            <section class="surface-panel space-y-4 p-5">
                <h2 class="font-semibold">Contact information</h2>
                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-muted-foreground">Contact person</dt>
                        <dd class="mt-1 font-medium">
                            {{ supplier.contact_person || '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Phone</dt>
                        <dd class="mt-1 font-medium">{{ supplier.phone }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Alternate phone</dt>
                        <dd class="mt-1 font-medium">
                            {{ supplier.alternate_phone || '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Email</dt>
                        <dd class="mt-1 font-medium break-all">
                            {{ supplier.email || '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">City</dt>
                        <dd class="mt-1 font-medium">
                            {{ supplier.city || '—' }}
                        </dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-muted-foreground">Address</dt>
                        <dd class="mt-1 font-medium whitespace-pre-line">
                            {{ supplier.address || '—' }}
                        </dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-muted-foreground">Notes</dt>
                        <dd class="mt-1 whitespace-pre-line">
                            {{ supplier.notes || '—' }}
                        </dd>
                    </div>
                </dl>
            </section>
            <section class="table-panel">
                <div
                    class="flex items-center justify-between gap-3 border-b border-border/70 p-4"
                >
                    <h2 class="font-semibold">Recent purchases</h2>
                    <Button
                        v-if="can('purchases.view')"
                        as-child
                        size="sm"
                        variant="outline"
                        ><Link :href="purchasesIndex()"
                            >All purchases</Link
                        ></Button
                    >
                </div>
                <div class="overflow-x-auto">
                    <table class="data-table min-w-[660px]">
                        <thead>
                            <tr>
                                <th>Invoice</th>
                                <th>Date</th>
                                <th>Location</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="purchase in purchases"
                                :key="purchase.id"
                            >
                                <td class="font-medium">
                                    {{ purchase.invoice_number }}
                                </td>
                                <td>{{ purchase.invoice_date }}</td>
                                <td>{{ purchase.location }}</td>
                                <td>{{ purchase.total }}</td>
                                <td>
                                    <Badge variant="secondary">{{
                                        purchase.status.replaceAll('_', ' ')
                                    }}</Badge>
                                </td>
                                <td class="text-right">
                                    <Button
                                        as-child
                                        variant="outline"
                                        size="icon-sm"
                                        class="row-action"
                                        ><Link
                                            :href="purchaseShow(purchase.id)"
                                            :aria-label="`View purchase ${purchase.invoice_number}`"
                                            ><Eye /></Link
                                    ></Button>
                                </td>
                            </tr>
                            <tr v-if="purchases.length === 0">
                                <td
                                    colspan="6"
                                    class="py-10 text-center text-muted-foreground"
                                >
                                    No purchases are available for your assigned
                                    locations.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
        <ConfirmActionDialog
            :open="confirmationOpen"
            :title="confirmationTitle"
            :description="confirmationDescription"
            :confirm-label="confirmLabel"
            :destructive="destructive"
            @update:open="setConfirmationOpen"
            @confirm="confirmAction"
        />
    </section>
</template>
