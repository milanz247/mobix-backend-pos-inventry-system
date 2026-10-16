<script setup lang="ts">
type Props = {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
};

withDefaults(defineProps<Props>(), {
    variant: 'default',
});
</script>

<template>
    <header :class="variant === 'small' ? 'space-y-1' : 'space-y-1.5'">
        <h2
            :class="
                variant === 'small'
                    ? 'text-base font-semibold tracking-tight'
                    : 'text-xl font-semibold tracking-tight text-balance sm:text-2xl'
            "
        >
            {{ title }}
        </h2>
        <p
            v-if="description"
            class="max-w-3xl text-sm leading-5 text-muted-foreground"
        >
            {{ description }}
        </p>
    </header>
</template>
