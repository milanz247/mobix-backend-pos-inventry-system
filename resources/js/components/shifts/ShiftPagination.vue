<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { PageLink } from '@/types/shift';
defineProps<{ links: PageLink[] }>();
</script>
<template>
    <nav
        v-if="links.length > 3"
        class="flex flex-wrap gap-2 p-4"
        aria-label="Pagination"
    >
        <template v-for="(link, position) in links" :key="position">
            <Link
                v-if="link.url"
                :href="link.url"
                preserve-scroll
                class="rounded-lg border px-3 py-2 text-sm"
                :class="
                    link.active
                        ? 'bg-primary text-primary-foreground'
                        : 'hover:bg-accent'
                "
                :aria-current="link.active ? 'page' : undefined"
                >{{
                    position === 0
                        ? 'Previous'
                        : position === links.length - 1
                          ? 'Next'
                          : link.label
                }}</Link
            >
            <span v-else class="px-3 py-2 text-sm text-muted-foreground">{{
                position === 0
                    ? 'Previous'
                    : position === links.length - 1
                      ? 'Next'
                      : link.label
            }}</span>
        </template>
    </nav>
</template>
