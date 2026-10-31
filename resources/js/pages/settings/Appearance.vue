<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Monitor, Moon, RotateCcw, Sun } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useAppearance, type Appearance } from '@/composables/useAppearance';
import {
    contrastText,
    getDisplayColors,
    themePresets,
    useThemeColors,
    type ThemeColors,
} from '@/composables/useThemeColors';

const { appearance, resolvedAppearance, updateAppearance } = useAppearance();
const { colors, updateColors, resetColors } = useThemeColors();
const previewColors = computed(() =>
    getDisplayColors(colors.value, resolvedAppearance.value === 'dark'),
);
const appearanceOptions: {
    value: Appearance;
    label: string;
    description: string;
    icon: typeof Sun;
}[] = [
    {
        value: 'system',
        label: 'System',
        description: 'Follow this device',
        icon: Monitor,
    },
    {
        value: 'light',
        label: 'Light',
        description: 'Bright workspace',
        icon: Sun,
    },
    {
        value: 'dark',
        label: 'Dark',
        description: 'Low-light workspace',
        icon: Moon,
    },
];

function setColor(key: keyof ThemeColors, event: Event): void {
    const input = event.target as HTMLInputElement;
    updateColors({ ...colors.value, [key]: input.value.toUpperCase() });
}

function applyPreset(preset: (typeof themePresets)[number]): void {
    updateColors(preset.colors);
}

function reset(): void {
    resetColors();
    updateAppearance('system');
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Appearance & colors', href: '/settings/appearance' },
        ],
    },
});
</script>

