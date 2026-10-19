<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import AuthBackground from '@/components/AuthBackground.vue';
import { ShieldCheck } from '@lucide/vue';
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
        class="relative isolate flex min-h-svh items-center justify-center px-5 py-12 text-slate-900 dark:text-white"
    >
        <AuthBackground />
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
                    class="flex size-11 items-center justify-center rounded-2xl bg-primary text-primary-foreground shadow-lg shadow-primary/15"
                >
                    <AppLogoIcon class="size-6 fill-current" />
                </span>
                <span class="text-lg font-semibold tracking-tight">{{
                    name
                }}</span>
            </Link>
            <div
                class="rounded-3xl border border-white bg-white/90 p-7 shadow-xl shadow-slate-200/40 backdrop-blur-sm sm:p-9 dark:border-slate-800 dark:bg-slate-900/90 dark:shadow-none"
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
                class="mt-6 flex items-center justify-center gap-2 text-xs text-slate-500 dark:text-slate-400"
            >
                <ShieldCheck class="size-3.5" aria-hidden="true" />
                Secure access for your team
            </p>
        </main>
    </div>
</template>
