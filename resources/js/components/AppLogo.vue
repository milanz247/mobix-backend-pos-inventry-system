<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

const page = usePage();
const appName = page.props.name;
const businessPreferences = page.props.businessPreferences;
const name = businessPreferences?.name ?? appName;
const logoUrl = businessPreferences?.logoUrl;
</script>

<template>
    <div
        class="flex aspect-square size-8 items-center justify-center overflow-hidden rounded-lg bg-linear-to-br from-blue-500 to-indigo-600 text-white shadow-sm shadow-blue-950/30"
    >
        <img
            v-if="logoUrl"
            :src="logoUrl"
            :alt="`${name} logo`"
            class="size-full bg-white object-contain p-0.5"
        />
        <AppLogoIcon v-else class="size-4 fill-current text-white" />
    </div>
    <div class="ml-1 grid flex-1 text-left text-sm leading-tight">
        <span class="truncate font-semibold text-white">{{ name }}</span>
        <span class="truncate text-[0.68rem] text-sidebar-foreground/55"
            >Retail &amp; repair workspace</span
        >
    </div>
</template>
