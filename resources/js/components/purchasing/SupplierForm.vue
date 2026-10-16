<script setup lang="ts">
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as suppliersIndex } from '@/routes/suppliers';

type Supplier = {
    id: number;
    name: string;
    contact_person: string | null;
    email: string | null;
    phone: string;
    alternate_phone: string | null;
    address: string | null;
    city: string | null;
    notes: string | null;
};

const props = defineProps<{
    action: string;
    method: 'post' | 'put';
    supplier?: Supplier;
}>();

const form = useForm({
    name: props.supplier?.name ?? '',
    contact_person: props.supplier?.contact_person ?? '',
    email: props.supplier?.email ?? '',
    phone: props.supplier?.phone ?? '',
    alternate_phone: props.supplier?.alternate_phone ?? '',
    address: props.supplier?.address ?? '',
    city: props.supplier?.city ?? '',
    notes: props.supplier?.notes ?? '',
});
const errors = computed(() => form.errors as Record<string, string>);

function submit(): void {
    if (props.method === 'post') form.post(props.action);
    else form.put(props.action);
}
</script>

<template>
    <form class="surface-panel space-y-6 p-5 sm:p-6" @submit.prevent="submit">
        <section class="grid gap-4 md:grid-cols-2">
            <div class="grid gap-2">
                <Label for="supplier-name"
                    >Supplier name
                    <span class="text-destructive">*</span></Label
                >
                <Input
                    id="supplier-name"
                    v-model="form.name"
                    required
                    maxlength="150"
                    autocomplete="organization"
                />
                <InputError :message="form.errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="supplier-contact">Contact person</Label>
                <Input
                    id="supplier-contact"
                    v-model="form.contact_person"
                    maxlength="120"
                    autocomplete="name"
                />
                <InputError :message="form.errors.contact_person" />
            </div>
            <div class="grid gap-2">
                <Label for="supplier-phone"
                    >Contact number
                    <span class="text-destructive">*</span></Label
                >
                <Input
                    id="supplier-phone"
                    v-model="form.phone"
                    required
                    maxlength="32"
                    autocomplete="tel"
                />
                <InputError :message="form.errors.phone" />
            </div>
            <div class="grid gap-2">
                <Label for="supplier-alt-phone">Alternate number</Label>
                <Input
                    id="supplier-alt-phone"
                    v-model="form.alternate_phone"
                    maxlength="32"
                />
                <InputError :message="form.errors.alternate_phone" />
            </div>
            <div class="grid gap-2">
                <Label for="supplier-email">Email</Label>
                <Input
                    id="supplier-email"
                    v-model="form.email"
                    type="email"
                    maxlength="254"
                    autocomplete="email"
                />
                <InputError :message="form.errors.email" />
            </div>
            <div class="grid gap-2">
                <Label for="supplier-city">City / town</Label>
                <Input id="supplier-city" v-model="form.city" maxlength="100" />
                <InputError :message="form.errors.city" />
            </div>
            <div class="grid gap-2 md:col-span-2">
                <Label for="supplier-address">Address</Label>
                <textarea
                    id="supplier-address"
                    v-model="form.address"
                    rows="3"
                    maxlength="4000"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-2 focus-visible:ring-ring"
                />
                <InputError :message="form.errors.address" />
            </div>
            <div class="grid gap-2 md:col-span-2">
                <Label for="supplier-notes">Notes</Label>
                <textarea
                    id="supplier-notes"
                    v-model="form.notes"
                    rows="3"
                    maxlength="4000"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-2 focus-visible:ring-ring"
                />
                <InputError :message="form.errors.notes" />
            </div>
        </section>
        <InputError :message="errors.status" />
        <div
            class="flex flex-wrap justify-end gap-2 border-t border-border/70 pt-4"
        >
            <Button as-child variant="outline"
                ><Link :href="suppliersIndex()">Cancel</Link></Button
            >
            <Button type="submit" :disabled="form.processing">{{
                form.processing ? 'Saving…' : 'Save supplier'
            }}</Button>
        </div>
    </form>
</template>
