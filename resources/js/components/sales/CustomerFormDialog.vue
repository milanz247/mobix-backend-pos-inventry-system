<script setup lang="ts">
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import type { Customer } from '@/types/customer';
const open = defineModel<boolean>('open', { required: true });
const props = defineProps<{ customer?: Customer | null; currency: string }>();
const form = useForm({
    name: '',
    phone: '',
    email: '',
    credit_limit: '',
    credit_days: 30,
});
watch(open, (value) => {
    if (value) {
        form.clearErrors();
        form.name = props.customer?.name ?? '';
        form.phone = props.customer?.phone ?? '';
        form.email = props.customer?.email ?? '';
        form.credit_limit = props.customer?.credit_limit ?? '';
        form.credit_days = props.customer?.credit_days ?? 30;
    }
});
function save() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset();
        },
    };
    if (props.customer) form.put(`/customers/${props.customer.id}`, options);
    else form.post('/customers', options);
}
</script>
<template>
    <Dialog v-model:open="open"
        ><DialogContent class="max-h-[calc(100dvh-2rem)] overflow-y-auto"
            ><DialogHeader
                ><DialogTitle>{{
                    customer ? 'Edit customer' : 'Add customer'
                }}</DialogTitle
                ><DialogDescription
                    >Contact details and credit terms. Leave the limit blank for
                    no configured limit.</DialogDescription
                ></DialogHeader
            >
            <form class="grid gap-4" @submit.prevent="save">
                <div class="grid gap-1.5">
                    <Label for="customer-form-name">Name</Label
                    ><Input
                        id="customer-form-name"
                        v-model="form.name"
                        required
                        maxlength="150"
                        autocomplete="name"
                    /><InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="customer-form-phone">Phone</Label
                    ><Input
                        id="customer-form-phone"
                        v-model="form.phone"
                        required
                        autocomplete="tel"
                        inputmode="tel"
                    /><InputError :message="form.errors.phone" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="customer-form-email">Email</Label
                    ><Input
                        id="customer-form-email"
                        v-model="form.email"
                        type="email"
                        autocomplete="email"
                    /><InputError :message="form.errors.email" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-1.5">
                        <Label for="customer-form-limit"
                            >Credit limit ({{ currency }})</Label
                        ><Input
                            id="customer-form-limit"
                            v-model="form.credit_limit"
                            type="number"
                            min="0"
                            step="0.01"
                        /><InputError :message="form.errors.credit_limit" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="customer-form-days"
                            >Payment terms (days)</Label
                        ><Input
                            id="customer-form-days"
                            v-model="form.credit_days"
                            type="number"
                            min="0"
                            max="365"
                            required
                        /><InputError :message="form.errors.credit_days" />
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t pt-4">
                    <Button
                        type="button"
                        variant="outline"
                        @click="open = false"
                        >Cancel</Button
                    ><Button :disabled="form.processing">{{
                        customer ? 'Save changes' : 'Add customer'
                    }}</Button>
                </div>
            </form></DialogContent
        ></Dialog
    >
</template>
