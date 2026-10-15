<script setup lang="ts">
import { Form, Head, Link, router, usePage } from '@inertiajs/vue3';
import { Eye, Pencil, Power, UsersRound } from '@lucide/vue';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import Heading from '@/components/Heading.vue';
import RowActions from '@/components/management/RowActions.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useConfirmationDialog } from '@/composables/useConfirmationDialog';
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
const changeStatus = (location: Location): void => {
    const status = location.status === 'active' ? 'inactive' : 'active';
    const action = status === 'inactive' ? 'Deactivate' : 'Activate';

    request({
        title: `${action} ${location.name}?`,
        description:
            status === 'inactive'
                ? 'Users may lose access to this location while it is inactive. You can activate it again later.'
                : 'This location will become available to assigned users again.',
        confirmLabel: action,
        destructive: status === 'inactive',
        onConfirm: () =>
            router.patch(
                updateStatus.url(location.id),
                { status },
                { preserveScroll: true },
            ),
    });
};

defineOptions({
    layout: { breadcrumbs: [{ title: 'Locations', href: index() }] },
});
</script>

<template>
    <Head title="Locations" />
    <div class="page-shell">
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
            class="filter-panel grid gap-3 sm:grid-cols-3"
        >
            <Input
                name="search"
                placeholder="Search name, code, or city"
                :default-value="filters.search"
            />
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
                    <col class="w-[25%]" />
                    <col class="w-[14%]" />
                    <col class="w-[14%]" />
                    <col class="w-[8%]" />
                    <col class="w-[13%]" />
                    <col class="w-[26%]" />
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col">Location</th>
                        <th scope="col">Type</th>
                        <th scope="col">City</th>
                        <th scope="col">Users</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="location in locations.data" :key="location.id">
                        <td>
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
                        <td class="capitalize">
                            {{ location.type.replace('_', ' ') }}
                        </td>
                        <td>{{ location.city }}</td>
                        <td>{{ location.users_count }}</td>
                        <td>
                            <Badge
                                :variant="
                                    location.status === 'active'
                                        ? 'success'
                                        : 'secondary'
                                "
                                >{{ location.status }}</Badge
                            >
                        </td>
                        <td class="text-right">
                            <RowActions :label="location.name">
                                <Button
                                    as-child
                                    variant="outline"
                                    size="icon-sm"
                                    class="row-action"
                                >
                                    <Link
                                        :href="show(location.id)"
                                        :aria-label="`View ${location.name}`"
                                        :title="`View ${location.name}`"
                                    >
                                        <Eye aria-hidden="true" />
                                    </Link>
                                </Button>
                                <Button
                                    v-if="can('locations.update')"
                                    as-child
                                    variant="outline"
                                    size="icon-sm"
                                    class="row-action"
                                >
                                    <Link
                                        :href="edit(location.id)"
                                        :aria-label="`Edit ${location.name}`"
                                        :title="`Edit ${location.name}`"
                                    >
                                        <Pencil aria-hidden="true" />
                                    </Link>
                                </Button>
                                <Button
                                    v-if="can('locations.assign-users')"
                                    as-child
                                    variant="outline"
                                    size="icon-sm"
                                    class="row-action"
                                >
                                    <Link
                                        :href="editUsers(location.id)"
                                        :aria-label="`Manage users for ${location.name}`"
                                        :title="`Manage users for ${location.name}`"
                                    >
                                        <UsersRound aria-hidden="true" />
                                    </Link>
                                </Button>
                                <Button
                                    v-if="
                                        can('locations.update') &&
                                        !location.is_main
                                    "
                                    type="button"
                                    variant="outline"
                                    size="icon-sm"
                                    class="row-action"
                                    :aria-label="`${location.status === 'active' ? 'Deactivate' : 'Activate'} ${location.name}`"
                                    :title="`${location.status === 'active' ? 'Deactivate' : 'Activate'} ${location.name}`"
                                    @click="changeStatus(location)"
                                >
                                    <Power aria-hidden="true" />
                                </Button>
                            </RowActions>
                        </td>
                    </tr>
                    <tr v-if="locations.data.length === 0">
                        <td
                            colspan="6"
                            class="py-12 text-center text-muted-foreground"
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
        <ConfirmActionDialog
            :open="confirmationOpen"
            :title="confirmationTitle"
            :description="confirmationDescription"
            :confirm-label="confirmLabel"
            :destructive="destructive"
            @update:open="setConfirmationOpen"
            @confirm="confirmAction"
        />
    </div>
</template>
