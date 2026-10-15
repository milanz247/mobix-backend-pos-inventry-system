<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { edit, index } from '@/routes/locations';
import { update as updateStatus } from '@/routes/locations/status';
import { edit as editUsers } from '@/routes/locations/users';
import type { Auth } from '@/types';

type Location = {
    id: number;
    name: string;
    code: string;
    type: string;
    phone: string;
    email: string | null;
    address: string;
    city: string;
    is_main: boolean;
    status: string;
    users_count: number;
};
defineProps<{ location: Location }>();
const auth = usePage<{ auth: Auth }>().props.auth;
const can = (permission: string): boolean =>
    auth.permissions.includes(permission);
const changeStatus = (location: Location): void => {
    const status = location.status === 'active' ? 'inactive' : 'active';
    if (
        window.confirm(
            `${status === 'inactive' ? 'Deactivate' : 'Activate'} ${location.name}?`,
        )
    )
        router.patch(updateStatus.url(location.id), { status });
};
defineOptions({
    layout: { breadcrumbs: [{ title: 'Location details', href: index() }] },
});
</script>

<template>
    <Head :title="location.name" />
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="location.name"
                description="Location contact details, status, and access summary."
            />
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" as-child
                    ><Link :href="index()">All locations</Link></Button
                >
                <Button
                    v-if="can('locations.assign-users')"
                    variant="outline"
                    as-child
                    ><Link :href="editUsers(location.id)"
                        >Manage users</Link
                    ></Button
                >
                <Button v-if="can('locations.update')" as-child
                    ><Link :href="edit(location.id)">Edit</Link></Button
                >
                <Button
                    v-if="can('locations.update') && !location.is_main"
                    :variant="
                        location.status === 'active' ? 'destructive' : 'outline'
                    "
                    @click="changeStatus(location)"
                    >{{
                        location.status === 'active' ? 'Deactivate' : 'Activate'
                    }}</Button
                >
            </div>
        </div>

        <dl
            class="surface-panel grid gap-6 p-5 sm:grid-cols-2 sm:p-6 lg:grid-cols-3"
        >
            <div>
                <dt class="text-sm text-muted-foreground">Code</dt>
                <dd class="font-medium">{{ location.code }}</dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Type</dt>
                <dd class="font-medium capitalize">
                    {{ location.type.replace('_', ' ') }}
                </dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Status</dt>
                <dd>
                    <Badge
                        :variant="
                            location.status === 'active'
                                ? 'success'
                                : 'secondary'
                        "
                        >{{ location.status }}</Badge
                    >
                    <Badge v-if="location.is_main" variant="secondary"
                        >Main location</Badge
                    >
                </dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Phone</dt>
                <dd class="font-medium">{{ location.phone }}</dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Email</dt>
                <dd class="font-medium">{{ location.email || '—' }}</dd>
            </div>
            <div>
                <dt class="text-sm text-muted-foreground">Assigned users</dt>
                <dd class="font-medium">{{ location.users_count }}</dd>
            </div>
            <div class="sm:col-span-2 lg:col-span-3">
                <dt class="text-sm text-muted-foreground">Address</dt>
                <dd class="font-medium whitespace-pre-line">
                    {{ location.address }}, {{ location.city }}
                </dd>
            </div>
        </dl>
    </div>
</template>
