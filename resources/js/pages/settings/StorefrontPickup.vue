<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Location = {
    id: number; name: string; type: string; status: string; address: string; city: string;
    public_code: string | null; display_name: string | null; selection_priority: number; is_active: boolean;
};

defineProps<{ locations: Location[] }>();
const editingId = ref<number | null>(null);
const form = useForm({ public_code: '', display_name: '', selection_priority: 100, is_active: false });

function edit(location: Location) {
    editingId.value = location.id;
    form.public_code = location.public_code ?? '';
    form.display_name = location.display_name ?? '';
    form.selection_priority = location.selection_priority;
    form.is_active = location.is_active;
    form.clearErrors();
}

function cancel() {
    editingId.value = null;
    form.reset();
    form.clearErrors();
}

function save() {
    if (editingId.value === null) return;
    form.put(`/settings/storefront-pickup/${editingId.value}`, { onSuccess: cancel, preserveScroll: true });
}

defineOptions({ layout: { breadcrumbs: [{ title: 'Storefront pickup', href: '/settings/storefront-pickup' }] } });
</script>

<template>
    <Head title="Storefront pickup" />
    <div class="page-shell space-y-6">
        <Heading title="Storefront pickup" description="Choose which active shops customers may select for pickup. Locations are private until explicitly enabled." />
        <p class="text-sm text-muted-foreground">The public address comes from the shop's current location record. Changing pickup details does not edit accepted order snapshots.</p>
        <p v-if="locations.length === 0" class="rounded-xl border p-6 text-sm text-muted-foreground">No business locations are available to configure.</p>
        <section v-for="location in locations" :key="location.id" class="rounded-xl border p-5 space-y-3">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold">{{ location.name }}</h2>
                    <p class="text-sm text-muted-foreground">{{ location.type }} · {{ location.status }} · {{ location.is_active ? 'Pickup enabled' : 'Pickup disabled' }}</p>
                    <p class="mt-2 text-sm">{{ location.address }}, {{ location.city }}</p>
                    <p v-if="location.public_code" class="mt-1 text-xs text-muted-foreground">Public code: {{ location.public_code }} · Priority: {{ location.selection_priority }}</p>
                </div>
                <Button type="button" variant="outline" @click="edit(location)">Configure pickup</Button>
            </div>
            <form v-if="editingId === location.id" class="grid gap-4 border-t pt-4 md:grid-cols-2" @submit.prevent="save">
                <label class="space-y-1 text-sm">Public code <Input v-model="form.public_code" required maxlength="80" placeholder="shop-code" /><span class="block text-red-600">{{ form.errors.public_code }}</span></label>
                <label class="space-y-1 text-sm">Customer-facing name (optional) <Input v-model="form.display_name" maxlength="150" /><span class="block text-red-600">{{ form.errors.display_name }}</span></label>
                <label class="space-y-1 text-sm">Selection priority <Input v-model="form.selection_priority" type="number" min="0" max="1000000" required /><span class="block text-red-600">{{ form.errors.selection_priority }}</span></label>
                <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" :disabled="!form.is_active && (location.type !== 'shop' || location.status !== 'active')" /> Available for customer pickup<span class="text-red-600">{{ form.errors.is_active }}</span></label>
                <p v-if="location.type !== 'shop' || location.status !== 'active'" class="text-sm text-muted-foreground md:col-span-2">Only an active shop can be published for pickup.</p>
                <div class="flex gap-2 md:col-span-2"><Button type="submit" :disabled="form.processing">Save pickup settings</Button><Button type="button" variant="outline" @click="cancel">Cancel</Button></div>
            </form>
        </section>
    </div>
</template>
