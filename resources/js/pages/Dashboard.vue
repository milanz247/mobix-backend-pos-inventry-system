<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    Building2,
    MapPin,
    ShieldCheck,
    Sparkles,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { edit as editBusiness } from '@/routes/business-settings';
import { dashboard } from '@/routes';
import { index as locationsIndex } from '@/routes/locations';
import { index as rolesIndex } from '@/routes/roles';
import { index as usersIndex } from '@/routes/users';
import type { Auth } from '@/types';

const auth = usePage<{ auth: Auth }>().props.auth;

const quickLinks = computed(() =>
    [
        {
            title: 'Business settings',
            description:
                'Keep your business identity and regional settings current.',
            href: editBusiness(),
            icon: Building2,
            permission: 'business-settings.view',
        },
        {
            title: 'Locations',
            description: 'Review shops, warehouses, and location access.',
            href: locationsIndex(),
            icon: MapPin,
            permission: 'locations.view',
        },
        {
            title: 'Users',
            description: 'Manage team accounts and their assigned roles.',
            href: usersIndex(),
            icon: Users,
            permission: 'users.view',
        },
        {
            title: 'Roles & permissions',
            description: 'Control what each team role can access.',
            href: rolesIndex(),
            icon: ShieldCheck,
            permission: 'roles.view',
        },
    ].filter((item) => auth.permissions.includes(item.permission)),
);

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Dashboard" />

    <section class="page-shell">
        <div
            class="relative overflow-hidden rounded-2xl border border-blue-200/60 bg-linear-to-br from-blue-600 via-blue-600 to-indigo-700 p-6 text-white shadow-lg shadow-blue-950/10 sm:p-8 dark:border-blue-400/20"
        >
            <div
                class="absolute -top-24 -right-16 size-64 rounded-full bg-white/10 blur-3xl"
                aria-hidden="true"
            />
            <div class="relative max-w-2xl space-y-4">
                <div
                    class="flex size-11 items-center justify-center rounded-xl border border-white/20 bg-white/10"
                >
                    <Sparkles class="size-5" aria-hidden="true" />
                </div>
                <div class="space-y-2">
                    <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">
                        Welcome to NalakaMobile
                    </h1>
                    <p
                        class="max-w-xl text-sm leading-6 text-blue-50 sm:text-base"
                    >
                        Your secure retail and repair workspace is ready. Use
                        the shortcuts below to manage the foundation of your
                        business.
                    </p>
                </div>
            </div>
        </div>

        <div class="space-y-4">
            <div>
                <h2 class="text-lg font-semibold tracking-tight">
                    Workspace setup
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Quick access to the modules currently available.
                </p>
            </div>

            <div
                v-if="quickLinks.length"
                class="grid gap-4 md:grid-cols-2 xl:grid-cols-4"
            >
                <Link
                    v-for="item in quickLinks"
                    :key="item.title"
                    :href="item.href"
                    class="group surface-panel flex min-h-44 flex-col p-5 transition-[border-color,box-shadow,transform] hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-md focus-visible:ring-3 focus-visible:ring-ring/30 focus-visible:outline-none"
                >
                    <div
                        class="mb-5 flex size-10 items-center justify-center rounded-lg bg-primary/10 text-primary transition-colors group-hover:bg-primary group-hover:text-primary-foreground"
                    >
                        <component
                            :is="item.icon"
                            class="size-5"
                            aria-hidden="true"
                        />
                    </div>
                    <h3 class="font-semibold">{{ item.title }}</h3>
                    <p
                        class="mt-1 flex-1 text-sm leading-5 text-muted-foreground"
                    >
                        {{ item.description }}
                    </p>
                    <span
                        class="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-primary"
                    >
                        Open
                        <ArrowRight
                            class="size-4 transition-transform group-hover:translate-x-0.5"
                            aria-hidden="true"
                        />
                    </span>
                </Link>
            </div>

            <div v-else class="surface-panel p-8 text-center">
                <ShieldCheck
                    class="mx-auto size-8 text-muted-foreground"
                    aria-hidden="true"
                />
                <h3 class="mt-3 font-semibold">Your workspace is ready</h3>
                <p class="mt-1 text-sm text-muted-foreground">
                    No additional management shortcuts are available for your
                    current permissions.
                </p>
                <Button as-child variant="outline" class="mt-5">
                    <Link :href="dashboard()">Refresh dashboard</Link>
                </Button>
            </div>
        </div>
    </section>
</template>
