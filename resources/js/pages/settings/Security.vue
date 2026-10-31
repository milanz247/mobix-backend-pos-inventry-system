<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import SecuritySettings from '@/components/settings/SecuritySettings.vue';
import type { Props } from '@/components/settings/SecuritySettings.vue';
defineProps<Props>();
</script>
<template>
    <Head title="My profile — security" /><Link
        href="/settings/profile"
        class="text-sm text-primary"
        >← My profile</Link
    ><SecuritySettings v-bind="$props" />
</template>
