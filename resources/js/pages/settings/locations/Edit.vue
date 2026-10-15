<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import LocationForm from '@/components/settings/LocationForm.vue';
import { index, update } from '@/routes/locations';

type Option = { value: string; label: string };
type Location = {
    id: number;
    name: string;
    code: string;
    type: string;
    phone: string;
    email: string | null;
    address: string;
    city: string;
    is_main: boolean;
    status: string;
};
defineProps<{ location: Location; types: Option[]; statuses: Option[] }>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Edit location', href: index() }] },
});
</script>

<template>
    <Head :title="`Edit ${location.name}`" />
    <div class="space-y-6">
        <Heading
            title="Edit location"
            :description="
                location.is_main
                    ? 'The main location must remain active.'
                    : 'Update this location’s details and status.'
            "
        />
        <LocationForm
            :action="update.url(location.id)"
            method="put"
            :location="location"
            :types="types"
            :statuses="statuses"
        />
    </div>
</template>
