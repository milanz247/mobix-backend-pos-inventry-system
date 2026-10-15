<script setup lang="ts">
import { Form, Head, Link, router, usePage } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { create, edit, index, show } from '@/routes/locations';
import { update as updateStatus } from '@/routes/locations/status';
import { edit as editUsers } from '@/routes/locations/users';
import type { Auth } from '@/types';

type Location = {
    id: number;
    name: string;
    code: string;
    type: string;
    city: string;
    is_main: boolean;
    status: string;
    users_count: number;
};
type LinkItem = { url: string | null; label: string; active: boolean };

defineProps<{
    locations: { data: Location[]; links: LinkItem[] };
    filters: { search?: string; status?: string };
    statuses: { value: string; label: string }[];
}>();

const auth = usePage<{ auth: Auth }>().props.auth;
const can = (permission: string): boolean =>
    auth.permissions.includes(permission);
const changeStatus = (location: Location): void => {
    const status = location.status === 'active' ? 'inactive' : 'active';
    if (
        window.confirm(
            `${status === 'inactive' ? 'Deactivate' : 'Activate'} ${location.name}?`,
        )
    ) {
        router.patch(
            updateStatus.url(location.id),
            { status },
            { preserveScroll: true },
        );
    }
};

defineOptions({
    layout: { breadcrumbs: [{ title: 'Locations', href: index() }] },
});
</script>

<template>
    <Head title="Locations" />
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Locations"
                description="Manage business shops, warehouses, repair centres, and user access."
            />
            <Button v-if="can('locations.create')" as-child
                ><Link :href="create()">Create location</Link></Button
            >
        </div>

        <Form
            :action="index.url()"
            method="get"
            class="grid gap-3 rounded-lg border p-4 sm:grid-cols-3"
        >
            <Input
                name="search"
                placeholder="Search name, code, or city"
                :default-value="filters.search"
            />
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
                        <th class="p-3">Location</th>
                        <th class="p-3">Type</th>
                        <th class="p-3">City</th>
                        <th class="p-3">Users</th>
                        <th class="p-3">Status</th>
                        <th class="p-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="location in locations.data" :key="location.id">
                        <td class="p-3">
                            <div class="flex items-center gap-2 font-medium">
                                {{ location.name
                                }}<Badge
                                    v-if="location.is_main"
                                    variant="secondary"
                                    >Main</Badge
                                >
                            </div>
                            <div class="text-muted-foreground">
                                {{ location.code }}
                            </div>
                        </td>
                        <td class="p-3 capitalize">
                            {{ location.type.replace('_', ' ') }}
                        </td>
                        <td class="p-3">{{ location.city }}</td>
                        <td class="p-3">{{ location.users_count }}</td>
                        <td class="p-3">
                            <Badge
                                :variant="
                                    location.status === 'active'
                                        ? 'default'
                                        : 'secondary'
                                "
                                >{{ location.status }}</Badge
                            >
                        </td>
                        <td class="p-3">
                            <div class="flex justify-end gap-2">
                                <Button size="sm" variant="outline" as-child
                                    ><Link :href="show(location.id)"
                                        >View</Link
                                    ></Button
                                >
                                <Button
                                    v-if="can('locations.update')"
                                    size="sm"
                                    variant="outline"
                                    as-child
                                    ><Link :href="edit(location.id)"
                                        >Edit</Link
                                    ></Button
                                >
                                <Button
                                    v-if="can('locations.assign-users')"
                                    size="sm"
                                    variant="outline"
                                    as-child
                                    ><Link :href="editUsers(location.id)"
                                        >Users</Link
                                    ></Button
                                >
                                <Button
                                    v-if="
                                        can('locations.update') &&
                                        !location.is_main
                                    "
                                    size="sm"
                                    :variant="
                                        location.status === 'active'
                                            ? 'destructive'
                                            : 'outline'
                                    "
                                    @click="changeStatus(location)"
                                    >{{
                                        location.status === 'active'
                                            ? 'Deactivate'
                                            : 'Activate'
                                    }}</Button
                                >
                            </div>
                        </td>
                    </tr>
                    <tr v-if="locations.data.length === 0">
                        <td
                            colspan="6"
                            class="p-8 text-center text-muted-foreground"
                        >
                            No accessible locations found.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav class="flex flex-wrap gap-2" aria-label="Pagination">
            <Button
                v-for="link in locations.links"
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
    </div>
</template>
