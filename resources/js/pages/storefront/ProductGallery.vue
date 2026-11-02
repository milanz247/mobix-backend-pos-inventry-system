<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{ product: { id: number; name: string; images: string[] } }>();
const form = useForm<{ images: File[] }>({ images: [] });
const select = (event: Event) => { form.images = Array.from((event.target as HTMLInputElement).files ?? []); };
const save = () => form.post(`/storefront/products/${props.product.id}/gallery`, { preserveScroll: true, onSuccess: () => form.reset() });
</script>

<template>
    <Head :title="`Photos · ${product.name}`" />
    <section class="page-shell">
        <Heading :title="`Product photos · ${product.name}`" description="Add genuine product and condition photos. The main catalog image remains the first photo." />
        <Link href="/storefront/products" class="text-sm underline">Back to storefront products</Link>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4"><img v-for="(image, index) in product.images" :key="image" :src="image" :alt="`${product.name} photo ${index + 1}`" class="aspect-square w-full rounded-lg border bg-white object-contain p-4" /></div>
        <form class="form-section space-y-4" @submit.prevent="save">
            <label class="grid gap-2 text-sm font-medium">Replace additional photos<input type="file" multiple accept="image/jpeg,image/png,image/webp" required @change="select" /></label>
            <p class="text-sm text-muted-foreground">Choose 1–8 JPEG, PNG or WebP photos, up to 5 MB each and 8000 pixels per side. Photos appear in selection order. Saving replaces the additional gallery; the main product image is unchanged.</p>
            <InputError v-for="(error, field) in form.errors" :key="field" :message="error" />
            <p v-if="form.recentlySuccessful" role="status" class="text-sm">Gallery saved.</p>
            <Button :disabled="form.processing || !form.images.length">{{ form.processing ? 'Uploading…' : 'Save gallery' }}</Button>
        </form>
    </section>
</template>
