<script setup lang="ts">
import type { Auth } from '@/types';
import { Form, Link, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';
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
    show_on_homepage: boolean;
    homepage_order: number;
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

const logoPreview = ref<string | null>(null);
const logoError = ref('');
function previewLogo(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (logoPreview.value) URL.revokeObjectURL(logoPreview.value);
    logoPreview.value = null;
    logoError.value = '';
    if (!file) return;
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024) {
        logoError.value = 'Choose a JPG, PNG or WebP image no larger than 2 MB.';
        input.value = '';
        return;
    }
    logoPreview.value = URL.createObjectURL(file);
}
onBeforeUnmount(() => {
    if (logoPreview.value) URL.revokeObjectURL(logoPreview.value);
});
</script>

<template>
    <Form
        :action="action"
        :method="method === 'put' ? 'post' : method"
        class="form-section space-y-7"
        @success="emit('success')"
        v-slot="{ errors, processing, progress }"
    >
        <input v-if="method === 'put'" type="hidden" name="_method" value="PUT" />
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
            <div class="grid gap-2">
                <Label for="show_on_homepage">Ecommerce homepage</Label>
                <select id="show_on_homepage" name="show_on_homepage" :value="brand?.show_on_homepage === false ? '0' : '1'" class="w-full">
                    <option value="1">Show on homepage</option>
                    <option value="0">Hide from homepage</option>
                </select>
                <p class="text-xs text-muted-foreground">Hidden brands remain available in shop filters.</p>
                <InputError :message="errors.show_on_homepage" />
            </div>
            <div class="grid gap-2">
                <Label for="homepage_order">Homepage display order</Label>
                <Input id="homepage_order" name="homepage_order" type="number" min="0" max="9999" step="1" :default-value="brand?.homepage_order ?? 0" required />
                <p class="text-xs text-muted-foreground">Lower numbers appear first. Equal numbers sort by brand name.</p>
                <InputError :message="errors.homepage_order" />
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
                        v-if="logoPreview || brand?.logo_url"
                        :src="logoPreview || brand?.logo_url || ''"
                        alt="Brand logo preview"
                        class="h-24 w-52 rounded-lg border border-border bg-white object-contain p-4"
                    />
                    <Input
                        id="logo"
                        name="logo"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        :required="false"
                        :aria-invalid="Boolean(errors.logo || logoError)"
                        aria-describedby="logo-guidance"
                        @change="previewLogo"
                        class="max-w-lg"
                    />
                </div>
                <p id="logo-guidance" class="text-xs text-muted-foreground">
                    JPG, PNG or WebP, up to 2 MB. Recommended: 600 × 240 px with a transparent background. The storefront fits the whole logo without cropping.
                </p>
                <p class="text-xs text-muted-foreground">
                    Brands marked Show on homepage appear when active and linked to a published, eligible product. Changes appear on the storefront's next page load.
                </p>
                <InputError :message="logoError" />
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
