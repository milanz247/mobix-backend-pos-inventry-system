<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { index } from '@/routes/inventory/movements';
type Movement = {
    id: number;
    location: string;
    product: string;
    variant: string;
    sku: string;
    type: string;
    delta: string;
    before: string;
    after: string;
    reference: string | null;
    actor: string;
    created_at: string;
};
type LinkItem = { url: string | null; label: string; active: boolean };
type Option = { id?: number; name?: string; value?: string; label?: string };
const props = defineProps<{
    movements: { data: Movement[]; links: LinkItem[] };
    locations: Option[];
    movementTypes: Option[];
    filters: {
        search: string;
        location_id: number | null;
        movement_type: string;
    };
}>();
const search = ref(props.filters.search);
const locationId = ref(
    props.filters.location_id ? String(props.filters.location_id) : '',
);
const movementType = ref(props.filters.movement_type);
let timer: ReturnType<typeof setTimeout> | undefined;
watch([search, locationId, movementType], () => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(
        () =>
            router.get(
                index.url(),
                {
                    search: search.value || undefined,
                    location_id: locationId.value || undefined,
                    movement_type: movementType.value || undefined,
                },
                {
                    preserveState: true,
                    replace: true,
                    only: ['movements', 'filters'],
                },
            ),
        250,
    );
});
onBeforeUnmount(() => {
    if (timer) clearTimeout(timer);
});
defineOptions({
    layout: { breadcrumbs: [{ title: 'Stock movements', href: index() }] },
});
</script>
<template>
    <Head title="Stock movement history" />
    <section class="page-shell">
        <Heading
            title="Stock movement history"
            description="Immutable quantity-stock audit history. Corrections are posted as new movements."
        />
        <div class="surface-panel grid gap-2 p-2 md:grid-cols-3">
            <Input
                v-model="search"
                placeholder="Search product or SKU"
            /><select v-model="locationId">
                <option value="">All authorized locations</option>
                <option
                    v-for="item in locations"
                    :key="item.id"
                    :value="item.id"
                >
                    {{ item.name }}
                </option></select
            ><select v-model="movementType">
                <option value="">All movement types</option>
                <option
                    v-for="item in movementTypes"
                    :key="item.value"
                    :value="item.value"
                >
                    {{ item.label }}
                </option>
            </select>
        </div>
        <div class="table-panel">
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Location / actor</th>
                            <th>Type / reference</th>
                            <th class="text-right">Change</th>
                            <th class="text-right">Before → after</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in movements.data" :key="item.id">
                            <td>
                                <div class="font-medium">
                                    {{ item.product }}
                                </div>
                                <div class="text-muted-foreground">
                                    {{ item.variant }} · {{ item.sku }}
                                </div>
                            </td>
                            <td>
                                {{ item.location }}
                                <div class="text-muted-foreground">
                                    {{ item.actor }}
                                </div>
                            </td>
                            <td>
                                <Badge variant="secondary">{{
                                    item.type.replaceAll('_', ' ')
                                }}</Badge>
                                <div
                                    v-if="item.reference"
                                    class="mt-1 text-xs text-muted-foreground"
                                >
                                    {{ item.reference }}
                                </div>
                            </td>
                            <td
                                class="text-right font-semibold tabular-nums"
                                :class="
                                    Number(item.delta) >= 0
                                        ? 'text-emerald-600'
                                        : 'text-destructive'
                                "
                            >
                                {{ Number(item.delta) >= 0 ? '+' : ''
                                }}{{ item.delta }}
                            </td>
                            <td class="text-right tabular-nums">
                                {{ item.before }} → {{ item.after }}
                            </td>
                        </tr>
                        <tr v-if="movements.data.length === 0">
                            <td
                                colspan="5"
                                class="py-10 text-center text-muted-foreground"
                            >
                                No movements match these filters.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <nav class="flex justify-end gap-1 border-t p-3">
                <Link
                    v-for="link in movements.links"
                    :key="link.label"
                    :href="link.url || '#'"
                    :class="[
                        'rounded-md px-3 py-1.5 text-sm',
                        link.active
                            ? 'bg-primary text-primary-foreground'
                            : 'hover:bg-muted',
                        !link.url && 'pointer-events-none opacity-40',
                    ]"
                    ><span v-html="link.label"
                /></Link>
            </nav>
        </div>
    </section>
</template>
