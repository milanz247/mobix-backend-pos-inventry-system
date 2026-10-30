<script setup lang="ts">
import type { Auth } from '@/types';
import { Form, Link, usePage } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/brands';

type Option = { value: string; label: string };
type Brand = {
    name: string;
    description: string | null;
    logo_url: string | null;
    status: string;
};

defineProps<{
    action: string;
    method: 'post' | 'put';
    statuses: Option[];
    brand?: Brand;
    embedded?: boolean;
}>();
const emit = defineEmits<{
    cancel: [];
    success: [];
}>();
const canChangeStatus = usePage<{
    auth: Auth;
}>().props.auth.permissions.includes('brands.deactivate');
</script>

<template>
    <Form
        :action="action"
        :method="method"
        class="form-section space-y-7"
        @success="emit('success')"
        v-slot="{ errors, processing, progress }"
    >
        <div class="grid gap-5 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="name">Brand name</Label>
                <Input
                    id="name"
                    name="name"
                    required
                    maxlength="120"
                    :default-value="brand?.name"
                    autocomplete="off"
                />
                <InputError :message="errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="status">Status</Label>
                <select
                    id="status"
                    name="status"
                    :disabled="!!brand && !canChangeStatus"
                    required
                    :value="brand?.status ?? 'active'"
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
                    v-if="brand && !canChangeStatus"
                    type="hidden"
                    name="status"
                    :value="brand.status"
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
                    :value="brand?.description ?? ''"
                    class="w-full"
                />
                <InputError :message="errors.description" />
            </div>
            <div class="grid gap-3 sm:col-span-2">
                <Label for="logo">Brand logo (optional)</Label>
                <div class="flex flex-wrap items-center gap-4">
                    <img
                        v-if="brand?.logo_url"
                        :src="brand.logo_url"
                        :alt="`${brand.name} logo`"
                        class="size-16 rounded-lg border border-border bg-white object-contain p-2"
                    />
                    <Input
                        id="logo"
                        name="logo"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        :required="false"
                        :aria-invalid="Boolean(errors.logo)"
                        class="max-w-lg"
                    />
                </div>
                <p class="text-xs text-muted-foreground">
                    JPG, PNG or WebP, up to 2 MB.
                </p>
                <InputError :message="errors.logo" />
            </div>
        </div>

        <progress
            v-if="progress"
            class="h-2 w-full overflow-hidden rounded-full accent-primary"
            :value="progress.percentage"
            max="100"
            :aria-label="`Upload progress: ${progress.percentage}%`"
        />

        <div class="flex flex-wrap gap-3 border-t border-border/70 pt-6">
            <Button :disabled="processing">{{
                brand ? 'Save changes' : 'Create brand'
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
