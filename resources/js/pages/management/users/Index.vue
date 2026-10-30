<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Pencil, Plus, Power, Trash2 } from '@lucide/vue';
import { onBeforeUnmount, reactive, ref, watch } from 'vue';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import Heading from '@/components/Heading.vue';
import RowActions from '@/components/management/RowActions.vue';
import UserForm from '@/components/management/UserForm.vue';
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
import { destroy, edit, index, store } from '@/routes/users';
import { update as updateStatus } from '@/routes/users/status';
import type { Auth } from '@/types';

type Role = { id: number; name: string };
type User = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    status: string;
    can_manage: boolean;
    roles: Role[];
    locations: { id: number; name: string; code: string; status: string }[];
};
type LinkItem = { url: string | null; label: string; active: boolean };

const props = defineProps<{
    users: { data: User[]; links: LinkItem[] };
    roles: Role[];
    statuses: { value: string; label: string }[];
    locations: { id: number; name: string; code: string; status: string }[];
    filters: { search?: string; role?: number; status?: string };
}>();

const auth = usePage<{ auth: Auth }>().props.auth;
const can = (permission: string) => auth.permissions.includes(permission);
const canAddUser = can('users.create') && can('users.assign-roles');
const addUserOpen = ref(false);
const searchText = ref(props.filters.search ?? '');
const activeFilters = reactive({
    role: props.filters.role ?? '',
    status: props.filters.status ?? '',
});
let searchTimer: ReturnType<typeof setTimeout> | undefined;
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

const queryFilters = (): Record<string, string | number> => {
    const query: Record<string, string | number> = {};

    if (searchText.value.trim()) {
        query.search = searchText.value.trim();
    }
    if (activeFilters.role) {
        query.role = activeFilters.role;
    }
    if (activeFilters.status) {
        query.status = activeFilters.status;
    }

    return query;
};

const visitUsers = (): void => {
    router.get(index.url(), queryFilters(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['users', 'filters'],
    });
};

watch(searchText, () => {
    if (searchTimer) {
        clearTimeout(searchTimer);
    }

    searchTimer = setTimeout(visitUsers, 250);
});

watch(
    () => props.filters,
    (filters) => {
        searchText.value = filters.search ?? '';
        activeFilters.role = filters.role ?? '';
        activeFilters.status = filters.status ?? '';
    },
    { deep: true },
);

onBeforeUnmount(() => {
    if (searchTimer) {
        clearTimeout(searchTimer);
    }
});

const filterChanged = (event: Event): void => {
    const target = event.target;

    if (!(target instanceof HTMLSelectElement)) {
        return;
    }

    if (searchTimer) {
        clearTimeout(searchTimer);
    }

    const key = target.name as keyof typeof activeFilters;
    activeFilters[key] = target.value;
    visitUsers();
};

const changeStatus = (user: User) => {
    const status = user.status === 'active' ? 'inactive' : 'active';
    const action = status === 'inactive' ? 'Deactivate' : 'Activate';

    request({
        title: `${action} ${user.name}?`,
        description:
            status === 'inactive'
                ? 'This user will no longer be able to sign in until the account is activated again.'
                : 'This user will be able to sign in again.',
        confirmLabel: action,
        destructive: status === 'inactive',
        onConfirm: () =>
            router.patch(
                updateStatus.url(user.id),
                { status },
                { preserveScroll: true },
            ),
    });
};

const remove = (user: User) => {
    request({
        title: `Delete ${user.name}?`,
        description:
            'This user account will be permanently deleted. This action cannot be undone.',
        confirmLabel: 'Delete account',
        destructive: true,
        onConfirm: () =>
            router.delete(destroy.url(user.id), { preserveScroll: true }),
    });
};

defineOptions({
    layout: { breadcrumbs: [{ title: 'User management', href: index() }] },
});
</script>

