<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';
import { Pencil, Power } from '@lucide/vue';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import Heading from '@/components/Heading.vue';
import RowActions from '@/components/management/RowActions.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useConfirmationDialog } from '@/composables/useConfirmationDialog';
import { create, edit, index } from '@/routes/brands';
import { update as updateStatus } from '@/routes/brands/status';
import type { Auth } from '@/types';

type Brand = {
    id: number;
    name: string;
    description: string | null;
    logo_url: string | null;
    status: string;
    products_count: number;
};
type LinkItem = { url: string | null; label: string; active: boolean };
type Option = { value: string; label: string };

const props = defineProps<{
    brands: { data: Brand[]; links: LinkItem[] };
    filters: { search?: string; status?: string };
    statuses: Option[];
}>();

const search = ref(props.filters.search ?? '');
const selectedStatus = ref(props.filters.status ?? '');
let filterTimer: ReturnType<typeof setTimeout> | undefined;
watch([search, selectedStatus], () => {
    if (filterTimer) clearTimeout(filterTimer);
    filterTimer = setTimeout(
        () =>
            router.get(
                index.url(),
                {
                    search: search.value || undefined,
                    status: selectedStatus.value || undefined,
                },
                {
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                    only: ['brands', 'filters'],
                },
            ),
        250,
    );
});
onBeforeUnmount(() => {
    if (filterTimer) clearTimeout(filterTimer);
});

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

const changeStatus = (brand: Brand): void => {
    const status = brand.status === 'active' ? 'inactive' : 'active';
    const action = status === 'inactive' ? 'Deactivate' : 'Activate';

    request({
        title: `${action} ${brand.name}?`,
        description:
            status === 'inactive'
                ? 'This is allowed only after every product using the brand is inactive.'
                : 'This brand will be available for new products again.',
        confirmLabel: action,
        destructive: status === 'inactive',
        onConfirm: () =>
            router.patch(
                updateStatus.url(brand.id),
                { status },
                { preserveScroll: true },
            ),
    });
};

defineOptions({
    layout: { breadcrumbs: [{ title: 'Brands', href: index() }] },
});
</script>

<template>
    <Head title="Brands" />
    <section class="page-shell">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Brands"
                description="Manage the manufacturers and labels associated with products."
            />
            <Button v-if="can('brands.create')" as-child>
                <Link :href="create()">Create brand</Link>
            </Button>
        </div>

        <div
            class="surface-panel grid gap-2 p-2 sm:grid-cols-[minmax(0,1fr)_14rem]"
        >
            <Input
                v-model="search"
                placeholder="Search brand name"
                aria-label="Search brands"
            />
            <select
                v-model="selectedStatus"
                aria-label="Filter brands by status"
                class="w-full"
            >
                <option value="">All statuses</option>
                <option
                    v-for="status in statuses"
                    :key="status.value"
                    :value="status.value"
                >
                    {{ status.label }}
                </option>
            </select>
        </div>

        <div class="table-panel">
            <table class="data-table">
                <colgroup>
                    <col class="w-[42%]" />
                    <col class="w-[16%]" />
                    <col class="w-[18%]" />
                    <col class="w-[24%]" />
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col">Brand</th>
                        <th scope="col">Products</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="brand in brands.data" :key="brand.id">
                        <td>
                            <div class="flex items-center gap-3">
                                <img
                                    v-if="brand.logo_url"
                                    :src="brand.logo_url"
                                    :alt="`${brand.name} logo`"
                                    class="size-9 rounded-md border border-border bg-white object-contain p-1"
                                />
                                <div
                                    v-else
                                    class="grid size-9 place-items-center rounded-md bg-muted text-xs font-semibold text-muted-foreground"
                                >
                                    {{ brand.name.slice(0, 2).toUpperCase() }}
                                </div>
                                <div class="min-w-0">
                                    <div class="truncate font-medium">
                                        {{ brand.name }}
                                    </div>
                                    <div class="truncate text-muted-foreground">
                                        {{
                                            brand.description ||
                                            'No description'
                                        }}
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td>{{ brand.products_count }}</td>
                        <td>
                            <Badge
                                :variant="
                                    brand.status === 'active'
                                        ? 'success'
                                        : 'secondary'
                                "
                            >
                                {{ brand.status }}
                            </Badge>
                        </td>
                        <td class="text-right">
                            <RowActions
                                v-if="
                                    can('brands.update') ||
                                    can('brands.deactivate')
                                "
                                :label="brand.name"
                            >
                                <Button
                                    v-if="can('brands.update')"
                                    as-child
                                    variant="outline"
                                    size="icon-sm"
                                    class="row-action"
                                >
                                    <Link
                                        :href="edit(brand.id)"
                                        :aria-label="`Edit ${brand.name}`"
                                        :title="`Edit ${brand.name}`"
                                    >
                                        <Pencil aria-hidden="true" />
                                    </Link>
                                </Button>
                                <Button
                                    v-if="can('brands.deactivate')"
                                    type="button"
                                    variant="outline"
                                    size="icon-sm"
                                    class="row-action"
                                    :aria-label="`${brand.status === 'active' ? 'Deactivate' : 'Activate'} ${brand.name}`"
                                    :title="`${brand.status === 'active' ? 'Deactivate' : 'Activate'} ${brand.name}`"
                                    @click="changeStatus(brand)"
                                >
                                    <Power aria-hidden="true" />
                                </Button>
                            </RowActions>
                        </td>
                    </tr>
                    <tr v-if="brands.data.length === 0">
                        <td
                            colspan="4"
                            class="py-12 text-center text-muted-foreground"
                        >
                            No brands found. Create a brand or run the catalogue
                            defaults seeder.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav class="flex flex-wrap gap-2" aria-label="Pagination">
            <Button
                v-for="link in brands.links"
                :key="link.label"
                size="sm"
                :variant="link.active ? 'default' : 'outline'"
                :disabled="!link.url"
                as-child
            >
                <Link
                    :href="link.url ?? '#'"
                    preserve-scroll
                    v-html="link.label"
                />
            </Button>
        </nav>
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
