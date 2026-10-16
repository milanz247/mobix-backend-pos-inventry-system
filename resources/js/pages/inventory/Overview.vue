<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Boxes, MapPin, PackageCheck, Smartphone } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { index } from '@/routes/inventory';
import { index as locationStock } from '@/routes/inventory/locations';

type Movement = {
    id: number;
    location: string;
    product: string;
    variant: string;
    sku: string;
    type: string;
    delta: string;
    balance_after: string;
    created_at: string;
};
defineProps<{
    metrics: {
        quantity_on_hand: string | number;
        serialized_in_stock: number;
        tracked_variants: number;
        locations: number;
    };
    recentMovements: Movement[];
    locationSummary: {
        id: number;
        name: string;
        quantity_on_hand: string;
        serialized_in_stock: number;
    }[];
    lowStockConfigured: boolean;
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Inventory', href: index() }] },
});
</script>

<template>
    <Head title="Inventory overview" />
    <section class="page-shell">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Inventory overview"
                description="Live stock across only the locations you are authorized to access."
            />
            <Link
                :href="locationStock()"
                class="text-sm font-medium text-primary hover:underline"
                >View stock by location</Link
            >
        </div>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div
                v-for="card in [
                    {
                        label: 'Quantity on hand',
                        value: metrics.quantity_on_hand,
                        icon: Boxes,
                    },
                    {
                        label: 'Serialized devices',
                        value: metrics.serialized_in_stock,
                        icon: Smartphone,
                    },
                    {
                        label: 'Tracked variants',
                        value: metrics.tracked_variants,
                        icon: PackageCheck,
                    },
                    {
                        label: 'Authorized locations',
                        value: metrics.locations,
                        icon: MapPin,
                    },
                ]"
                :key="card.label"
                class="surface-panel flex items-center gap-4 p-5"
            >
                <div
                    class="grid size-11 place-items-center rounded-xl bg-primary/10 text-primary"
                >
                    <component :is="card.icon" class="size-5" />
                </div>
                <div>
                    <p class="text-sm text-muted-foreground">
                        {{ card.label }}
                    </p>
                    <p class="text-2xl font-semibold tabular-nums">
                        {{ card.value }}
                    </p>
                </div>
            </div>
        </div>
        <div
            v-if="!lowStockConfigured"
            class="rounded-xl border border-info/25 bg-info/5 p-4 text-sm"
        >
            Low-stock alerts are not shown because no reorder threshold has been
            configured. No threshold has been fabricated.
        </div>
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            <div
                v-for="location in locationSummary"
                :key="location.id"
                class="surface-panel p-4"
            >
                <h2 class="font-semibold">{{ location.name }}</h2>
                <div class="mt-3 flex gap-6 text-sm">
                    <div>
                        <span class="text-muted-foreground">Quantity</span>
                        <p class="text-lg font-semibold tabular-nums">
                            {{ location.quantity_on_hand }}
                        </p>
                    </div>
                    <div>
                        <span class="text-muted-foreground">Devices</span>
                        <p class="text-lg font-semibold tabular-nums">
                            {{ location.serialized_in_stock }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
        <div class="table-panel">
            <div class="border-b p-4">
                <h2 class="font-semibold">Recent quantity movements</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Location</th>
                            <th>Movement</th>
                            <th class="text-right">Change</th>
                            <th class="text-right">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="movement in recentMovements"
                            :key="movement.id"
                        >
                            <td>
                                <div class="font-medium">
                                    {{ movement.product }}
                                </div>
                                <div class="text-muted-foreground">
                                    {{ movement.variant }} · {{ movement.sku }}
                                </div>
                            </td>
                            <td>{{ movement.location }}</td>
                            <td>
                                <Badge variant="secondary">{{
                                    movement.type.replaceAll('_', ' ')
                                }}</Badge>
                            </td>
                            <td
                                class="text-right font-medium tabular-nums"
                                :class="
                                    Number(movement.delta) >= 0
                                        ? 'text-emerald-600'
                                        : 'text-destructive'
                                "
                            >
                                {{ Number(movement.delta) >= 0 ? '+' : ''
                                }}{{ movement.delta }}
                            </td>
                            <td class="text-right tabular-nums">
                                {{ movement.balance_after }}
                            </td>
                        </tr>
                        <tr v-if="recentMovements.length === 0">
                            <td
                                colspan="5"
                                class="py-10 text-center text-muted-foreground"
                            >
                                No stock movements have been posted yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</template>