<template>
    <Head title="User management" />
    <section class="page-shell">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="User management"
                description="Invite teammates and manage their roles and account status."
            />
            <Button v-if="canAddUser" type="button" @click="addUserOpen = true">
                <Plus aria-hidden="true" />
                Add user
            </Button>
        </div>

        <div class="filter-panel grid gap-3 sm:grid-cols-3">
            <Input
                v-model="searchText"
                name="search"
                placeholder="Search users"
            />
            <select
                name="role"
                :value="activeFilters.role"
                class="w-full"
                @change="filterChanged"
            >
                <option value="">All roles</option>
                <option v-for="role in roles" :key="role.id" :value="role.id">
                    {{ role.name }}
                </option>
            </select>
            <select
                name="status"
                :value="activeFilters.status"
                class="w-full"
                @change="filterChanged"
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
                    <col class="w-[24%]" />
                    <col class="w-[16%]" />
                    <col class="w-[23%]" />
                    <col class="w-[12%]" />
                    <col class="w-[25%]" />
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col">User</th>
                        <th scope="col">Contact</th>
                        <th scope="col">Role</th>
                        <th scope="col">Locations</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="user in users.data" :key="user.id">
                        <td class="truncate">
                            <div class="font-medium">{{ user.name }}</div>
                            <div class="text-muted-foreground">
                                {{ user.email }}
                            </div>
                        </td>
                        <td>{{ user.phone || '—' }}</td>
                        <td>
                            <div class="flex flex-wrap gap-1">
                                <Badge
                                    v-for="role in user.roles"
                                    :key="role.id"
                                    variant="secondary"
                                >
                                    {{ role.name }}
                                </Badge>
                            </div>
                        </td>
                        <td>
                            <span
                                v-if="
                                    user.roles.some(
                                        (role) => role.name === 'Owner',
                                    )
                                "
                                >All locations</span
                            >
                            <span v-else>{{
                                user.locations
                                    .map((location) => location.name)
                                    .join(', ') || 'Not assigned'
                            }}</span>
                        </td>
                        <td>
                            <Badge
                                :variant="
                                    user.status === 'active'
                                        ? 'success'
                                        : 'secondary'
                                "
                            >
                                {{ user.status }}
                            </Badge>
                        </td>
                        <td class="text-right">
                            <RowActions
                                v-if="
                                    user.can_manage &&
                                    (can('users.update') ||
                                        can('users.change-status') ||
                                        can('users.delete'))
                                "
                                :label="user.name"
                            >
                                <Button
                                    v-if="
                                        can('users.update') &&
                                        can('users.assign-roles')
                                    "
                                    as-child
                                    variant="outline"
                                    size="icon-sm"
                                    class="row-action"
                                >
                                    <Link
                                        :href="edit(user.id)"
                                        :aria-label="`Edit ${user.name}`"
                                        :title="`Edit ${user.name}`"
                                    >
                                        <Pencil aria-hidden="true" />
                                    </Link>
                                </Button>
                                <Button
                                    v-if="
                                        can('users.change-status') &&
                                        user.id !== auth.user.id
                                    "
                                    type="button"
                                    variant="outline"
                                    size="icon-sm"
                                    class="row-action"
                                    :aria-label="`${user.status === 'active' ? 'Deactivate' : 'Activate'} ${user.name}`"
                                    :title="`${user.status === 'active' ? 'Deactivate' : 'Activate'} ${user.name}`"
                                    @click="changeStatus(user)"
                                >
                                    <Power aria-hidden="true" />
                                </Button>
                                <Button
                                    v-if="
                                        can('users.delete') &&
                                        user.id !== auth.user.id
                                    "
                                    type="button"
                                    variant="outline"
                                    size="icon-sm"
                                    class="row-action text-destructive hover:border-destructive/30 hover:bg-destructive/5 hover:text-destructive"
                                    :aria-label="`Delete ${user.name}`"
                                    :title="`Delete ${user.name}`"
                                    @click="remove(user)"
                                >
                                    <Trash2 aria-hidden="true" />
                                </Button>
                            </RowActions>
                        </td>
                    </tr>
                    <tr v-if="users.data.length === 0">
                        <td
                            colspan="6"
                            class="py-12 text-center text-muted-foreground"
                        >
                            No users found.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav class="flex flex-wrap gap-2" aria-label="Pagination">
            <Button
                v-for="link in users.links"
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

        <Dialog v-model:open="addUserOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Add user</DialogTitle>
                    <DialogDescription>
                        Add account details, choose a role, and optionally email
                        a password setup link.
                    </DialogDescription>
                </DialogHeader>
                <UserForm
                    embedded
                    :action="store.url()"
                    method="post"
                    :roles="roles"
                    :statuses="statuses"
                    :locations="locations"
                    @cancel="addUserOpen = false"
                    @success="addUserOpen = false"
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
