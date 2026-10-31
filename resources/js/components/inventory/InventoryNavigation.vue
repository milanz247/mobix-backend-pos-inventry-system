<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { Auth } from '@/types';
const page = usePage<{ auth: Auth }>();
const items = computed(() =>
    [
        ['Overview', '/inventory', 'inventory.view'],
        ['Damaged stock', '/inventory/damaged', 'damaged-stock.view'],
        ['Reorder levels / alerts', '/inventory/reorder', 'inventory.view'],
        ['Stock by location', '/inventory/locations', 'inventory.view'],
        ['Stock receiving', '/inventory/receiving', 'inventory.receive'],
        ['Opening stock', '/inventory/opening-stock', 'inventory.opening'],
        ['Adjustments', '/inventory/adjustments', 'inventory.adjust'],
        ['Transfers', '/inventory/transfers', 'inventory.transfer'],
        ['IMEI lookup', '/inventory/devices', 'serialized-devices.view'],
        ['Movement history', '/inventory/movements', 'stock-movements.view'],
    ].filter((item) => page.props.auth.permissions.includes(item[2])),
);
const current = computed(() => page.url.split('?')[0]);
</script>
<template>
    <div class="border-b bg-card/60 px-4 py-3 sm:px-6">
        <label for="inventory-page" class="sr-only">Inventory page</label
        ><select
            id="inventory-page"
            class="w-full sm:hidden"
            :value="current"
            @change="router.visit(($event.target as HTMLSelectElement).value)"
        >
            <option v-for="item in items" :key="item[1]" :value="item[1]">
                {{ item[0] }}
            </option>
        </select>
        <nav
            class="hidden flex-wrap items-center gap-1.5 sm:flex"
            aria-label="Inventory pages"
        >
            <Link
                v-for="item in items.filter((x) =>
                    [
                        '/inventory',
                        '/inventory/damaged',
                        '/inventory/reorder',
                        '/inventory/locations',
                    ].includes(x[1]),
                )"
                :key="item[1]"
                :href="item[1]"
                class="rounded-md px-3 py-2 text-xs font-medium"
                :class="
                    current === item[1]
                        ? 'bg-primary text-primary-foreground'
                        : 'text-muted-foreground hover:bg-muted'
                "
                :aria-current="current === item[1] ? 'page' : undefined"
                >{{ item[0] }}</Link
            >
            <select
                aria-label="Stock actions and history"
                class="ml-auto max-w-xs text-xs"
                :value="
                    items
                        .filter(
                            (x) =>
                                ![
                                    '/inventory',
                                    '/inventory/damaged',
                                    '/inventory/reorder',
                                    '/inventory/locations',
                                ].includes(x[1]),
                        )
                        .some((x) => x[1] === current)
                        ? current
                        : ''
                "
                @change="
                    ($event.target as HTMLSelectElement).value &&
                    router.visit(($event.target as HTMLSelectElement).value)
                "
            >
                <option value="">Stock actions and history</option>
                <option
                    v-for="item in items.filter(
                        (x) =>
                            ![
                                '/inventory',
                                '/inventory/damaged',
                                '/inventory/reorder',
                                '/inventory/locations',
                            ].includes(x[1]),
                    )"
                    :key="item[1]"
                    :value="item[1]"
                >
                    {{ item[0] }}
                </option>
            </select>
        </nav>
    </div>
</template>
