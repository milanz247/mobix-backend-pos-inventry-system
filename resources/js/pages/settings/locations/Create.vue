<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import LocationForm from '@/components/settings/LocationForm.vue';
import { create, store } from '@/routes/locations';

type Option = { value: string; label: string };
defineProps<{ types: Option[]; statuses: Option[] }>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Create location', href: create() }] },
});
</script>

<template>
    <Head title="Create location" />
    <div class="space-y-6">
        <Heading
            title="Create location"
            description="Add a shop, warehouse, repair centre, or other business location."
        />
        <LocationForm
            :action="store.url()"
            method="post"
            :types="types"
            :statuses="statuses"
        />
    </div>
</template>
