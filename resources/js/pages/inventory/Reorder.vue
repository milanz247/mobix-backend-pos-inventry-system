<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import InputError from '@/components/InputError.vue';
type Row = {
    id: number;
    product: string;
    variant: string;
    sku: string;
    quantity: string;
    threshold: string | null;
};
const props = defineProps<{
    rows: { data: Row[]; links: { url: string | null; label: string }[] };
    locations: { id: number; name: string }[];
    locationId: number;
    search: string;
    alerts: boolean;
    canUpdate: boolean;
}>();
const search = ref(props.search),
    location = ref(props.locationId),
    alerts = ref(props.alerts);
const form = useForm({
    location_id: props.locationId,
    product_variant_id: 0,
    quantity: '',
});
const editId = ref<number | null>(null);
function filter() {
    router.get('/inventory/reorder', {
        search: search.value,
        location_id: location.value,
        alerts: alerts.value ? 1 : 0,
    });
}
function edit(row: Row) {
    editId.value = row.id;
    form.product_variant_id = row.id;
    form.quantity = row.threshold ?? '';
    form.clearErrors();
}
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inventory', href: '/inventory' },
            { title: 'Reorder levels', href: '/inventory/reorder' },
        ],
    },
});
</script>
<template>
    <Head title="Reorder levels and low stock" />
    <section class="page-shell">
        <Heading
            title="Reorder levels and low stock"
            description="Set the stock level at which each branch needs to reorder. Leave blank to disable an alert."
        />
        <form
            class="flex flex-wrap items-center gap-3"
            @submit.prevent="filter"
        >
            <Input
                v-model="search"
                class="max-w-sm"
                placeholder="Product or SKU"
                aria-label="Search products"
            /><select
                v-model="location"
                aria-label="Location"
                class="rounded border p-2"
            >
                <option
                    v-for="item in locations"
                    :key="item.id"
                    :value="item.id"
                >
                    {{ item.name }}
                </option></select
            ><label class="flex gap-2"
                ><input v-model="alerts" type="checkbox" />Low stock only</label
            ><Button>Apply filters</Button>
        </form>
        <article
            v-for="row in rows.data"
            :key="row.id"
            class="surface-panel flex flex-wrap items-center justify-between gap-3 p-4"
        >
            <div>
                <h2 class="font-semibold">
                    {{ row.product }} · {{ row.variant }}
                </h2>
                <p class="text-sm text-muted-foreground">{{ row.sku }}</p>
            </div>
            <div>
                Available <strong>{{ row.quantity }}</strong> · Reorder at
                {{ row.threshold ?? 'not set' }}
            </div>
            <Button
                v-if="canUpdate && editId !== row.id"
                variant="outline"
                @click="edit(row)"
                >Set level</Button
            >
            <form
                v-if="editId === row.id"
                class="flex flex-wrap gap-2"
                @submit.prevent="
                    form.put('/inventory/reorder', {
                        onSuccess: () => (editId = null),
                    })
                "
            >
                <Input
                    v-model="form.quantity"
                    type="number"
                    min="0"
                    step="0.001"
                    placeholder="Disabled"
                    aria-label="Reorder quantity"
                    class="w-36"
                /><Button :disabled="form.processing">Save</Button
                ><Button type="button" variant="outline" @click="editId = null"
                    >Cancel</Button
                ><InputError :message="form.errors.quantity" />
            </form>
        </article>
        <p
            v-if="!rows.data.length"
            class="p-8 text-center text-muted-foreground"
        >
            No products match these filters.
        </p>
        <nav aria-label="Stock pages" class="flex flex-wrap gap-2">
            <template v-for="(link, i) in rows.links" :key="i"
                ><Link
                    v-if="link.url"
                    :href="link.url"
                    class="rounded border px-3 py-2 text-sm"
                    v-html="link.label"
            /></template>
        </nav>
    </section>
</template>
