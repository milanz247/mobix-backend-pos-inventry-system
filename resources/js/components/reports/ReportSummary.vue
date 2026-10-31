<script setup lang="ts">
defineProps<{ items: { label: string; value: string | number }[] }>();
</script>
<template>
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <article
            v-for="item in items"
            :key="item.label"
            class="surface-panel p-4"
        >
            <p class="text-xs text-muted-foreground">{{ item.label }}</p>
            <p class="mt-2 text-xl font-semibold tabular-nums">
                {{ item.value }}
            </p>
        </article>
    </div>
</template>
