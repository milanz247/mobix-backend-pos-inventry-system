<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
const page = usePage<{
    locationAccess: {
        locations: { id: number }[];
        allLocations: boolean;
    } | null;
}>();
const hasNoLocations = computed(
    () =>
        page.props.locationAccess !== null &&
        page.props.locationAccess?.locations.length === 0,
);
const isFixedWorkflowPage = computed(() =>
    [
        'purchasing/purchases/Create',
        'purchasing/purchases/Edit',
        'inventory/Adjustments',
        'inventory/Transfers',
        'sales/Show',
    ].includes(page.component),
);
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent
            variant="sidebar"
            :class="
                isFixedWorkflowPage
                    ? 'h-svh min-h-0 overflow-hidden'
                    : 'min-w-0 overflow-x-clip'
            "
        >
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <p
                v-if="hasNoLocations"
                role="status"
                class="border-b border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-900 dark:text-amber-200"
            >
                {{
                    page.props.locationAccess?.allLocations
                        ? 'No active locations are configured. Create or reactivate a location before making transactions.'
                        : 'No active locations are assigned to your account. Ask an administrator to assign your shop locations before making transactions.'
                }}
            </p>
            <slot />
            <footer
                class="flex shrink-0 items-center justify-center border-t border-border/60 px-4 py-2 text-center text-[11px] text-muted-foreground"
            >
                © 2026 NalakaMobile · Developed by Milan Madusanka Senarathna
            </footer>
        </AppContent>
        <Toaster />
    </AppShell>
</template>
