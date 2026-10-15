<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home } from '@/routes';

const name = usePage().props.name;

defineProps<{
    title?: string;
    description?: string;
    wide?: boolean;
}>();
</script>

<template>
    <div
        class="relative flex min-h-svh flex-col items-center justify-center overflow-hidden bg-background p-4 sm:p-6 md:p-10"
    >
        <div
            class="pointer-events-none absolute inset-x-0 top-0 h-72 bg-linear-to-b from-blue-500/10 to-transparent"
            aria-hidden="true"
        />
        <div :class="wide ? 'max-w-4xl' : 'max-w-sm'" class="w-full">
            <div class="relative flex flex-col gap-7">
                <div class="flex flex-col items-center gap-4">
                    <Link
                        :href="home()"
                        class="flex flex-col items-center gap-2 font-medium"
                    >
                        <div
                            class="mb-1 flex size-12 items-center justify-center rounded-xl bg-linear-to-br from-blue-500 to-indigo-600 text-white shadow-lg shadow-blue-950/20"
                        >
                            <AppLogoIcon
                                class="size-7 fill-current text-white"
                            />
                        </div>
                        <span class="text-base font-bold tracking-tight">{{
                            name
                        }}</span>
                        <span class="sr-only">{{ title }}</span>
                    </Link>
                    <div class="space-y-2 text-center">
                        <h1 class="text-2xl font-bold tracking-tight">
                            {{ title }}
                        </h1>
                        <p
                            class="mx-auto max-w-xl text-center text-sm leading-6 text-muted-foreground"
                        >
                            {{ description }}
                        </p>
                    </div>
                </div>
                <div
                    class="rounded-2xl border border-border/80 bg-card p-5 shadow-xl shadow-slate-950/5 sm:p-7 dark:shadow-black/25"
                >
                    <slot />
                </div>
            </div>
        </div>
    </div>
</template>
