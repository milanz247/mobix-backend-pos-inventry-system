<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
const remove = (role: Role) => {
    if (window.confirm(`Delete the ${role.name} role?`))
        router.delete(destroy.url(role.id), { preserveScroll: true });
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
                <thead>
                    <tr>
                        <th>Role</th>
                        <th>Users</th>
                        <th>Permissions</th>
                        <th class="text-right">Actions</th>
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
                        <td>
                            <div class="flex justify-end gap-2">
                                <Button
                                    v-if="can('roles.update')"
                                    size="sm"
                                    variant="outline"
                                    as-child
                                    ><Link :href="edit(role.id)"
                                        >Edit</Link
                                    ></Button
                                >
                                <Button
                                    v-if="
                                        can('roles.delete') && !role.is_system
                                    "
                                    size="sm"
                                    variant="destructive"
                                    @click="remove(role)"
                                    >Delete</Button
                                >
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
