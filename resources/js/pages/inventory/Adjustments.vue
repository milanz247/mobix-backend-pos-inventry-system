<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { create, store } from '@/routes/inventory/adjustments';
type Option = {
    id: number;
    name?: string;
    label?: string;
    code?: string;
    sku?: string;
};
const props = defineProps<{ locations: Option[]; variants: Option[] }>();
const form = useForm({
    operation_key: crypto.randomUUID(),
    location_id: props.locations[0]?.id ?? '',
    product_variant_id: '',
    direction: 'increase',
    quantity: '1',
    reason: 'physical_count',
    reference: '',
    notes: '',
});
const confirming = ref(false);
const submit = (): void =>
    form.post(store.url(), {
        onSuccess: () => {
            form.reset();
            form.operation_key = crypto.randomUUID();
            confirming.value = false;
        },
    });
defineOptions({
    layout: { breadcrumbs: [{ title: 'Stock adjustments', href: create() }] },
});
</script>
<template>
    <Head title="Stock adjustments" />
    <section class="page-shell">
        <Heading
            title="Stock adjustments"
            description="Correct quantity stock without rewriting its history."
        />
        <form
            class="surface-panel space-y-5 p-5"
            @submit.prevent="confirming ? submit() : (confirming = true)"
        >
            <div class="grid gap-4 md:grid-cols-2">
                <label class="grid gap-2 text-sm font-medium"
                    >Location<select v-model="form.location_id" required>
                        <option
                            v-for="item in locations"
                            :key="item.id"
                            :value="item.id"
                        >
                            {{ item.name }}
                        </option></select
                    ><InputError :message="form.errors.location_id" /></label
                ><label class="grid gap-2 text-sm font-medium"
                    >Quantity-tracked variant<select
                        v-model="form.product_variant_id"
                        required
                    >
                        <option disabled value="">Choose variant</option>
                        <option
                            v-for="item in variants"
                            :key="item.id"
                            :value="item.id"
                        >
                            {{ item.label }} · {{ item.sku }}
                        </option></select
                    ><InputError
                        :message="form.errors.product_variant_id" /></label
                ><label class="grid gap-2 text-sm font-medium"
                    >Direction<select v-model="form.direction">
                        <option value="increase">Increase stock</option>
                        <option value="decrease">Decrease stock</option>
                    </select></label
                ><label class="grid gap-2 text-sm font-medium"
                    >Quantity<Input
                        v-model="form.quantity"
                        type="number"
                        min="0.001"
                        step="0.001"
                        required /><InputError
                        :message="form.errors.quantity" /></label
                ><label class="grid gap-2 text-sm font-medium"
                    >Reason<select v-model="form.reason">
                        <option value="physical_count">
                            Physical count correction
                        </option>
                        <option value="damaged">Damaged goods</option>
                        <option value="missing">Missing stock</option>
                        <option value="data_correction">Data correction</option>
                        <option value="other">Other authorized reason</option>
                    </select></label
                ><label class="grid gap-2 text-sm font-medium"
                    >Reference<Input v-model="form.reference" /></label
                ><label class="grid gap-2 text-sm font-medium md:col-span-2"
                    >Notes<textarea v-model="form.notes" rows="3" />
                </label>
            </div>
            <div
                v-if="confirming"
                class="rounded-xl border border-warning/30 bg-warning/5 p-4 text-sm"
            >
                Confirm a <strong>{{ form.direction }}</strong> adjustment of
                <strong>{{ form.quantity }}</strong
                >. A decrease is rejected if stock would become negative.
            </div>
            <div class="flex justify-end gap-2">
                <Button
                    v-if="confirming"
                    type="button"
                    variant="outline"
                    @click="confirming = false"
                    >Back</Button
                ><Button type="submit" :disabled="form.processing">{{
                    confirming ? 'Confirm and post' : 'Review adjustment'
                }}</Button>
            </div>
        </form>
    </section>
</template>
