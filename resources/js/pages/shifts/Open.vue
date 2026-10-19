<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index, store } from '@/routes/shifts';
defineProps<{
    locations: { id: number; name: string }[];
    cashierName: string;
    currency: string;
}>();
const form = useForm({
    operation_key: crypto.randomUUID(),
    location_id: '',
    opening_cash: '',
    opening_note: '',
});
function submit(): void {
    form.post(store().url);
}
</script>
<template>
    <section class="page-shell max-w-3xl space-y-6">
        <Head title="Open shift" />
        <Heading
            title="Open shift"
            description="Count the opening float before accepting payments. Opening time is recorded automatically."
        />
        <form
            class="surface-panel space-y-5 p-5 sm:p-6"
            @submit.prevent="submit"
        >
            <p class="text-sm">
                Cashier: <strong>{{ cashierName }}</strong>
            </p>
            <p class="text-xs text-muted-foreground">
                One active shift per cashier. Each cashier operates a separate
                drawer at their assigned location.
            </p>
            <div class="grid gap-2">
                <Label for="location">Location</Label
                ><select
                    id="location"
                    v-model="form.location_id"
                    required
                    class="h-10 rounded-lg border bg-background px-3"
                >
                    <option disabled value="">
                        Select authorized location
                    </option>
                    <option
                        v-for="location in locations"
                        :key="location.id"
                        :value="location.id"
                    >
                        {{ location.name }}
                    </option></select
                ><InputError :message="form.errors.location_id" />
            </div>
            <div class="grid gap-2">
                <Label for="opening-cash">Opening cash ({{ currency }})</Label
                ><Input
                    id="opening-cash"
                    v-model="form.opening_cash"
                    type="text"
                    inputmode="decimal"
                    required
                    placeholder="0.00"
                /><InputError :message="form.errors.opening_cash" />
            </div>
            <div class="grid gap-2">
                <Label for="opening-note">Opening note (optional)</Label
                ><textarea
                    id="opening-note"
                    v-model="form.opening_note"
                    maxlength="2000"
                    rows="3"
                    class="rounded-lg border bg-background p-3 text-sm"
                /><InputError :message="form.errors.opening_note" />
            </div>
            <InputError :message="form.errors.operation_key" />
            <div class="flex gap-3">
                <Button :disabled="form.processing || !locations.length">{{
                    form.processing ? 'Opening…' : 'Open shift'
                }}</Button
                ><Button variant="outline" as-child
                    ><Link :href="index()">Cancel</Link></Button
                >
            </div>
            <p v-if="!locations.length" class="text-sm text-destructive">
                No active authorized locations. Ask a manager to assign a
                location.
            </p>
        </form>
    </section>
</template>
