<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Pencil, Trash2 } from '@lucide/vue';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import Heading from '@/components/Heading.vue';
import RowActions from '@/components/management/RowActions.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useConfirmationDialog } from '@/composables/useConfirmationDialog';
import { create, destroy, edit, index } from '@/routes/roles';
import type { Auth } from '@/types';

type Role = {
    id: number;
    name: string;
    description: string | null;
    is_system: boolean;
    users_count: number;
    permissions_count: number;
};
defineProps<{ roles: Role[] }>();
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
const remove = (role: Role) => {
    request({
        title: `Delete the ${role.name} role?`,
        description:
            'This role will be permanently deleted. You cannot delete a role while it is assigned to users.',
        confirmLabel: 'Delete role',
        destructive: true,
        onConfirm: () =>
            router.delete(destroy.url(role.id), { preserveScroll: true }),
    });
};
defineOptions({ layout: { breadcrumbs: [{ title: 'Roles', href: index() }] } });
</script>

<template>
    <Head title="Roles" />
    <section class="page-shell">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Roles"
                description="Manage reusable permission sets for user accounts."
            />
            <Button v-if="can('roles.create')" as-child
                ><Link :href="create()">Create role</Link></Button
            >
        </div>
        <div class="table-panel">
            <table class="data-table">
                <colgroup>
                    <col class="w-[48%]" />
                    <col class="w-[12%]" />
                    <col class="w-[15%]" />
                    <col class="w-[25%]" />
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col">Role</th>
                        <th scope="col">Users</th>
                        <th scope="col">Permissions</th>
                        <th scope="col" class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="role in roles" :key="role.id">
                        <td>
                            <div class="flex items-center gap-2 font-medium">
                                {{ role.name
                                }}<Badge
                                    v-if="role.is_system"
                                    variant="secondary"
                                    >System</Badge
                                >
                            </div>
                            <div class="text-muted-foreground">
                                {{ role.description || 'No description' }}
                            </div>
                        </td>
                        <td>{{ role.users_count }}</td>
                        <td>{{ role.permissions_count }}</td>
                        <td class="text-right">
                            <RowActions
                                v-if="
                                    can('roles.update') ||
                                    (can('roles.delete') && !role.is_system)
                                "
                                :label="role.name"
                            >
                                <Button
                                    v-if="can('roles.update')"
                                    as-child
                                    variant="outline"
                                    size="icon-sm"
                                    class="row-action"
                                >
                                    <Link
                                        :href="edit(role.id)"
                                        :aria-label="`Edit ${role.name}`"
                                        :title="`Edit ${role.name}`"
                                    >
                                        <Pencil aria-hidden="true" />
                                    </Link>
                                </Button>
                                <Button
                                    v-if="
                                        can('roles.delete') && !role.is_system
                                    "
                                    type="button"
                                    variant="outline"
                                    size="icon-sm"
                                    class="row-action text-destructive hover:border-destructive/30 hover:bg-destructive/5 hover:text-destructive"
                                    :aria-label="`Delete ${role.name}`"
                                    :title="`Delete ${role.name}`"
                                    @click="remove(role)"
                                >
                                    <Trash2 aria-hidden="true" />
                                </Button>
                            </RowActions>
                        </td>
                    </tr>
                    <tr v-if="roles.length === 0">
                        <td colspan="4" class="py-12 text-center">
                            <p class="font-medium">No roles yet</p>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Create a role to define access for your team.
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
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
