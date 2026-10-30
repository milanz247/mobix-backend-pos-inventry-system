<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';
import { Pencil, Plus, Power, Trash2 } from '@lucide/vue';
import { toast } from 'vue-sonner';
import CategoryForm from '@/components/catalog/CategoryForm.vue';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import Heading from '@/components/Heading.vue';
import RowActions from '@/components/management/RowActions.vue';
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
import { useConfirmationDialog } from '@/composables/useConfirmationDialog';
import { destroy, edit, index, store } from '@/routes/categories';
import { update as updateStatus } from '@/routes/categories/status';
import type { Auth } from '@/types';

type Category = {
    id: number;
    name: string;
    description: string | null;
    status: string;
    products_count: number;
};
type LinkItem = { url: string | null; label: string; active: boolean };
type Option = { value: string; label: string };

const props = defineProps<{
    categories: { data: Category[]; links: LinkItem[] };
    filters: { search?: string; status?: string };
    statuses: Option[];
}>();

const search = ref(props.filters.search ?? '');
const selectedStatus = ref(props.filters.status ?? '');
const createCategoryOpen = ref(false);
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
                    only: ['categories', 'filters'],
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

const changeStatus = (category: Category): void => {
    const status = category.status === 'active' ? 'inactive' : 'active';
    const action = status === 'inactive' ? 'Deactivate' : 'Activate';

    request({
        title: `${action} ${category.name}?`,
        description:
            status === 'inactive'
                ? 'This is allowed only after every product in the category is inactive.'
                : 'This category will be available for new products again.',
        confirmLabel: action,
        destructive: status === 'inactive',
        onConfirm: () =>
            router.patch(
                updateStatus.url(category.id),
                { status },
                { preserveScroll: true },
            ),
    });
};

const remove = (category: Category): void => {
    request({
        title: `Delete ${category.name}?`,
        description:
            'This category will be permanently deleted. Categories assigned to products cannot be deleted.',
        confirmLabel: 'Delete category',
        destructive: true,
        onConfirm: () =>
            router.delete(destroy.url(category.id), {
                preserveScroll: true,
                onError: (errors) => {
                    if (errors.category) toast.error(errors.category);
                },
            }),
    });
};

defineOptions({
    layout: { breadcrumbs: [{ title: 'Categories', href: index() }] },
});
</script>

<template>
    <Head title="Categories" />
    <section class="page-shell">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Categories"
                description="Organize phones, accessories, spare parts, and other products."
            />
            <Button
                v-if="can('categories.create')"
                type="button"
                @click="createCategoryOpen = true"
            >
                <Plus aria-hidden="true" />
                Create category
            </Button>
        </div>

        <div
            class="surface-panel grid gap-2 p-2 sm:grid-cols-[minmax(0,1fr)_14rem]"
        >
            <Input
                v-model="search"
                placeholder="Search category name"
                aria-label="Search categories"
            />
            <select
                v-model="selectedStatus"
                aria-label="Filter categories by status"
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
                        <th scope="col">Category</th>
                        <th scope="col">Products</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="category in categories.data" :key="category.id">
                        <td>
                            <div class="font-medium">{{ category.name }}</div>
                            <div class="text-muted-foreground">
                                {{ category.description || 'No description' }}
                            </div>
                        </td>
                        <td>{{ category.products_count }}</td>
                        <td>
                            <Badge
                                :variant="
                                    category.status === 'active'
                                        ? 'success'
                                        : 'secondary'
                                "
                            >
                                {{ category.status }}
                            </Badge>
                        </td>
                        <td class="text-right">
                            <RowActions
                                v-if="
                                    can('categories.update') ||
                                    can('categories.deactivate')
                                "
                                :label="category.name"
                            >
                                <Button
                                    v-if="can('categories.update')"
                                    as-child
                                    variant="outline"
                                    size="icon-sm"
                                    class="row-action"
                                >
                                    <Link
                                        :href="edit(category.id)"
                                        :aria-label="`Edit ${category.name}`"
                                        :title="`Edit ${category.name}`"
                                    >
                                        <Pencil aria-hidden="true" />
                                    </Link>
                                </Button>
                                <Button
                                    v-if="can('categories.deactivate')"
                                    type="button"
                                    variant="outline"
                                    size="icon-sm"
                                    class="row-action"
                                    :aria-label="`${category.status === 'active' ? 'Deactivate' : 'Activate'} ${category.name}`"
                                    :title="`${category.status === 'active' ? 'Deactivate' : 'Activate'} ${category.name}`"
                                    @click="changeStatus(category)"
                                >
                                    <Power aria-hidden="true" />
                                </Button>
                                <Button
                                    v-if="can('categories.delete')"
                                    type="button"
                                    variant="outline"
                                    size="icon-sm"
                                    class="row-action text-destructive hover:border-destructive/30 hover:bg-destructive/5 hover:text-destructive"
                                    :aria-label="`Delete ${category.name}`"
                                    :title="`Delete ${category.name}`"
                                    @click="remove(category)"
                                >
                                    <Trash2 aria-hidden="true" />
                                </Button>
                            </RowActions>
                        </td>
                    </tr>
                    <tr v-if="categories.data.length === 0">
                        <td
                            colspan="4"
                            class="py-12 text-center text-muted-foreground"
                        >
                            No categories found. Create one to start organizing
                            products.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav class="flex flex-wrap gap-2" aria-label="Pagination">
            <Button
                v-for="link in categories.links"
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
        <Dialog v-model:open="createCategoryOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Create category</DialogTitle>
                    <DialogDescription>
                        Group products into a clear catalogue category.
                    </DialogDescription>
                </DialogHeader>
                <CategoryForm
                    embedded
                    :action="store.url()"
                    method="post"
                    :statuses="statuses"
                    @cancel="createCategoryOpen = false"
                    @success="createCategoryOpen = false"
                />
            </DialogContent>
        </Dialog>
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
