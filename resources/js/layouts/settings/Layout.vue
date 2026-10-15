<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { edit as editBusiness } from '@/routes/business-settings';
import { edit as editSecurity } from '@/routes/security';
import { index as locationsIndex } from '@/routes/locations';
import type { Auth, NavItem } from '@/types';

const auth = usePage<{ auth: Auth }>().props.auth;
const sidebarNavItems = computed<NavItem[]>(() => [
    ...(auth.permissions.includes('business-settings.view')
        ? [{ title: 'Business', href: editBusiness() }]
        : []),
    ...(auth.permissions.includes('locations.view')
        ? [{ title: 'Locations', href: locationsIndex() }]
        : []),
    {
        title: 'Security',
        href: editSecurity(),
    },
]);

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div class="px-4 py-6">
        <Heading
            title="Settings"
            description="Manage your business and sign-in preferences"
        />

        <div class="flex flex-col lg:flex-row lg:space-x-12">
            <aside class="w-full max-w-xl lg:w-48">
                <nav
                    class="flex flex-col space-y-1 space-x-0"
                    aria-label="Settings"
                >
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="toUrl(item.href)"
                        variant="ghost"
                        :class="[
                            'w-full justify-start',
                            { 'bg-muted': isCurrentOrParentUrl(item.href) },
                        ]"
                        as-child
                    >
                        <Link :href="item.href">
                            <component :is="item.icon" class="h-4 w-4" />
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <Separator class="my-6 lg:hidden" />

            <div class="min-w-0 flex-1">
                <section class="max-w-5xl space-y-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
