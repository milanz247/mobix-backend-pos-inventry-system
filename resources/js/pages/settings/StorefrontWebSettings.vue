<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    announcement: {
        text: string;
        direction: 'left' | 'right';
        enabled: boolean;
    };
}>();
const form = useForm({
    text: props.announcement.text,
    direction: props.announcement.direction,
    enabled: props.announcement.enabled,
});

function save() {
    form.put('/settings/storefront-web', { preserveScroll: true });
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Web Settings', href: '/settings/storefront-web' },
        ],
    },
});
</script>

<template>
    <Head title="Ecommerce Web Settings" />
    <section class="page-shell max-w-4xl space-y-6">
        <Heading
            title="Web Settings"
            description="Edit the announcement strip shown above the Ecommerce navigation."
        />
        <form
            class="space-y-5 rounded-xl border bg-card p-5 sm:p-6"
            @submit.prevent="save"
        >
            <div>
                <h2 class="font-semibold">Announcement text</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Keep the message short and factual. Changes appear across
                    the storefront after saving.
                </p>
            </div>
            <label class="block space-y-1 text-sm font-medium">
                Text
                <textarea
                    v-model="form.text"
                    maxlength="240"
                    rows="3"
                    required
                    class="w-full rounded-md border bg-background px-3 py-2"
                />
                <span class="block text-xs font-normal text-muted-foreground"
                    >{{ form.text.length }}/240 characters</span
                >
                <span class="text-red-600">{{ form.errors.text }}</span>
            </label>
            <fieldset class="space-y-2">
                <legend class="text-sm font-medium">Movement direction</legend>
                <label class="flex items-center gap-2 text-sm"
                    ><input
                        v-model="form.direction"
                        type="radio"
                        value="left"
                    />
                    Right to left</label
                >
                <label class="flex items-center gap-2 text-sm"
                    ><input
                        v-model="form.direction"
                        type="radio"
                        value="right"
                    />
                    Left to right</label
                >
                <span class="text-sm text-red-600">{{
                    form.errors.direction
                }}</span>
            </fieldset>
            <label class="flex items-center gap-2 text-sm font-medium"
                ><input v-model="form.enabled" type="checkbox" /> Show
                announcement</label
            >
            <span class="text-sm text-red-600">{{ form.errors.enabled }}</span>
            <div class="rounded-lg border">
                <p
                    class="border-b px-3 py-2 text-xs font-medium text-muted-foreground"
                >
                    Preview
                </p>
                <div
                    v-if="form.enabled"
                    class="overflow-hidden bg-primary py-2 text-primary-foreground"
                >
                    <div
                        :class="
                            form.direction === 'right'
                                ? 'announcement-preview-right'
                                : 'announcement-preview-left'
                        "
                        class="w-max text-xs font-semibold whitespace-nowrap"
                    >
                        <span
                            class="inline-block min-w-[100vw] px-8 text-center"
                            >{{
                                form.text || 'Your announcement appears here'
                            }}</span
                        ><span
                            aria-hidden="true"
                            class="inline-block min-w-[100vw] px-8 text-center"
                            >{{
                                form.text || 'Your announcement appears here'
                            }}</span
                        >
                    </div>
                </div>
                <p v-else class="p-3 text-sm text-muted-foreground">
                    The announcement is hidden.
                </p>
            </div>
            <Button type="submit" :disabled="form.processing">{{
                form.processing ? 'Saving…' : 'Save Web Settings'
            }}</Button>
        </form>
    </section>
</template>

<style scoped>
.announcement-preview-left {
    animation: preview-left 30s linear infinite;
}
.announcement-preview-right {
    animation: preview-right 30s linear infinite;
}
@keyframes preview-left {
    to {
        transform: translateX(-50%);
    }
}
@keyframes preview-right {
    from {
        transform: translateX(-50%);
    }
}
@media (prefers-reduced-motion: reduce) {
    .announcement-preview-left,
    .announcement-preview-right {
        animation: none;
    }
}
</style>
