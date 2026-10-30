<script setup lang="ts">
import type { Auth } from '@/types';
import { Form, Link, usePage } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/categories';

type Option = { value: string; label: string };
type Category = { name: string; description: string | null; status: string };

defineProps<{
    action: string;
    method: 'post' | 'put';
    statuses: Option[];
    category?: Category;
    embedded?: boolean;
}>();
const emit = defineEmits<{
    cancel: [];
    success: [];
}>();
const canChangeStatus = usePage<{
    auth: Auth;
}>().props.auth.permissions.includes('categories.deactivate');
</script>

<template>
    <Form
        :action="action"
        :method="method"
        class="form-section space-y-7"
        @success="emit('success')"
        v-slot="{ errors, processing }"
    >
        <div class="grid gap-5 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="name">Category name</Label>
                <Input
                    id="name"
                    name="name"
                    required
                    maxlength="120"
                    :default-value="category?.name"
                    autocomplete="off"
                />
                <InputError :message="errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="status">Status</Label>
                <select
                    id="status"
                    name="status"
                    :disabled="!!category && !canChangeStatus"
                    required
                    :value="category?.status ?? 'active'"
                    class="w-full"
                >
                    <option
                        v-for="status in statuses"
                        :key="status.value"
                        :value="status.value"
                    >
                        {{ status.label }}
                    </option>
                </select>
                <input
                    v-if="category && !canChangeStatus"
                    type="hidden"
                    name="status"
                    :value="category.status"
                />
                <InputError :message="errors.status" />
            </div>
            <div class="grid gap-2 sm:col-span-2">
                <Label for="description">Description (optional)</Label>
                <textarea
                    id="description"
                    name="description"
                    rows="3"
                    maxlength="2000"
                    :value="category?.description ?? ''"
                    class="w-full"
                />
                <InputError :message="errors.description" />
            </div>
        </div>

        <div class="flex flex-wrap gap-3 border-t border-border/70 pt-6">
            <Button :disabled="processing">{{
                category ? 'Save changes' : 'Create category'
            }}</Button>
            <Button v-if="!embedded" variant="outline" as-child
                ><Link :href="index()">Cancel</Link></Button
            >
            <Button
                v-else
                type="button"
                variant="outline"
                @click="emit('cancel')"
                >Cancel</Button
            >
        </div>
    </Form>
</template>
