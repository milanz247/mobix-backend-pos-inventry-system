<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';
import { Eye, Pencil, Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import RowActions from '@/components/management/RowActions.vue';
import SupplierForm from '@/components/purchasing/SupplierForm.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { edit, index, show, store } from '@/routes/suppliers';
import type { Auth } from '@/types';

type Supplier = {
    id: number;
    name: string;
    contact_person: string | null;
    email: string | null;
    phone: string;
    city: string | null;
    status: string;
    purchases_count: number;
};
type PageLink = { url: string | null; label: string; active: boolean };
type Status = { value: string; label: string };
const props = defineProps<{
    suppliers: { data: Supplier[]; links: PageLink[] };
    filters: { search: string; status: string };
    statuses: Status[];
}>();
const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
const addSupplierOpen = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;
watch([search, status], () => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(
        () =>
            router.get(
                index.url(),
                {
                    search: search.value || undefined,
                    status: status.value || undefined,
                },
                {
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                    only: ['suppliers', 'filters'],
                },
            ),
        250,
    );
});
onBeforeUnmount(() => {
    if (timer) clearTimeout(timer);
});
const auth = usePage<{ auth: Auth }>().props.auth;
const can = (permission: string): boolean =>
    auth.permissions.includes(permission);
defineOptions({
    layout: { breadcrumbs: [{ title: 'Suppliers', href: index() }] },
});
</script>

<template>
    <Head title="Suppliers" />
    <section class="page-shell">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Suppliers"
                description="Manage supplier contacts and review their purchase history."
            />
            <Button
                v-if="can('suppliers.create')"
                type="button"
                @click="addSupplierOpen = true"
            >
                <Plus /> Add supplier
            </Button>
        </div>
        <div
            class="surface-panel grid gap-2 p-2 sm:grid-cols-[minmax(0,1fr)_14rem]"
        >
            <Input
                v-model="search"
                aria-label="Search suppliers"
                placeholder="Search name, contact, email, or phone"
            />
            <select
                v-model="status"
                aria-label="Filter suppliers by status"
                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
            >
                <option value="">All statuses</option>
                <option
                    v-for="item in statuses"
                    :key="item.value"
                    :value="item.value"
                >
                    {{ item.label }}
                </option>
            </select>
        </div>
        <div class="table-panel">
            <div class="overflow-x-auto">
                <table class="data-table min-w-[760px]">
                    <thead>
                        <tr>
                            <th scope="col">Supplier</th>
                            <th scope="col">Contact</th>
                            <th scope="col">City</th>
                            <th scope="col">Purchases</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="supplier in suppliers.data"
                            :key="supplier.id"
                        >
                            <td>
                                <div class="font-medium">
                                    {{ supplier.name }}
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    {{ supplier.email || 'No email' }}
                                </div>
                            </td>
                            <td>
                                <div>{{ supplier.contact_person || '—' }}</div>
                                <div class="text-xs text-muted-foreground">
                                    {{ supplier.phone }}
                                </div>
                            </td>
                            <td>{{ supplier.city || '—' }}</td>
                            <td>{{ supplier.purchases_count }}</td>
                            <td>
                                <Badge
                                    :variant="
                                        supplier.status === 'active'
                                            ? 'success'
                                            : 'secondary'
                                    "
                                    >{{ supplier.status }}</Badge
                                >
                            </td>
                            <td class="text-right">
                                <RowActions :label="supplier.name"
                                    ><Button
                                        as-child
                                        variant="outline"
                                        size="icon-sm"
                                        class="row-action"
                                        ><Link
                                            :href="show(supplier.id)"
                                            :aria-label="`View ${supplier.name}`"
                                            ><Eye /></Link></Button
                                    ><Button
                                        v-if="can('suppliers.update')"
                                        as-child
                                        variant="outline"
                                        size="icon-sm"
                                        class="row-action"
                                        ><Link
                                            :href="edit(supplier.id)"
                                            :aria-label="`Edit ${supplier.name}`"
                                            ><Pencil /></Link></Button
                                ></RowActions>
                            </td>
                        </tr>
                        <tr v-if="suppliers.data.length === 0">
                            <td
                                colspan="6"
                                class="py-12 text-center text-muted-foreground"
                            >
                                No suppliers match this search.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <nav
            v-if="suppliers.links.length > 3"
            aria-label="Supplier pages"
            class="flex flex-wrap justify-end gap-1"
        >
            <template v-for="link in suppliers.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    :class="[
                        'rounded-md border px-3 py-2 text-sm',
                        link.active
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'border-border hover:bg-muted',
                    ]"
                    v-html="link.label"
                />
                <span
                    v-else
                    class="pointer-events-none rounded-md border border-border px-3 py-2 text-sm opacity-50"
                    v-html="link.label"
                />
            </template>
        </nav>
        <Dialog v-model:open="addSupplierOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Add supplier</DialogTitle>
                    <DialogDescription>
                        Save supplier contact information for future purchase
                        invoices.
                    </DialogDescription>
                </DialogHeader>
                <SupplierForm
                    embedded
                    :action="store().url"
                    method="post"
                    @cancel="addSupplierOpen = false"
                    @success="addSupplierOpen = false"
                />
            </DialogContent>
        </Dialog>
    </section>
</template>
