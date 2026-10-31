<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import ShiftPagination from '@/components/shifts/ShiftPagination.vue';
import CustomerFormDialog from '@/components/sales/CustomerFormDialog.vue';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import { useConfirmationDialog } from '@/composables/useConfirmationDialog';
import type { Customer } from '@/types/customer';
const props = defineProps<{
    customers: {
        data: Customer[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
        from: number | null;
        to: number | null;
    };
    search: string;
    currency: string;
    canCreate: boolean;
    canUpdate: boolean;
    canDelete: boolean;
}>();
const search = ref(props.search),
    open = ref(false),
    selected = ref<Customer | null>(null);
const confirmation = useConfirmationDialog();
function edit(customer: Customer | null) {
    selected.value = customer;
    open.value = true;
}
function remove(customer: Customer) {
    confirmation.request({
        title: `Delete ${customer.name}?`,
        description: 'Only customers without invoice history can be deleted.',
        confirmLabel: 'Delete customer',
        destructive: true,
        onConfirm: () =>
            router.delete(`/customers/${customer.id}`, {
                preserveScroll: true,
            }),
    });
}
defineOptions({
    layout: { breadcrumbs: [{ title: 'Customers', href: '/customers' }] },
});
</script>
<template>
    <Head title="Customers" />
    <section class="page-shell">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="Customers"
                description="Find customers, manage details and open their purchase history."
            /><Button v-if="canCreate" @click="edit(null)">Add customer</Button>
        </div>
        <form
            class="flex gap-2"
            @submit.prevent="
                router.get('/customers', { search }, { preserveState: true })
            "
        >
            <Input
                v-model="search"
                aria-label="Find a customer"
                placeholder="Search name or phone"
                class="max-w-md"
            /><Button variant="outline">Search</Button>
        </form>
        <div class="table-panel">
            <table class="data-table responsive-table">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Contact</th>
                        <th>Credit terms</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="customer in customers.data" :key="customer.id">
                        <td data-label="Customer">
                            <Link
                                :href="`/customers/${customer.id}`"
                                class="font-semibold text-primary"
                                >{{ customer.name }}</Link
                            >
                        </td>
                        <td data-label="Contact">
                            <div>
                                <p>{{ customer.phone }}</p>
                                <p class="text-xs text-muted-foreground">
                                    {{ customer.email || 'No email' }}
                                </p>
                            </div>
                        </td>
                        <td data-label="Credit terms">
                            <div>
                                <p>
                                    {{
                                        customer.credit_limit === null
                                            ? 'No configured limit'
                                            : `${currency} ${Number(customer.credit_limit).toLocaleString(undefined, { minimumFractionDigits: 2 })}`
                                    }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ customer.credit_days }} days
                                </p>
                            </div>
                        </td>
                        <td data-label="Actions">
                            <div class="flex flex-wrap justify-end gap-2">
                                <Button as-child size="sm" variant="outline"
                                    ><Link :href="`/customers/${customer.id}`"
                                        >View</Link
                                    ></Button
                                ><Button
                                    v-if="canUpdate"
                                    size="sm"
                                    variant="outline"
                                    @click="edit(customer)"
                                    >Edit</Button
                                ><Button
                                    v-if="canDelete"
                                    size="sm"
                                    variant="outline"
                                    :disabled="!customer.can_delete"
                                    :title="
                                        customer.can_delete
                                            ? 'Delete customer'
                                            : 'Customer has invoice history'
                                    "
                                    class="text-destructive"
                                    @click="remove(customer)"
                                    >Delete</Button
                                >
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!customers.data.length">
                        <td
                            colspan="4"
                            class="py-10 text-center text-muted-foreground"
                        >
                            No customers found.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="text-xs text-muted-foreground">
            {{ customers.total }} customers · Customers with invoice history are
            retained.
        </p>
        <ShiftPagination :links="customers.links" /><CustomerFormDialog
            v-model:open="open"
            :customer="selected"
            :currency="currency"
        /><ConfirmActionDialog
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
