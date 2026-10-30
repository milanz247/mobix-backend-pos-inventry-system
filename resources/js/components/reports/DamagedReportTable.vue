<script setup lang="ts">
import type { DamageRow } from '@/types/damage';
import { damageMoney, damageQuantity } from '@/types/damage';
defineProps<{ rows: DamageRow[]; showCosts: boolean; currency: string }>();
</script>
<template>
    <table class="data-table w-full text-sm">
        <thead>
            <tr>
                <th>Product / variant</th>
                <th>Model</th>
                <th>SKU / Barcode</th>
                <th>Category / Brand</th>
                <th>Location</th>
                <th class="text-right">Damaged quantity</th>
                <th v-if="showCosts" class="text-right">Cost value</th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="row in rows" :key="row.id">
                <td>
                    <p class="font-medium">{{ row.product }}</p>
                    <p class="text-xs text-muted-foreground">
                        {{ row.variant }}
                    </p>
                </td>
                <td>{{ row.model_number || '—' }}</td>
                <td>
                    <p class="font-mono text-xs">{{ row.sku }}</p>
                    <p class="text-xs text-muted-foreground">
                        {{ row.barcode || '—' }}
                    </p>
                </td>
                <td>
                    {{ row.category }}
                    <p class="text-xs text-muted-foreground">
                        {{ row.brand || 'Unbranded' }}
                    </p>
                </td>
                <td>{{ row.location }}</td>
                <td class="text-right font-semibold tabular-nums">
                    {{
                        row.tracking_mode === 'serialized'
                            ? row.devices
                            : damageQuantity(row.quantity)
                    }}
                    <p class="text-xs font-normal text-muted-foreground">
                        {{
                            row.tracking_mode === 'serialized'
                                ? 'IMEI devices'
                                : row.unit
                        }}
                    </p>
                </td>
                <td v-if="showCosts" class="text-right tabular-nums">
                    {{ damageMoney(row.value ?? '0', currency) }}
                </td>
            </tr>
            <tr v-if="rows.length === 0">
                <td
                    :colspan="showCosts ? 7 : 6"
                    class="py-12 text-center text-muted-foreground"
                >
                    No damaged items match these filters.
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
