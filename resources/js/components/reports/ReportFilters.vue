<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { reactive, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
type Field = {
    key: string;
    label: string;
    type?: 'date' | 'text' | 'search' | 'select';
    options?: { value: string | number; label: string }[];
    emptyLabel?: string;
};
const props = defineProps<{
    action: string;
    preserve?: string[];
    filters: Record<string, string | number>;
    fields: Field[];
}>();
const page = usePage();
const values = reactive<Record<string, string | number>>({});
const loading = ref(false);
function loadFilters(): void {
    for (const field of props.fields) {
        values[field.key] = props.filters[field.key] ?? '';
    }
}
loadFilters();
watch(() => props.filters, loadFilters);
function apply(): void {
    router.get(
        props.action,
        Object.fromEntries(
            Object.entries({
                ...Object.fromEntries(
                    (props.preserve ?? [])
                        .filter((key) => props.filters[key] !== undefined)
                        .map((key) => [key, props.filters[key]]),
                ),
                ...values,
            }).filter(([, value]) => value !== ''),
        ),
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
            onStart: () => {
                loading.value = true;
            },
            onFinish: () => {
                loading.value = false;
            },
        },
    );
}
function reset(): void {
    for (const field of props.fields) {
        values[field.key] = '';
    }
    apply();
}
</script>
<template>
    <section class="surface-panel p-4">
        <form
            class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
            @submit.prevent="apply"
        >
            <label
                v-for="field in fields"
                :key="field.key"
                class="grid gap-1.5 text-xs font-medium"
            >
                {{ field.label }}
                <select
                    v-if="field.type === 'select'"
                    v-model="values[field.key]"
                    class="h-9 min-w-0 rounded-lg border border-input bg-background px-2 text-sm"
                >
                    <option value="">{{ field.emptyLabel ?? 'All' }}</option>
                    <option
                        v-for="option in field.options"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
                <Input
                    v-else
                    v-model="values[field.key]"
                    :type="field.type ?? 'text'"
                />
            </label>
            <div class="flex items-end gap-2">
                <Button type="submit" :disabled="loading">{{
                    loading ? 'Loading…' : 'Apply filters'
                }}</Button
                ><Button
                    type="button"
                    variant="outline"
                    :disabled="loading"
                    @click="reset"
                    >Reset</Button
                >
            </div>
        </form>
        <p
            v-for="(error, field) in page.props.errors"
            :key="field"
            class="mt-2 text-sm text-destructive"
            role="alert"
        >
            {{ error }}
        </p>
    </section>
</template>
