<script setup lang="ts">
import { Form, Head, Link, router, usePage } from '@inertiajs/vue3';
import { Pencil, Power, Trash2 } from '@lucide/vue';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import Heading from '@/components/Heading.vue';
import RowActions from '@/components/management/RowActions.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useConfirmationDialog } from '@/composables/useConfirmationDialog';
import { create, destroy, edit, index } from '@/routes/users';
import { update as updateStatus } from '@/routes/users/status';
import type { Auth } from '@/types';

type Role = { id: number; name: string };
type User = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    status: string;
    roles: Role[];
};
type LinkItem = { url: string | null; label: string; active: boolean };

defineProps<{
    users: { data: User[]; links: LinkItem[] };
    roles: Role[];
    statuses: { value: string; label: string }[];
    filters: { search?: string; role?: number; status?: string };
}>();

const auth = usePage<{ auth: Auth }>().props.auth;
const can = (permission: string) => auth.permissions.includes(permission);
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

defineOptions({ layout: { breadcrumbs: [{ title: 'Users', href: index() }] } });
</script>

<template>
    <Head title="Users" />
    <section class="page-shell">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Users"
                description="Manage user accounts, roles, and account status."
            />
            <Button v-if="can('users.create')" as-child
                ><Link :href="create()">Create user</Link></Button
            >
        </div>

        <Form
            :action="index.url()"
            method="get"
            class="filter-panel grid gap-3 sm:grid-cols-4"
        >
            <Input
                name="search"
                placeholder="Search users"
                :default-value="filters.search"
            />
            <select name="role" :value="filters.role ?? ''" class="w-full">
                <option value="">All roles</option>
                <option v-for="role in roles" :key="role.id" :value="role.id">
                    {{ role.name }}
                </option>
            </select>
            <select name="status" :value="filters.status ?? ''" class="w-full">
                <option value="">All statuses</option>
                <option
                    v-for="status in statuses"
                    :key="status.value"
                    :value="status.value"
                >
                    {{ status.label }}
                </option>
            </select>
            <Button variant="outline">Filter</Button>
        </Form>

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
                        <th scope="col">Roles</th>
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
                                    >{{ role.name }}</Badge
                                >
                            </div>
                        </td>
                        <td>
                            <Badge
                                :variant="
                                    user.status === 'active'
                                        ? 'success'
                                        : 'secondary'
                                "
                                >{{ user.status }}</Badge
                            >
                        </td>
                        <td class="text-right">
                            <RowActions
                                v-if="
                                    can('users.update') || can('users.delete')
                                "
                                :label="user.name"
                            >
                                <Button
                                    v-if="can('users.update')"
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
                                    v-if="can('users.update')"
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
                                    v-if="can('users.delete')"
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
                            colspan="5"
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
