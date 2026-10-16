<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ShoppingCart } from '@lucide/vue';
import { computed } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { getInitials } from '@/composables/useInitials';
import { index as posIndex } from '@/routes/pos';
import type { Auth, BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const page = usePage<{ auth: Auth }>();
const user = computed(() => page.props.auth.user);
const canAccessPos = computed(() =>
    page.props.auth.permissions.includes('sales.pos'),
);
</script>

<template>
    <header
        class="sticky top-0 z-20 flex h-16 shrink-0 items-center gap-2 border-b border-border/70 bg-card/95 px-3 shadow-sm shadow-slate-950/5 backdrop-blur-md sm:px-4"
    >
        <div class="flex min-w-0 items-center gap-2">
            <SidebarTrigger
                class="-ml-1 size-9 rounded-lg border border-transparent hover:border-border hover:bg-accent"
            />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </template>
        </div>

        <div class="ml-auto flex shrink-0 items-center gap-2 sm:gap-3">
            <Button
                v-if="canAccessPos"
                as-child
                size="sm"
                class="h-9 px-3 shadow-md shadow-primary/15"
            >
                <Link :href="posIndex()" prefetch>
                    <ShoppingCart />
                    <span class="hidden sm:inline">Point of sale</span>
                    <span class="sm:hidden">POS</span>
                </Link>
            </Button>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="outline"
                        class="h-10 gap-2 rounded-full border-border/70 bg-background py-1 pl-1 pr-2 shadow-xs hover:bg-accent sm:gap-2.5 sm:pr-3"
                        :aria-label="`Open ${user.name} profile menu`"
                    >
                        <Avatar
                            class="size-8 shrink-0 rounded-full ring-2 ring-primary/10"
                        >
                            <AvatarImage
                                v-if="user.avatar"
                                :src="user.avatar"
                                :alt="user.name"
                            />
                            <AvatarFallback
                                class="rounded-full bg-primary/10 text-xs font-semibold text-primary"
                            >
                                {{ getInitials(user.name) }}
                            </AvatarFallback>
                        </Avatar>
                        <span
                            class="hidden max-w-36 truncate text-sm font-medium sm:inline"
                        >
                            {{ user.name }}
                        </span>
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-60">
                    <UserMenuContent :user="user" />
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </header>
</template>
