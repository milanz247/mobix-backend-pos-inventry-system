<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Input } from '@/components/ui/input';
import ShiftPagination from '@/components/shifts/ShiftPagination.vue';
import type { PageLink } from '@/types/shift';

type Change = { field: string; before: unknown; after: unknown };
type ActivityLog = {
    id: number;
    action: string;
    subject_type: string;
    subject_id: number | null;
    product_id: number;
    product_name: string;
    actor: string;
    location: string;
    created_at: string;
    changes: Change[];
};
const props = defineProps<{
    logs: {
        data: ActivityLog[];
        links: PageLink[];
        total: number;
        from: number | null;
        to: number | null;
    };
    filters: { search: string; action: string };
}>();
const filters = ref({ ...props.filters });
let timer: ReturnType<typeof setTimeout> | undefined;
watch(
    filters,
    () => {
        if (timer) clearTimeout(timer);
        timer = setTimeout(
            () =>
                router.get(
                    '/products/activity',
                    {
                        search: filters.value.search || undefined,
                        action: filters.value.action || undefined,
                    },
                    {
                        preserveState: true,
                        preserveScroll: true,
                        replace: true,
                    },
                ),
            250,
        );
    },
    { deep: true },
);
onBeforeUnmount(() => {
    if (timer) clearTimeout(timer);
});
const displayValue = (value: unknown): string =>
    value === null || value === undefined
        ? '—'
        : typeof value === 'string'
          ? value
          : JSON.stringify(value);
const fieldName = (field: string): string =>
    field.replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase());
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Products', href: '/products' },
            { title: 'Product activity', href: '/products/activity' },
        ],
    },
});
</script>

<template>
    <Head title="Product activity" />
    <section class="page-shell space-y-4">
        <Heading
            title="Product activity"
            description="Catalogue and variant changes, including deleted products, with the staff member, time, and active location."
        />
        <div
            class="surface-panel grid gap-2 p-3 sm:grid-cols-[minmax(0,1fr)_14rem]"
        >
            <Input
                v-model="filters.search"
                placeholder="Product, staff member, or variant ID"
                aria-label="Search product activity"
            />
            <select v-model="filters.action" aria-label="Activity type">
                <option value="">All activity</option>
                <option value="created">Created</option>
                <option value="updated">Updated</option>
                <option value="deleted">Deleted</option>
            </select>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm text-muted-foreground">
                Showing {{ logs.from ?? 0 }}–{{ logs.to ?? 0 }} of
                {{ logs.total }} recorded changes. New activity is recorded from
                the date this feature is enabled.
            </p>
            <ShiftPagination :links="logs.links" />
        </div>
        <div v-if="logs.data.length" class="space-y-3">
            <article
                v-for="entry in logs.data"
                :key="entry.id"
                class="surface-panel overflow-hidden"
            >
                <header
                    class="flex flex-wrap items-start justify-between gap-3 border-b border-border/70 p-4"
                >
                    <div>
                        <h2 class="font-semibold">{{ entry.product_name }}</h2>
                        <p class="text-sm text-muted-foreground">
                            {{ entry.action }} {{ entry.subject_type
                            }}<span v-if="entry.subject_type === 'variant'">
                                #{{ entry.subject_id }}</span
                            >
                            · {{ entry.actor }} · {{ entry.location }}
                        </p>
                    </div>
                    <time
                        class="text-sm text-muted-foreground"
                        :datetime="entry.created_at"
                        >{{ new Date(entry.created_at).toLocaleString() }}</time
                    >
                </header>
                <div v-if="entry.changes.length" class="overflow-x-auto p-3">
                    <table class="data-table min-w-[560px]">
                        <thead>
                            <tr>
                                <th>Field</th>
                                <th>Before</th>
                                <th>After</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="change in entry.changes"
                                :key="change.field"
                            >
                                <td>{{ fieldName(change.field) }}</td>
                                <td class="max-w-80 break-words">
                                    {{ displayValue(change.before) }}
                                </td>
                                <td class="max-w-80 break-words">
                                    {{ displayValue(change.after) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="p-4 text-sm text-muted-foreground">
                    No tracked fields changed.
                </p>
            </article>
            <ShiftPagination :links="logs.links" />
        </div>
        <div
            v-else
            class="surface-panel p-8 text-center text-sm text-muted-foreground"
        >
            No matching product activity. Existing catalogue history before this
            feature was enabled is not available.
        </div>
    </section>
</template>
