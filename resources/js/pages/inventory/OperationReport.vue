<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Input } from '@/components/ui/input';

type Line = { product: string; variant: string; sku: string; quantity: string };
type Operation = {
    id: number;
    reference: string | null;
    reason: string | null;
    source: string | null;
    destination: string | null;
    actor: string;
    posted_at: string;
    lines: Line[];
};
type LinkItem = { url: string | null; label: string; active: boolean };
const props = defineProps<{
    type: 'adjustments' | 'transfers';
    operations: { data: Operation[]; links: LinkItem[] };
    locations: { id: number; name: string }[];
    filters: {
        search: string;
        location_id: number | null;
        from: string;
        to: string;
    };
}>();
const filters = ref({
    ...props.filters,
    location_id: props.filters.location_id
        ? String(props.filters.location_id)
        : '',
});
const title =
    props.type === 'adjustments'
        ? 'Stock adjustment report'
        : 'Stock transfer report';
const endpoint = `/inventory/reports/${props.type}`;
let timer: ReturnType<typeof setTimeout> | undefined;
watch(
    filters,
    () => {
        if (timer) clearTimeout(timer);
        timer = setTimeout(
            () =>
                router.get(
                    endpoint,
                    {
                        ...filters.value,
                        location_id: filters.value.location_id || undefined,
                        search: filters.value.search || undefined,
                        from: filters.value.from || undefined,
                        to: filters.value.to || undefined,
                    },
                    {
                        preserveState: true,
                        replace: true,
                        only: ['operations', 'filters'],
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
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Inventory reports', href: '/inventory' }],
    },
});
</script>

<template>
    <Head :title="title" />
    <section class="page-shell">
        <Heading
            :title="title"
            :description="
                type === 'adjustments'
                    ? 'Review posted stock increases and decreases, products, reasons, and staff.'
                    : 'Review product transfers between your authorized locations.'
            "
        />
        <div class="surface-panel grid gap-2 p-2 sm:grid-cols-2 lg:grid-cols-5">
            <Input
                v-model="filters.search"
                placeholder="Search product, SKU, or reference"
            />
            <select v-model="filters.location_id">
                <option value="">All authorized locations</option>
                <option
                    v-for="location in locations"
                    :key="location.id"
                    :value="String(location.id)"
                >
                    {{ location.name }}
                </option>
            </select>
            <label class="grid gap-1 text-xs text-muted-foreground"
                >From<Input v-model="filters.from" type="date"
            /></label>
            <label class="grid gap-1 text-xs text-muted-foreground"
                >To<Input v-model="filters.to" type="date"
            /></label>
            <div class="flex items-center text-sm text-muted-foreground">
                {{ operations.data.length }} operations on this page
            </div>
        </div>
        <div class="table-panel overflow-x-auto">
            <table class="data-table responsive-table">
                <thead>
                    <tr>
                        <th>Date / reference</th>
                        <th>Locations</th>
                        <th>Products and quantities</th>
                        <th>Reason</th>
                        <th>Recorded by</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="operation in operations.data"
                        :key="operation.id"
                    >
                        <td data-label="Date / reference">
                            <div>
                                {{
                                    new Date(
                                        operation.posted_at,
                                    ).toLocaleString()
                                }}
                            </div>
                            <div class="text-muted-foreground">
                                {{ operation.reference || '—' }}
                            </div>
                        </td>
                        <td data-label="Locations">
                            {{ operation.source || '—'
                            }}<span v-if="operation.destination">
                                → {{ operation.destination }}</span
                            >
                        </td>
                        <td data-label="Products and quantities">
                            <div
                                v-for="line in operation.lines"
                                :key="`${line.sku}-${line.quantity}`"
                                class="mb-1"
                            >
                                <span class="font-medium"
                                    >{{ line.product }} {{ line.variant }}</span
                                >
                                · {{ line.sku }} · {{ line.quantity }}
                            </div>
                        </td>
                        <td data-label="Reason">
                            {{ operation.reason || '—' }}
                        </td>
                        <td data-label="Recorded by">{{ operation.actor }}</td>
                    </tr>
                    <tr v-if="operations.data.length === 0">
                        <td
                            colspan="5"
                            class="py-10 text-center text-muted-foreground"
                        >
                            No operations match these filters.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <nav
            v-if="operations.links.length > 3"
            class="flex flex-wrap justify-end gap-1"
            aria-label="Pagination"
        >
            <button
                v-for="link in operations.links"
                :key="link.label"
                type="button"
                class="rounded-md border px-3 py-1.5 text-sm disabled:opacity-40"
                :class="
                    link.active
                        ? 'bg-primary text-primary-foreground'
                        : 'bg-background'
                "
                :disabled="!link.url"
                @click="
                    link.url && router.visit(link.url, { preserveScroll: true })
                "
                v-html="link.label"
            />
        </nav>
    </section>
</template>
