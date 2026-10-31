<script setup lang="ts">
import { computed } from 'vue';
const props = defineProps<{ changes: Record<string, unknown> }>();
function flatten(value: unknown, prefix = ''): Record<string, string> {
    if (value !== null && typeof value === 'object')
        return Object.fromEntries(
            Object.entries(value).flatMap(([key, child]) =>
                Object.entries(
                    flatten(child, prefix ? `${prefix}.${key}` : key),
                ),
            ),
        );
    return prefix ? { [prefix]: value === null ? '—' : String(value) } : {};
}
const rows = computed(() => {
    const paired = 'before' in props.changes || 'after' in props.changes;
    const before = paired ? flatten(props.changes.before ?? {}) : {};
    const after = flatten(paired ? (props.changes.after ?? {}) : props.changes);
    return [...new Set([...Object.keys(before), ...Object.keys(after)])]
        .filter((key) => before[key] !== after[key])
        .map((key) => ({
            field: key.replaceAll('_', ' ').split('.').join(' › '),
            before: before[key] ?? '—',
            after: after[key] ?? '—',
        }));
});
</script>
<template>
    <div class="mt-3 max-h-72 space-y-2 overflow-y-auto">
        <div
            v-for="row in rows"
            :key="row.field"
            class="rounded border bg-background p-2 text-left text-xs"
        >
            <p class="font-semibold capitalize">{{ row.field }}</p>
            <p class="mt-1 break-all text-muted-foreground">
                Before: {{ row.before }}
            </p>
            <p class="break-all">After: {{ row.after }}</p>
        </div>
        <p v-if="!rows.length" class="text-xs text-muted-foreground">
            No visible field changes.
        </p>
    </div>
</template>
