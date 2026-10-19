<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { outOfStock, expenses, currentShift } from '@/routes/reports';
import { reports as salesReports } from '@/routes/sales';
import type { Auth } from '@/types';
defineProps<{ active: 'stock' | 'expenses' | 'shift' | 'sales' }>();
const page = usePage<{ auth: Auth }>();
const items = [
    {
        key: 'sales',
        label: 'Sales',
        permission: 'sales.reports.view',
        href: salesReports(),
    },
    {
        key: 'stock',
        label: 'Out-of-Stock Products',
        permission: 'inventory.view',
        href: outOfStock(),
    },
    {
        key: 'expenses',
        label: 'Expenses',
        permission: 'shifts.view',
        href: expenses(),
    },
    {
        key: 'shift',
        label: 'Current Shift',
        permission: 'shifts.view',
        href: currentShift(),
    },
];
</script>
<template>
    <nav class="flex flex-wrap gap-2" aria-label="Reports">
        <template v-for="item in items" :key="item.key">
            <Link
                v-if="page.props.auth.permissions.includes(item.permission)"
                :href="item.href"
                class="rounded-lg border px-3 py-2 text-sm font-medium transition-colors"
                :class="
                    active === item.key
                        ? 'border-primary/25 bg-primary/10 text-primary'
                        : 'bg-card hover:bg-accent'
                "
                :aria-current="active === item.key ? 'page' : undefined"
                >{{ item.label }}</Link
            >
        </template>
    </nav>
</template>