<template>
    <Head title="Appearance & colors" />

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                variant="small"
                title="Appearance & colors"
                description="Choose a comfortable theme for your workspace. Changes preview immediately and are saved in this browser."
            />
            <Button type="button" variant="outline" @click="reset">
                <RotateCcw class="size-4" />
                Reset to default
            </Button>
        </div>

        <section class="surface-panel p-4 sm:p-5">
            <div class="mb-4">
                <h2 class="font-semibold">Display mode</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Set the overall light or dark appearance.
                </p>
            </div>
            <div class="grid gap-3 sm:grid-cols-3">
                <button
                    v-for="option in appearanceOptions"
                    :key="option.value"
                    type="button"
                    class="flex items-center gap-3 rounded-xl border p-3 text-left transition-colors hover:bg-accent/40"
                    :class="
                        appearance === option.value
                            ? 'border-primary bg-primary/5 ring-1 ring-primary/20'
                            : 'border-border'
                    "
                    :aria-pressed="appearance === option.value"
                    @click="updateAppearance(option.value)"
                >
                    <span
                        class="flex size-10 items-center justify-center rounded-lg bg-primary/10 text-primary"
                    >
                        <component :is="option.icon" class="size-5" />
                    </span>
                    <span>
                        <span class="block text-sm font-semibold">{{
                            option.label
                        }}</span>
                        <span class="block text-xs text-muted-foreground">{{
                            option.description
                        }}</span>
                    </span>
                    <Badge v-if="appearance === option.value" class="ml-auto"
                        >Active</Badge
                    >
                </button>
            </div>
        </section>

        <section class="surface-panel p-4 sm:p-5">
            <div class="mb-4">
                <h2 class="font-semibold">Color themes</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Pick a coordinated set, then fine-tune the main colors
                    below.
                </p>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <button
                    v-for="preset in themePresets"
                    :key="preset.id"
                    type="button"
                    class="flex items-center gap-3 rounded-xl border p-3 text-left transition-colors hover:bg-accent/40"
                    :class="
                        JSON.stringify(colors) === JSON.stringify(preset.colors)
                            ? 'border-primary ring-1 ring-primary/20'
                            : 'border-border'
                    "
                    :aria-pressed="
                        JSON.stringify(colors) === JSON.stringify(preset.colors)
                    "
                    @click="applyPreset(preset)"
                >
                    <span class="flex shrink-0 gap-1">
                        <span
                            class="size-5 rounded-full border border-black/10"
                            :style="{ backgroundColor: preset.colors.primary }"
                        />
                        <span
                            class="size-5 rounded-full border border-black/10"
                            :style="{ backgroundColor: preset.colors.page }"
                        />
                        <span
                            class="size-5 rounded-full border border-black/10"
                            :style="{ backgroundColor: preset.colors.sidebar }"
                        />
                    </span>
                    <span class="min-w-0">
                        <span class="block text-sm font-semibold">{{
                            preset.name
                        }}</span>
                        <span
                            class="block truncate text-xs text-muted-foreground"
                            >{{ preset.description }}</span
                        >
                    </span>
                </button>
            </div>
        </section>

        <div
            class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(320px,0.8fr)]"
        >
            <section class="surface-panel p-4 sm:p-5">
                <div class="mb-4">
                    <h2 class="font-semibold">Customize colors</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Text colors are selected automatically to keep contrast
                        readable.
                    </p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label
                        v-for="item in [
                            {
                                key: 'primary',
                                title: 'Buttons and highlights',
                                help: 'Main actions, links, and selected items',
                            },
                            {
                                key: 'page',
                                title: 'Page background',
                                help: 'Workspace canvas behind cards',
                            },
                            {
                                key: 'sidebar',
                                title: 'Sidebar background',
                                help: 'Navigation panel and footer',
                            },
                        ]"
                        :key="item.key"
                        class="flex items-center justify-between gap-3 rounded-xl border border-border p-3"
                    >
                        <span>
                            <span class="block text-sm font-medium">{{
                                item.title
                            }}</span>
                            <span class="block text-xs text-muted-foreground">{{
                                item.help
                            }}</span>
                        </span>
                        <input
                            type="color"
                            class="size-11 shrink-0 cursor-pointer rounded-lg border border-border bg-card p-1"
                            :aria-label="item.title"
                            :value="colors[item.key as keyof ThemeColors]"
                            @input="
                                setColor(item.key as keyof ThemeColors, $event)
                            "
                        />
                    </label>
                </div>
                <p class="mt-4 text-xs text-muted-foreground">
                    Your color choices affect this browser only. The built-in
                    status colors for errors, warnings, and success remain
                    distinct.
                </p>
            </section>

            <section class="surface-panel overflow-hidden p-4 sm:p-5">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <div>
                        <h2 class="font-semibold">Live preview</h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            See your colors on common controls.
                        </p>
                    </div>
                    <Badge variant="secondary">Preview</Badge>
                </div>
                <div
                    class="overflow-hidden rounded-xl border border-border"
                    :style="{ backgroundColor: previewColors.page }"
                >
                    <div class="flex min-h-52">
                        <aside
                            class="hidden w-36 shrink-0 p-3 text-xs sm:block"
                            :style="{
                                backgroundColor: previewColors.sidebar,
                                color: contrastText(previewColors.sidebar),
                            }"
                        >
                            <div class="mb-4 font-semibold">NalakaMobile</div>
                            <div class="space-y-2 opacity-85">
                                <div class="rounded-md px-2 py-1.5">
                                    Dashboard
                                </div>
                                <div
                                    class="rounded-md px-2 py-1.5"
                                    :style="{
                                        backgroundColor:
                                            'color-mix(in srgb, ' +
                                            previewColors.primary +
                                            ' 30%, ' +
                                            previewColors.sidebar +
                                            ')',
                                    }"
                                >
                                    Inventory
                                </div>
                                <div class="rounded-md px-2 py-1.5">Sales</div>
                            </div>
                        </aside>
                        <div
                            class="min-w-0 flex-1 p-4"
                            :style="{ color: contrastText(previewColors.page) }"
                        >
                            <div class="mb-3 text-xs font-medium opacity-65">
                                Dashboard / Today
                            </div>
                            <div
                                class="rounded-xl border border-black/10 p-3 shadow-sm"
                                :style="{
                                    backgroundColor:
                                        'color-mix(in srgb, ' +
                                        previewColors.page +
                                        ' 96%, ' +
                                        contrastText(previewColors.page) +
                                        ')',
                                    color: contrastText(previewColors.page),
                                }"
                            >
                                <p class="text-xs opacity-70">Today’s sales</p>
                                <p class="mt-1 text-xl font-bold">
                                    LKR 128,450.00
                                </p>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        class="rounded-lg px-3 py-2 text-xs font-semibold"
                                        :style="{
                                            backgroundColor:
                                                previewColors.primary,
                                            color: contrastText(
                                                previewColors.primary,
                                            ),
                                        }"
                                    >
                                        New sale
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-lg border border-current/20 px-3 py-2 text-xs font-semibold opacity-85"
                                    >
                                        View report
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>
