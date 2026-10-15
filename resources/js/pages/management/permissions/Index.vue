<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { index } from '@/routes/permissions';

type Permission = { name: string; label: string };
defineProps<{ permissionGroups: Record<string, Permission[]> }>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Permissions', href: index() }] },
});
</script>

<template>
    <Head title="Permissions" />
    <section class="page-shell">
        <Heading
            title="Permissions"
            description="Available access controls for implemented modules."
        />
        <div class="grid gap-4 lg:grid-cols-2">
            <section
                v-for="(permissions, module) in permissionGroups"
                :key="module"
                class="surface-panel p-5"
            >
                <h2 class="mb-3 font-semibold">{{ module }}</h2>
                <ul class="space-y-2">
                    <li
                        v-for="permission in permissions"
                        :key="permission.name"
                        class="flex items-center justify-between gap-4 rounded-lg border border-transparent bg-muted/50 p-3 text-sm transition-colors hover:border-primary/15 hover:bg-accent/50"
                    >
                        <span>{{ permission.label }}</span
                        ><Badge variant="secondary">{{
                            permission.name
                        }}</Badge>
                    </li>
                </ul>
            </section>
        </div>
    </section>
</template>
