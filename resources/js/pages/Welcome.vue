<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
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
        class="flex min-h-svh items-center justify-center bg-white px-5 py-12 text-slate-900 dark:bg-slate-950 dark:text-white"
    >
        <section class="w-full max-w-md text-center">
            <div class="mb-8 flex items-center justify-center gap-3">
                <img
                    v-if="preferences?.logoUrl"
                    :src="preferences.logoUrl"
                    :alt="`${appName} logo`"
                    class="size-11 rounded-xl object-contain"
                />
                <span
                    v-else
                    class="flex size-11 items-center justify-center rounded-xl bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-white"
                >
                    <AppLogoIcon class="size-6 fill-current" />
                </span>
                <span class="text-lg font-semibold tracking-tight">{{
                    appName
                }}</span>
            </div>
            <div
                class="rounded-2xl border border-slate-200 bg-white p-8 sm:p-10 dark:border-slate-800 dark:bg-slate-900"
            >
                <h1 class="text-2xl font-semibold tracking-tight">Welcome</h1>
                <p
                    class="mt-3 text-sm leading-6 text-slate-600 dark:text-slate-300"
                >
                    Sign in to continue to your business workspace.
                </p>
                <div class="mt-8 flex flex-col gap-3">
                    <Link
                        :href="login()"
                        class="rounded-lg bg-slate-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-700 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
                    >
                        Log in
                    </Link>
                    <Link
                        v-if="canRegister"
                        :href="register()"
                        class="rounded-lg border border-slate-200 px-4 py-3 text-sm font-medium transition hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800"
                    >
                        Set up business
                    </Link>
                </div>
            </div>
            <p class="mt-6 text-xs text-slate-500 dark:text-slate-400">
                © {{ new Date().getFullYear() }} {{ appName }}
            </p>
        </section>
    </main>
</template>
