<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import Heading from '@/components/Heading.vue';
import RoleForm from '@/components/management/RoleForm.vue';
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
import { useConfirmationDialog } from '@/composables/useConfirmationDialog';
import { destroy, edit, index, store } from '@/routes/roles';
import type { Auth } from '@/types';

import type { PermissionGroups } from '@/types/permissions';
type Role = {
    id: number;
    name: string;
    description: string | null;
    is_system: boolean;
    users_count: number;
    permissions_count: number;
};
defineProps<{
    roles: Role[];
    permissionGroups: PermissionGroups;
}>();
const auth = usePage<{ auth: Auth }>().props.auth;
const can = (permission: string) => auth.permissions.includes(permission);
const createRoleOpen = ref(false);
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
            <Button
                v-if="can('roles.create')"
                type="button"
                @click="createRoleOpen = true"
            >
                <Plus aria-hidden="true" />
                Create role
            </Button>
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
                                    (can('roles.update') &&
                                        role.name !== 'Owner') ||
                                    (can('roles.delete') && !role.is_system)
                                "
                                :label="role.name"
                            >
                                <Button
                                    v-if="
                                        can('roles.update') &&
                                        role.name !== 'Owner'
                                    "
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
        <Dialog v-model:open="createRoleOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-5xl">
                <DialogHeader>
                    <DialogTitle>Create role</DialogTitle>
                    <DialogDescription>
                        Name the role and select the access permissions it
                        needs.
                    </DialogDescription>
                </DialogHeader>
                <RoleForm
                    embedded
                    :action="store.url()"
                    method="post"
                    :permission-groups="permissionGroups"
                    @cancel="createRoleOpen = false"
                    @success="createRoleOpen = false"
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
