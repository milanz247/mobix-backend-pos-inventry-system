<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import AuthBackground from '@/components/AuthBackground.vue';
import { ArrowRight, Boxes, ShoppingBag, ShieldCheck } from '@lucide/vue';
import { login, register } from '@/routes';

defineProps<{
    canRegister: boolean;
}>();

const page = usePage();
const preferences = page.props.businessPreferences as {
    name?: string | null;
    logoUrl?: string | null;
} | null;
const appName = preferences?.name ?? page.props.name;
</script>

<template>
    <Head :title="appName" />

    <main
        class="relative isolate flex min-h-svh items-center justify-center px-5 py-12 text-slate-900 dark:text-white"
    >
        <AuthBackground />
        <section class="w-full max-w-lg text-center">
            <div class="mb-8 flex items-center justify-center gap-3">
                <img
                    v-if="preferences?.logoUrl"
                    :src="preferences.logoUrl"
                    :alt="`${appName} logo`"
                    class="size-11 rounded-xl object-contain"
                />
                <span
                    v-else
                    class="flex size-11 items-center justify-center rounded-2xl bg-primary text-primary-foreground shadow-lg shadow-primary/15"
                >
                    <AppLogoIcon class="size-6 fill-current" />
                </span>
                <span class="text-lg font-semibold tracking-tight">{{
                    appName
                }}</span>
            </div>
            <div
                class="rounded-3xl border border-white bg-white/90 p-8 shadow-xl shadow-slate-200/40 backdrop-blur-sm sm:p-10 dark:border-slate-800 dark:bg-slate-900/90 dark:shadow-none"
            >
                <span
                    class="mb-5 inline-flex items-center gap-2 rounded-full border border-primary/10 bg-primary/5 px-3 py-1.5 text-xs font-medium text-primary"
                >
                    <span class="size-1.5 rounded-full bg-primary" />
                    Your business workspace
                </span>
                <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">
                    Ready for a<br />productive day?
                </h1>
                <p
                    class="mt-3 text-sm leading-6 text-slate-600 dark:text-slate-300"
                >
                    Your sales, stock, and team.<br />Everything you need, in
                    one place.
                </p>
                <div class="mt-8 flex flex-col gap-3">
                    <Link
                        :href="login()"
                        class="flex items-center justify-center gap-2 rounded-xl bg-primary px-4 py-3 text-sm font-semibold text-primary-foreground shadow-sm transition hover:bg-primary/90"
                    >
                        Log in
                        <ArrowRight class="size-4" aria-hidden="true" />
                    </Link>
                    <Link
                        v-if="canRegister"
                        :href="register()"
                        class="rounded-lg border border-slate-200 px-4 py-3 text-sm font-medium transition hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800"
                    >
                        Set up business
                    </Link>
                </div>
                <div
                    class="mt-8 flex flex-wrap items-center justify-center gap-x-5 gap-y-3 border-t border-slate-100 pt-6 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400"
                >
                    <span class="flex items-center gap-1.5"
                        ><ShoppingBag class="size-3.5" aria-hidden="true" />
                        Sales</span
                    >
                    <span class="flex items-center gap-1.5"
                        ><Boxes class="size-3.5" aria-hidden="true" />
                        Inventory</span
                    >
                    <span class="flex items-center gap-1.5"
                        ><ShieldCheck class="size-3.5" aria-hidden="true" />
                        Team access</span
                    >
                </div>
            </div>
            <p class="mt-6 text-xs text-slate-500 dark:text-slate-400">
                © {{ new Date().getFullYear() }} {{ appName }}
            </p>
        </section>
    </main>
</template>
