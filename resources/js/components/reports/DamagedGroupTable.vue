<script setup lang="ts">
import type { DamageGroup } from '@/types/damage';
import { damageMoney, damageQuantity } from '@/types/damage';
defineProps<{
    groups: DamageGroup[];
    groupBy: string;
    showCosts: boolean;
    currency: string;
}>();
</script>
<template>
    <table class="data-table responsive-table w-full text-sm">
        <thead>
            <tr>
                <th>
                    {{
                        groupBy === 'branch'
                            ? 'Branch'
                            : groupBy === 'brand'
                              ? 'Brand'
                              : 'Category'
                    }}
                </th>
                <th class="text-right">SKUs</th>
                <th class="text-right">Quantity items</th>
                <th class="text-right">IMEI devices</th>
                <th v-if="showCosts" class="text-right">Cost value</th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="group in groups" :key="group.name">
                <td data-label="Details" class="font-medium">
                    {{ group.name }}
                </td>
                <td data-label="SKUs" class="text-right">{{ group.skus }}</td>
                <td data-label="Quantity items" class="text-right tabular-nums">
                    {{ damageQuantity(group.quantity) }}
                </td>
                <td data-label="IMEI devices" class="text-right">
                    {{ group.devices }}
                </td>
                <td
                    data-label="Cost value"
                    v-if="showCosts"
                    class="text-right tabular-nums"
                >
                    {{ damageMoney(group.value ?? '0', currency) }}
                </td>
            </tr>
            <tr v-if="groups.length === 0">
                <td
                    :colspan="showCosts ? 5 : 4"
                    class="p-6 text-center text-muted-foreground"
                >
                    No damaged stock.
                </td>
            </tr>
        </tbody>
    </table>
</template>

<style scoped>
.data-table th {
    white-space: normal;
}
.data-table td {
    overflow-wrap: anywhere;
}
</style>
