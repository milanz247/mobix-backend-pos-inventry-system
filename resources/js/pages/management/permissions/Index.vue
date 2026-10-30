<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { index } from '@/routes/permissions';
import type { PermissionGroups } from '@/types/permissions';
import type { Auth } from '@/types';
const props = defineProps<{ permissionGroups: PermissionGroups }>();
const auth = usePage<{ auth: Auth }>().props.auth;
const search = ref('');
const groups = computed(() =>
    Object.entries(props.permissionGroups)
        .map(([module, permissions]) => ({
            module,
            permissions: permissions.filter((permission) =>
                `${module} ${permission.feature} ${permission.label} ${permission.name}`
                    .toLowerCase()
                    .includes(search.value.toLowerCase().trim()),
            ),
        }))
        .filter((group) => group.permissions.length),
);
const count = computed(
    () => Object.values(props.permissionGroups).flat().length,
);
defineOptions({
    layout: { breadcrumbs: [{ title: 'Permissions', href: index() }] },
});
</script>
<template>
    <Head title="Function permissions" />
    <section class="page-shell space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="Function permissions"
                description="Every available View, Add, Edit, Delete and action permission. Assign them to staff from a role's permission matrix."
            /><Button v-if="auth.permissions.includes('roles.view')" as-child
                ><Link href="/roles">Manage role permissions</Link></Button
            >
        </div>
        <div class="surface-panel flex flex-wrap items-center gap-3 p-4">
            <Input
                v-model="search"
                class="max-w-lg"
                placeholder="Search function, module or permission"
                aria-label="Search function permissions"
            /><span class="text-sm text-muted-foreground"
                >{{ count }} available permissions</span
            >
        </div>
        <section
            v-for="group in groups"
            :key="group.module"
            class="table-panel overflow-hidden"
        >
            <h2 class="border-b bg-muted/40 px-4 py-3 font-semibold">
                {{ group.module }}
            </h2>
            <div class="divide-y">
                <article
                    v-for="permission in group.permissions"
                    :key="permission.id"
                    class="grid gap-2 px-4 py-3 sm:grid-cols-[minmax(0,1fr)_100px_minmax(0,1.5fr)]"
                >
                    <div>
                        <p class="font-medium">
                            {{ permission.feature }} · {{ permission.label }}
                        </p>
                        <p
                            class="mt-1 font-mono text-xs break-all text-muted-foreground"
                        >
                            {{ permission.name }}
                        </p>
                    </div>
                    <div>
                        <Badge variant="outline" class="capitalize">{{
                            permission.action === 'actions'
                                ? 'Action'
                                : permission.action
                        }}</Badge>
                    </div>
                    <div>
                        <p class="text-sm text-muted-foreground">
                            {{ permission.description }}
                        </p>
                        <p
                            v-if="permission.requires.length"
                            class="mt-1 text-xs text-muted-foreground"
                        >
                            Required access:
                            {{ permission.requires.join(', ') }}
                        </p>
                    </div>
                </article>
            </div>
        </section>
        <p
            v-if="groups.length === 0"
            class="surface-panel p-8 text-center text-muted-foreground"
        >
            No permissions match the search.
        </p>
    </section>
</template>
