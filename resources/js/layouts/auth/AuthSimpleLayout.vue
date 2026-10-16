<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home } from '@/routes';

const page = usePage();
const businessPreferences = page.props.businessPreferences as {
    name?: string | null;
    logoUrl?: string | null;
} | null;
const name = businessPreferences?.name ?? page.props.name;

const {
    title = '',
    description = '',
    wide = false,
} = defineProps<{
    title?: string;
    description?: string;
    wide?: boolean;
}>();
</script>

<template>
    <div
        class="flex min-h-svh items-center justify-center bg-white px-5 py-12 text-slate-900 dark:bg-slate-950 dark:text-white"
    >
        <main :class="wide ? 'max-w-3xl' : 'max-w-md'" class="w-full">
            <Link
                :href="home()"
                class="mb-10 flex items-center justify-center gap-3"
            >
                <img
                    v-if="businessPreferences?.logoUrl"
                    :src="businessPreferences.logoUrl"
                    :alt="`${name} logo`"
                    class="size-11 rounded-xl object-contain"
                />
                <span
                    v-else
                    class="flex size-11 items-center justify-center rounded-xl bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-white"
                >
                    <AppLogoIcon class="size-6 fill-current" />
                </span>
                <span class="text-lg font-semibold tracking-tight">{{
                    name
                }}</span>
            </Link>
            <div
                class="rounded-2xl border border-slate-200 bg-white p-7 sm:p-9 dark:border-slate-800 dark:bg-slate-900"
            >
                <div class="mb-8 space-y-2">
                    <h1 class="text-2xl font-semibold tracking-tight">
                        {{ title }}
                    </h1>
                    <p
                        class="text-sm leading-6 text-slate-600 dark:text-slate-300"
                    >
                        {{ description }}
                    </p>
                </div>
                <slot />
            </div>
            <p
                class="mt-6 text-center text-xs text-slate-500 dark:text-slate-400"
            >
                Secure access for your team
            </p>
        </main>
    </div>
</template>
