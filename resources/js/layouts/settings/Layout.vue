<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    Building2,
    FileText,
    MapPin,
    MonitorCog,
    ShieldCheck,
    UserRound,
} from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { edit as editBusiness } from '@/routes/business-settings';
import { edit as editSecurity } from '@/routes/security';
import { edit as editInvoices } from '@/routes/invoice-settings';
import { edit as editPos } from '@/routes/pos-settings';
import { edit as editProfile } from '@/routes/profile-settings';
import { index as locationsIndex } from '@/routes/locations';
import type { Auth, NavItem } from '@/types';

type SettingsNavItem = NavItem & { description: string };
type SettingsNavGroup = { title: string; items: SettingsNavItem[] };

const auth = computed(() => usePage<{ auth: Auth }>().props.auth);
const settingsGroups = computed<SettingsNavGroup[]>(() => [
    {
        title: 'Workspace',
        items: [
            ...(auth.value.permissions.includes('business-settings.view')
                ? [
                      {
                          title: 'Business',
                          description: 'Profile and regional defaults',
                          href: editBusiness(),
                          icon: Building2,
                      },
                  ]
                : []),
            ...(auth.value.permissions.includes('invoice-settings.view')
                ? [
                      {
                          title: 'Invoices',
                          description: 'Receipt design and print details',
                          href: editInvoices(),
                          icon: FileText,
                      },
                  ]
                : []),
            ...(auth.value.permissions.includes('pos-settings.view')
                ? [
                      {
                          title: 'POS screen',
                          description: 'Checkout layout and controls',
                          href: editPos(),
                          icon: MonitorCog,
                      },
                  ]
                : []),
            ...(auth.value.permissions.includes('locations.view')
                ? [
                      {
                          title: 'Locations',
                          description: 'Shops, warehouses, and access',
                          href: locationsIndex(),
                          icon: MapPin,
                      },
                  ]
                : []),
        ],
    },
    {
        title: 'Personal',
        items: [
            {
                title: 'My profile',
                description: 'Photo and personal details',
                href: editProfile(),
                icon: UserRound,
            },
        ],
    },
    {
        title: 'Security',
        items: [
            {
                title: 'Password & access',
                description: 'Password, 2FA, and passkeys',
                href: editSecurity(),
                icon: ShieldCheck,
            },
        ],
    },
]);

const { currentUrl, isCurrentOrParentUrl } = useCurrentUrl();

function visitSettingsPage(event: Event): void {
    const destination = (event.target as HTMLSelectElement).value;

    if (destination !== currentUrl.value) {
        router.visit(destination);
    }
}
</script>

<template>
    <div class="page-shell">
        <Heading
            title="Settings"
            description="Manage your business and sign-in preferences"
        />

        <div class="xl:hidden">
            <label
                for="settings-page"
                class="mb-2 block text-xs font-semibold tracking-[0.14em] text-muted-foreground uppercase"
            >
                Settings page
            </label>
            <select
                id="settings-page"
                :value="currentUrl"
                class="h-11 w-full rounded-xl border border-input bg-card px-3 text-sm font-medium shadow-sm transition-colors outline-none focus:border-ring focus:ring-2 focus:ring-ring/20"
                @change="visitSettingsPage"
            >
                <optgroup
                    v-for="group in settingsGroups"
                    :key="group.title"
                    v-show="group.items.length"
                    :label="group.title"
                >
                    <option
                        v-for="item in group.items"
                        :key="item.title"
                        :value="toUrl(item.href)"
                    >
                        {{ item.title }} — {{ item.description }}
                    </option>
                </optgroup>
            </select>
        </div>

        <div
            class="grid gap-6 xl:grid-cols-[260px_minmax(0,1fr)] xl:items-start"
        >
            <aside class="hidden xl:block">
                <nav
                    class="grid gap-4 xl:sticky xl:top-20"
                    aria-label="Settings"
                >
                    <section
                        v-for="group in settingsGroups"
                        :key="group.title"
                        v-show="group.items.length"
                        class="space-y-2"
                    >
                        <p
                            class="px-1 text-xs font-semibold tracking-[0.14em] text-muted-foreground uppercase"
                        >
                            {{ group.title }}
                        </p>
                        <div
                            class="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-1"
                        >
                            <Link
                                v-for="item in group.items"
                                :key="item.title"
                                :href="item.href"
                                :aria-current="
                                    isCurrentOrParentUrl(item.href)
                                        ? 'page'
                                        : undefined
                                "
                                class="group flex min-h-20 items-start gap-3 rounded-xl border border-border/70 bg-card p-3 transition-colors hover:border-primary/30 hover:bg-accent/50 xl:min-h-0 xl:p-4"
                                :class="
                                    isCurrentOrParentUrl(item.href)
                                        ? 'border-primary/30 bg-primary/5 ring-1 ring-primary/15'
                                        : ''
                                "
                            >
                                <span
                                    class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground transition-colors group-hover:text-primary"
                                    :class="
                                        isCurrentOrParentUrl(item.href)
                                            ? 'bg-primary/10 text-primary'
                                            : ''
                                    "
                                >
                                    <component :is="item.icon" class="size-4" />
                                </span>
                                <span class="min-w-0">
                                    <span class="block text-sm font-semibold">{{
                                        item.title
                                    }}</span>
                                    <span
                                        class="mt-1 hidden text-xs leading-5 text-muted-foreground xl:block"
                                        >{{ item.description }}</span
                                    >
                                </span>
                            </Link>
                        </div>
                    </section>
                </nav>
            </aside>

            <div class="min-w-0">
                <section class="max-w-6xl space-y-6">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
