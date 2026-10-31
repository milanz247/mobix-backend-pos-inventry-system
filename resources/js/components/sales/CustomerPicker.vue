<script setup lang="ts">
import { ref, watch, onBeforeUnmount } from 'vue';
import { api } from '@/lib/api';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
export type SavedCustomer = {
    id: number;
    name: string;
    phone: string;
    email: string | null;
    credit_limit: string | null;
    credit_days: number;
};
withDefaults(defineProps<{ currency?: string }>(), { currency: 'LKR' });
const model = defineModel<SavedCustomer | null>({ default: null });
const query = ref(''),
    results = ref<SavedCustomer[]>([]),
    loading = ref(false),
    error = ref('');
let timer: ReturnType<typeof setTimeout> | undefined;
let request: AbortController | undefined;
watch(
    query,
    () => {
        clearTimeout(timer);
        request?.abort();
        timer = setTimeout(async () => {
            request = new AbortController();
            const current = request;
            loading.value = true;
            error.value = '';
            try {
                results.value = await api<SavedCustomer[]>(
                    `/customers/search?search=${encodeURIComponent(query.value)}`,
                    { signal: current.signal },
                );
            } catch (e) {
                if (!current.signal.aborted)
                    error.value =
                        e instanceof Error
                            ? e.message
                            : 'Could not find customers.';
            } finally {
                if (!current.signal.aborted) loading.value = false;
            }
        }, 250);
    },
    { immediate: true },
);
onBeforeUnmount(() => {
    clearTimeout(timer);
    request?.abort();
});
</script>
<template>
    <div class="space-y-2">
        <Label for="saved-customer-search">Find saved customer</Label
        ><Input
            id="saved-customer-search"
            v-model="query"
            placeholder="Search name or phone"
            autocomplete="off"
        />
        <p v-if="loading" role="status" class="text-sm">Searching…</p>
        <p v-if="error" role="alert" class="text-sm text-destructive">
            {{ error }}
        </p>
        <div
            class="max-h-40 overflow-y-auto rounded border"
            aria-label="Customer search results"
        >
            <button
                v-for="customer in results"
                :key="customer.id"
                type="button"
                class="block w-full border-b px-3 py-2 text-left text-sm hover:bg-muted"
                :aria-pressed="model?.id === customer.id"
                @click="model = customer"
            >
                {{ customer.name }} · {{ customer.phone }}
            </button>
            <p
                v-if="!loading && !results.length"
                class="p-3 text-sm text-muted-foreground"
            >
                No customers found.
            </p>
        </div>
        <div v-if="model" class="rounded border p-3 text-sm">
            <p class="font-semibold">
                Selected: {{ model.name }} · {{ model.phone }}
            </p>
            <p>
                Credit limit:
                {{
                    model.credit_limit === null
                        ? 'Not set'
                        : `${currency} ${Number(model.credit_limit).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
                }}
                · {{ model.credit_days }} days
            </p>
            <button
                type="button"
                class="mt-1 text-primary underline"
                @click="model = null"
            >
                Clear selection
            </button>
        </div>
    </div>
</template>
