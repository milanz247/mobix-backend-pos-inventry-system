<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { dashboard, login, register } from '@/routes';

defineProps<{
    canRegister: boolean;
}>();
</script>

<template>
    <Head title="NalakaMobile" />

    <main
        class="flex min-h-svh items-center justify-center bg-background p-6 text-foreground"
    >
        <section class="w-full max-w-xl space-y-8 text-center">
            <div class="space-y-3">
                <p class="text-sm font-semibold tracking-wide text-primary">
                    NalakaMobile
                </p>
                <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">
                    Mobile shop management
                </h1>
                <p class="text-muted-foreground">
                    Sign in to manage your business account.
                </p>
            </div>

            <nav
                aria-label="Account"
                class="flex flex-col justify-center gap-3 sm:flex-row"
            >
                <Link
                    v-if="$page.props.auth.user"
                    :href="dashboard()"
                    class="rounded-md bg-primary px-5 py-2.5 text-sm font-medium text-primary-foreground"
                >
                    Continue to dashboard
                </Link>
                <template v-else>
                    <Link
                        :href="login()"
                        class="rounded-md bg-primary px-5 py-2.5 text-sm font-medium text-primary-foreground"
                    >
                        Log in
                    </Link>
                    <Link
                        v-if="canRegister"
                        :href="register()"
                        class="rounded-md border border-input px-5 py-2.5 text-sm font-medium hover:bg-accent"
                    >
                        Register your business
                    </Link>
                </template>
            </nav>
        </section>
    </main>
</template>
