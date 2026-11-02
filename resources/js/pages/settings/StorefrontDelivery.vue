<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Mapping = { id: number; location_id: number; location_name: string; location_status: string; delivery_fee_override: string | null; selection_priority: number; is_active: boolean };
type Area = { id: number; code: string; name: string; description: string | null; base_delivery_fee: string; currency: string; is_active: boolean; mappings: Mapping[] };
type Location = { id: number; name: string; status: string };

defineProps<{ areas: Area[]; locations: Location[]; currency: string }>();

const editingId = ref<number | null>(null);
const area = useForm({ name: '', code: '', description: '', base_delivery_fee: '0.00', is_active: false });
const mapping = useForm({ location_id: '', delivery_fee_override: '', selection_priority: 100, is_active: true });
const mappingAreaId = ref<number | null>(null);

function editArea(value: Area) {
    editingId.value = value.id;
    area.name = value.name;
    area.code = value.code;
    area.description = value.description ?? '';
    area.base_delivery_fee = value.base_delivery_fee;
    area.is_active = value.is_active;
}

function resetArea() {
    editingId.value = null;
    area.reset();
    area.clearErrors();
}

function saveArea() {
    if (editingId.value === null) {
        area.post('/settings/storefront-delivery', { onSuccess: resetArea });
    } else {
        area.put(`/settings/storefront-delivery/${editingId.value}`, { onSuccess: resetArea });
    }
}

function saveMapping() {
    if (mappingAreaId.value === null) return;
    mapping.put(`/settings/storefront-delivery/${mappingAreaId.value}/locations`, {
        onSuccess: () => mapping.reset(),
    });
}

function editMapping(areaId: number, value: Mapping) {
    mappingAreaId.value = areaId;
    mapping.location_id = String(value.location_id);
    mapping.delivery_fee_override = value.delivery_fee_override ?? '';
    mapping.selection_priority = value.selection_priority;
    mapping.is_active = value.is_active;
}

function deactivateMapping(areaId: number, mappingId: number) {
    if (window.confirm('Deactivate this delivery location mapping?')) {
        router.delete(`/settings/storefront-delivery/${areaId}/locations/${mappingId}`, { preserveScroll: true });
    }
}

defineOptions({ layout: { breadcrumbs: [{ title: 'Storefront delivery', href: '/settings/storefront-delivery' }] } });
</script>

<template>
    <Head title="Storefront delivery" />
    <div class="page-shell space-y-6">
        <Heading title="Storefront delivery" description="Configure customer delivery areas, fees, and eligible fulfillment locations." />

        <section class="rounded-xl border p-5 space-y-4">
            <h2 class="text-lg font-semibold">{{ editingId === null ? 'Add delivery area' : 'Edit delivery area' }}</h2>
            <form class="grid gap-4 md:grid-cols-2" @submit.prevent="saveArea">
                <label class="space-y-1 text-sm">Name <Input v-model="area.name" required maxlength="150" /><span class="text-red-600">{{ area.errors.name }}</span></label>
                <label class="space-y-1 text-sm">Code <Input v-model="area.code" required maxlength="80" placeholder="area-code" /><span class="text-red-600">{{ area.errors.code }}</span></label>
                <label class="space-y-1 text-sm">Base fee ({{ currency }}) <Input v-model="area.base_delivery_fee" required type="number" min="0" step="0.01" /><span class="text-red-600">{{ area.errors.base_delivery_fee }}</span></label>
                <label class="space-y-1 text-sm">Description <Input v-model="area.description" maxlength="2000" /><span class="text-red-600">{{ area.errors.description }}</span></label>
                <label class="flex items-center gap-2 text-sm"><input v-model="area.is_active" type="checkbox" /> Active for customers</label>
                <div class="flex gap-2"><Button type="submit" :disabled="area.processing">Save area</Button><Button v-if="editingId !== null" type="button" variant="outline" @click="resetArea">Cancel</Button></div>
            </form>
        </section>

        <section v-for="value in areas" :key="value.id" class="rounded-xl border p-5 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><h2 class="text-lg font-semibold">{{ value.name }}</h2><p class="text-sm text-muted-foreground">{{ value.code }} · {{ value.base_delivery_fee }} {{ value.currency }} · {{ value.is_active ? 'Active' : 'Inactive' }}</p></div>
                <Button variant="outline" @click="editArea(value)">Edit area</Button>
            </div>
            <p v-if="value.description" class="text-sm">{{ value.description }}</p>
            <div class="space-y-2">
                <h3 class="font-medium">Eligible locations</h3>
                <p v-if="value.mappings.length === 0" class="text-sm text-muted-foreground">No locations mapped yet.</p>
                <div v-for="row in value.mappings" :key="row.id" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3 text-sm">
                    <span>{{ row.location_name }} · {{ row.is_active ? 'Active' : 'Inactive' }} · Location {{ row.location_status }} · Priority {{ row.selection_priority }} · Fee {{ row.delivery_fee_override ?? 'Area base' }}</span>
                    <div class="flex gap-2"><Button size="sm" variant="outline" @click="editMapping(value.id, row)">Edit</Button><Button v-if="row.is_active" size="sm" variant="outline" @click="deactivateMapping(value.id, row.id)">Deactivate</Button></div>
                </div>
            </div>
            <Button size="sm" variant="outline" @click="mappingAreaId = value.id; mapping.reset()">Map location</Button>
            <form v-if="mappingAreaId === value.id" class="grid gap-3 rounded-lg border p-4 md:grid-cols-2" @submit.prevent="saveMapping">
                <label class="space-y-1 text-sm">Location<select v-model="mapping.location_id" required class="w-full rounded-md border bg-background p-2"><option value="">Choose location</option><option v-for="location in locations" :key="location.id" :value="String(location.id)">{{ location.name }} ({{ location.status }})</option></select><span class="text-red-600">{{ mapping.errors.location_id }}</span></label>
                <label class="space-y-1 text-sm">Fee override (blank uses base)<Input v-model="mapping.delivery_fee_override" type="number" min="0" step="0.01" /><span class="text-red-600">{{ mapping.errors.delivery_fee_override }}</span></label>
                <label class="space-y-1 text-sm">Selection priority (lower first)<Input v-model="mapping.selection_priority" type="number" min="0" max="1000000" required /><span class="text-red-600">{{ mapping.errors.selection_priority }}</span></label>
                <label class="flex items-center gap-2 text-sm"><input v-model="mapping.is_active" type="checkbox" /> Active mapping</label>
                <div class="flex gap-2"><Button type="submit" :disabled="mapping.processing">Save mapping</Button><Button type="button" variant="outline" @click="mappingAreaId = null">Cancel</Button></div>
            </form>
        </section>
        <p v-if="areas.length === 0" class="rounded-xl border p-6 text-sm text-muted-foreground">No delivery areas configured. New areas remain inactive until enabled.</p>
    </div>
</template>
