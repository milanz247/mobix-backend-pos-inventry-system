<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Building2, FileText, MapPin, ShieldCheck } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { edit as editBusiness } from '@/routes/business-settings';
import { edit as editSecurity } from '@/routes/security';
import { edit as editInvoices } from '@/routes/invoice-settings';
import { index as locationsIndex } from '@/routes/locations';
import type { Auth, NavItem } from '@/types';

type SettingsNavItem = NavItem & { description: string };

const auth = usePage<{ auth: Auth }>().props.auth;
const sidebarNavItems = computed<SettingsNavItem[]>(() => [
    ...(auth.permissions.includes('business-settings.view')
        ? [
              {
                  title: 'Business',
                  description: 'Profile and regional defaults',
                  href: editBusiness(),
                  icon: Building2,
              },
              {
                  title: 'Invoices',
                  description: 'Receipt design and print details',
                  href: editInvoices(),
                  icon: FileText,
              },
          ]
        : []),
    ...(auth.permissions.includes('locations.view')
        ? [
              {
                  title: 'Locations',
                  description: 'Shops, warehouses, and access',
                  href: locationsIndex(),
                  icon: MapPin,
              },
          ]
        : []),
    {
        title: 'Security',
        description: 'Password and account protection',
        href: editSecurity(),
        icon: ShieldCheck,
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

        <div
            class="grid gap-6 xl:grid-cols-[260px_minmax(0,1fr)] xl:items-start"
        >
            <aside class="space-y-3">
                <div
                    class="hidden rounded-2xl border border-border/70 bg-card p-5 xl:block"
                >
                    <p
                        class="text-xs font-semibold tracking-[0.16em] text-muted-foreground uppercase"
                    >
                        Workspace
                    </p>
                    <p class="mt-2 text-sm leading-6 text-muted-foreground">
                        Keep the details behind your day-to-day operations up to
                        date.
                    </p>
                </div>
                <nav
                    class="grid grid-cols-2 gap-2 sm:grid-cols-4 xl:sticky xl:top-20 xl:grid-cols-1"
                    aria-label="Settings"
                >
                    <Link
                        v-for="item in sidebarNavItems"
                        :key="item.title"
                        :href="item.href"
                        :aria-current="
                            isCurrentOrParentUrl(item.href) ? 'page' : undefined
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
