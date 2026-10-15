<script setup lang="ts">
defineProps<{
    label: string;
}>();
</script>

<template>
    <div
        class="flex items-center justify-end gap-1.5"
        role="group"
        :aria-label="`Actions for ${label}`"
    >
        <slot />
    </div>
</template>
