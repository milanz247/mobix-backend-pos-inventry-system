<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Dashboard" />

    <section class="flex min-h-64 flex-1 flex-col gap-3 p-6">
        <h1 class="text-2xl font-semibold tracking-tight">
            Welcome to NalakaMobile
        </h1>
        <p class="text-muted-foreground">Your business workspace is ready.</p>
    </section>
</template>
