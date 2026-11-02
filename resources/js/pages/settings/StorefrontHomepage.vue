<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';

type Banner = {
    id: number;
    headline: string;
    eyebrow: string | null;
    description: string | null;
    button_text: string | null;
    button_href: string | null;
    image_alt: string | null;
    image_url: string | null;
    is_active: boolean;
    updated_at: string | null;
};
defineProps<{ banners: Banner[] }>();
const dialogOpen = ref(false);
const editing = ref<Banner | null>(null);
const changingStatus = ref<number | null>(null);
const statusError = ref('');
const form = useForm({
    headline: '',
    eyebrow: '',
    description: '',
    button_text: '',
    button_href: '',
    image_alt: '',
    image: null as File | null,
});

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
}
function openEdit(banner: Banner) {
    editing.value = banner;
    form.headline = banner.headline;
    form.eyebrow = banner.eyebrow ?? '';
    form.description = banner.description ?? '';
    form.button_text = banner.button_text ?? '';
    form.button_href = banner.button_href ?? '';
    form.image_alt = banner.image_alt ?? '';
    form.image = null;
    form.clearErrors();
    dialogOpen.value = true;
}
function save() {
    const path = editing.value
        ? `/settings/storefront-homepage/${editing.value.id}`
        : '/settings/storefront-homepage';
    form.post(path, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
            form.reset();
        },
    });
}
function changeStatus(banner: Banner) {
    changingStatus.value = banner.id;
    statusError.value = '';
    router.patch(
        `/settings/storefront-homepage/${banner.id}/status`,
        { is_active: !banner.is_active },
        {
            preserveScroll: true,
            onError: (errors) => {
                statusError.value =
                    Object.values(errors)[0] ??
                    'Could not update banner status.';
            },
            onFinish: () => {
                changingStatus.value = null;
            },
        },
    );
}
defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Homepage banners',
                href: '/settings/storefront-homepage',
            },
        ],
    },
});
</script>

