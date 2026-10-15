<script setup lang="ts">
import { Form, Head, Link, router, usePage } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
const changeStatus = (user: User) => {
    const status = user.status === 'active' ? 'inactive' : 'active';
    if (
        window.confirm(
            `${status === 'inactive' ? 'Deactivate' : 'Activate'} ${user.name}?`,
        )
    ) {
        router.patch(
            updateStatus.url(user.id),
            { status },
            { preserveScroll: true },
        );
    }
};
const remove = (user: User) => {
    if (window.confirm(`Delete ${user.name}? This cannot be undone.`)) {
        router.delete(destroy.url(user.id), { preserveScroll: true });
    }
};

defineOptions({ layout: { breadcrumbs: [{ title: 'Users', href: index() }] } });
</script>

<template>
    <Head title="Users" />
    <section class="space-y-6 p-6">
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
            class="grid gap-3 rounded-lg border p-4 sm:grid-cols-4"
        >
            <Input
                name="search"
                placeholder="Search users"
                :default-value="filters.search"
            />
            <select
                name="role"
                :value="filters.role ?? ''"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
            >
                <option value="">All roles</option>
                <option v-for="role in roles" :key="role.id" :value="role.id">
                    {{ role.name }}
                </option>
            </select>
            <select
                name="status"
                :value="filters.status ?? ''"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
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
            <Button variant="outline">Filter</Button>
        </Form>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="p-3">User</th>
                        <th class="p-3">Contact</th>
                        <th class="p-3">Roles</th>
                        <th class="p-3">Status</th>
                        <th class="p-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="user in users.data" :key="user.id">
                        <td class="p-3">
                            <div class="font-medium">{{ user.name }}</div>
                            <div class="text-muted-foreground">
                                {{ user.email }}
                            </div>
                        </td>
                        <td class="p-3">{{ user.phone || '—' }}</td>
                        <td class="p-3">
                            <div class="flex flex-wrap gap-1">
                                <Badge
                                    v-for="role in user.roles"
                                    :key="role.id"
                                    variant="secondary"
                                    >{{ role.name }}</Badge
                                >
                            </div>
                        </td>
                        <td class="p-3">
                            <Badge
                                :variant="
                                    user.status === 'active'
                                        ? 'default'
                                        : 'secondary'
                                "
                                >{{ user.status }}</Badge
                            >
                        </td>
                        <td class="p-3">
                            <div class="flex justify-end gap-2">
                                <Button
                                    v-if="can('users.update')"
                                    size="sm"
                                    variant="outline"
                                    as-child
                                    ><Link :href="edit(user.id)"
                                        >Edit</Link
                                    ></Button
                                >
                                <Button
                                    v-if="can('users.update')"
                                    size="sm"
                                    variant="outline"
                                    @click="changeStatus(user)"
                                    >{{
                                        user.status === 'active'
                                            ? 'Deactivate'
                                            : 'Activate'
                                    }}</Button
                                >
                                <Button
                                    v-if="can('users.delete')"
                                    size="sm"
                                    variant="destructive"
                                    @click="remove(user)"
                                    >Delete</Button
                                >
                            </div>
                        </td>
                    </tr>
                    <tr v-if="users.data.length === 0">
                        <td
                            colspan="5"
                            class="p-8 text-center text-muted-foreground"
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
    </section>
</template>
