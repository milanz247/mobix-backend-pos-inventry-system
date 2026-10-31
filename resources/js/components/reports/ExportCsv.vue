<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
const page = usePage();
const href = computed(() => {
    const [path, query = ''] = page.url.split('?');
    const params = new URLSearchParams(query);
    params.set('export', '1');
    params.delete('page');
    return `${path}?${params}`;
});
</script>
<template>
    <a
        :href="href"
        class="inline-flex w-fit items-center rounded-md border px-4 py-2 text-sm font-medium hover:bg-muted"
        >Download filtered CSV</a
    >
</template>
