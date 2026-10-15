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
    <div class="page-shell">
        <Heading
            title="Settings"
            description="Manage your business and sign-in preferences"
        />

        <div class="flex flex-col gap-6 lg:flex-row lg:gap-10">
            <aside class="w-full lg:w-56 lg:shrink-0">
                <nav
                    class="surface-panel flex gap-1 overflow-x-auto p-2 lg:sticky lg:top-20 lg:flex-col"
                    aria-label="Settings"
                >
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="toUrl(item.href)"
                        variant="ghost"
                        :class="[
                            'shrink-0 justify-start lg:w-full',
                            {
                                'bg-primary/10 text-primary hover:bg-primary/15 hover:text-primary':
                                    isCurrentOrParentUrl(item.href),
                            },
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

            <Separator class="lg:hidden" />

            <div class="min-w-0 flex-1">
                <section class="max-w-5xl space-y-8">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