<template>
    <Head title="Homepage banners" />
    <section class="page-shell space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Homepage banners"
                description="Manage the main Ecommerce homepage image and message. Activate one banner at a time."
            />
            <Button type="button" @click="openCreate">Create banner</Button>
        </div>
        <p class="text-sm text-muted-foreground">
            Recommended image: 1920 × 1080 pixels (16:9). Minimum: 800 × 450.
            Maximum input: 6000 × 4000, 20 megapixels and 5 MB. Large images are
            resized to fit within 1920 × 1080 and saved as WebP.
        </p>
        <p v-if="statusError" role="alert" class="text-sm text-red-600">
            {{ statusError }}
        </p>
        <div
            v-if="banners.length === 0"
            class="rounded-xl border border-dashed p-10 text-center"
        >
            <p class="font-medium">No banners yet</p>
            <p class="mt-1 text-sm text-muted-foreground">
                The default Ecommerce hero remains visible until you activate a
                banner.
            </p>
            <Button class="mt-5" type="button" @click="openCreate"
                >Create your first banner</Button
            >
        </div>
        <div v-else class="overflow-x-auto rounded-xl border bg-card">
            <table class="w-full min-w-[700px] text-left text-sm">
                <thead class="border-b bg-muted/40 text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3 font-medium">Image</th>
                        <th class="px-4 py-3 font-medium">Banner</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium">Updated</th>
                        <th class="px-4 py-3 text-right font-medium">
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="banner in banners"
                        :key="banner.id"
                        class="border-b last:border-b-0"
                    >
                        <td class="px-4 py-3">
                            <img
                                v-if="banner.image_url"
                                :src="banner.image_url"
                                :alt="banner.image_alt || banner.headline"
                                class="h-16 w-28 rounded-md bg-muted object-cover"
                            />
                            <span
                                v-else
                                class="flex h-16 w-28 items-center justify-center rounded-md bg-muted text-xs text-muted-foreground"
                                >No image</span
                            >
                        </td>
                        <td class="max-w-xs px-4 py-3">
                            <p class="font-medium">{{ banner.headline }}</p>
                            <p
                                v-if="banner.eyebrow"
                                class="truncate text-xs text-muted-foreground"
                            >
                                {{ banner.eyebrow }}
                            </p>
                        </td>
                        <td class="px-4 py-3">
                            <span
                                :class="
                                    banner.is_active
                                        ? 'bg-emerald-100 text-emerald-800'
                                        : 'bg-muted text-muted-foreground'
                                "
                                class="rounded-full px-2.5 py-1 text-xs font-medium"
                                >{{
                                    banner.is_active ? 'Active' : 'Inactive'
                                }}</span
                            >
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ banner.updated_at ?? '—' }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    type="button"
                                    @click="openEdit(banner)"
                                    >Edit</Button
                                >
                                <Button
                                    size="sm"
                                    :variant="
                                        banner.is_active ? 'outline' : 'default'
                                    "
                                    type="button"
                                    :disabled="changingStatus !== null"
                                    @click="changeStatus(banner)"
                                    >{{
                                        banner.is_active
                                            ? 'Deactivate'
                                            : 'Activate'
                                    }}</Button
                                >
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="text-xs text-muted-foreground">
            The storefront shows the active banner. If none is active, it shows
            the default hero.
        </p>

        <Dialog v-model:open="dialogOpen">
            <DialogContent class="max-h-[90svh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{{
                        editing ? 'Edit banner' : 'Create banner'
                    }}</DialogTitle>
                    <DialogDescription
                        >Choose an image and write the message shown on the
                        Ecommerce homepage.</DialogDescription
                    >
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="save">
                    <label class="block space-y-1 text-sm font-medium"
                        >Headline<Input
                            v-model="form.headline"
                            maxlength="150"
                            required
                        /><span class="text-red-600">{{
                            form.errors.headline
                        }}</span></label
                    >
                    <label class="block space-y-1 text-sm font-medium"
                        >Small heading<Input
                            v-model="form.eyebrow"
                            maxlength="100"
                        /><span class="text-red-600">{{
                            form.errors.eyebrow
                        }}</span></label
                    >
                    <label class="block space-y-1 text-sm font-medium"
                        >Description<textarea
                            v-model="form.description"
                            maxlength="500"
                            rows="3"
                            class="w-full rounded-md border bg-background px-3 py-2"
                        /><span class="text-red-600">{{
                            form.errors.description
                        }}</span></label
                    >
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block space-y-1 text-sm font-medium"
                            >Button label<Input
                                v-model="form.button_text"
                                maxlength="60"
                            /><span class="text-red-600">{{
                                form.errors.button_text
                            }}</span></label
                        >
                        <label class="block space-y-1 text-sm font-medium"
                            >Button destination<select
                                v-model="form.button_href"
                                class="h-10 w-full rounded-md border bg-background px-3"
                            >
                                <option value="">No button</option>
                                <option value="/shop">Shop</option>
                                <option value="/cart">Cart</option></select
                            ><span class="text-red-600">{{
                                form.errors.button_href
                            }}</span></label
                        >
                    </div>
                    <label class="block space-y-1 text-sm font-medium"
                        >Banner image
                        <span class="font-normal text-muted-foreground">{{
                            editing
                                ? '(leave blank to keep current image)'
                                : '(required)'
                        }}</span>
                        <input
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            :required="!editing"
                            class="block w-full rounded-md border p-2"
                            @change="
                                form.image =
                                    ($event.target as HTMLInputElement)
                                        .files?.[0] ?? null
                            "
                        />
                        <span
                            class="block text-xs font-normal text-muted-foreground"
                            >JPEG, PNG or WebP; 5 MB max. Recommended 1920 ×
                            1080 pixels. Oversized images are automatically
                            resized.</span
                        >
                        <span class="text-red-600">{{
                            form.errors.image
                        }}</span>
                    </label>
                    <img
                        v-if="editing?.image_url"
                        :src="editing.image_url"
                        alt="Current banner image"
                        class="max-h-36 rounded-md object-contain"
                    />
                    <label class="block space-y-1 text-sm font-medium"
                        >Image description<Input
                            v-model="form.image_alt"
                            maxlength="180"
                        /><span class="text-red-600">{{
                            form.errors.image_alt
                        }}</span></label
                    >
                    <p class="text-xs text-muted-foreground">
                        New banners stay inactive until you activate them from
                        the list. Use only approved promotion claims.
                    </p>
                    <div class="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            @click="dialogOpen = false"
                            >Cancel</Button
                        ><Button type="submit" :disabled="form.processing">{{
                            form.processing ? 'Saving…' : 'Save banner'
                        }}</Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    </section>
</template>
