<script setup lang="ts">
import { Form, Link } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/locations';

type Option = { value: string; label: string };
type Location = {
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

defineProps<{
    action: string;
    method: 'post' | 'put';
    types: Option[];
    statuses: Option[];
    location?: Location;
}>();
</script>

<template>
    <Form
        :action="action"
        :method="method"
        class="form-section space-y-7"
        v-slot="{ errors, processing }"
    >
        <div class="grid gap-5 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="name">Location name</Label>
                <Input
                    id="name"
                    name="name"
                    required
                    :default-value="location?.name"
                    autocomplete="organization"
                />
                <InputError :message="errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="code">Location code</Label>
                <Input
                    id="code"
                    name="code"
                    required
                    :default-value="location?.code"
                    class="uppercase"
                />
                <InputError :message="errors.code" />
            </div>
            <div class="grid gap-2">
                <Label for="type">Location type</Label>
                <select
                    id="type"
                    name="type"
                    required
                    :value="location?.type ?? 'shop'"
                    class="w-full"
                >
                    <option
                        v-for="option in types"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
                <InputError :message="errors.type" />
            </div>
            <div class="grid gap-2">
                <Label for="status">Status</Label>
                <select
                    id="status"
                    name="status"
                    required
                    :value="location?.status ?? 'active'"
                    :disabled="location?.is_main"
                    class="w-full disabled:opacity-60"
                >
                    <option
                        v-for="option in statuses"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
                <input
                    v-if="location?.is_main"
                    type="hidden"
                    name="status"
                    value="active"
                />
                <InputError :message="errors.status" />
            </div>
            <div class="grid gap-2">
                <Label for="phone">Contact number</Label>
                <Input
                    id="phone"
                    name="phone"
                    type="tel"
                    required
                    :default-value="location?.phone"
                    autocomplete="tel"
                />
                <InputError :message="errors.phone" />
            </div>
            <div class="grid gap-2">
                <Label for="email">Email (optional)</Label>
                <Input
                    id="email"
                    name="email"
                    type="email"
                    :default-value="location?.email ?? ''"
                    autocomplete="email"
                />
                <InputError :message="errors.email" />
            </div>
            <div class="grid gap-2 sm:col-span-2">
                <Label for="address">Address</Label>
                <textarea
                    id="address"
                    name="address"
                    rows="3"
                    required
                    :value="location?.address"
                    autocomplete="street-address"
                    class="min-h-24 w-full resize-y"
                />
                <InputError :message="errors.address" />
            </div>
            <div class="grid gap-2">
                <Label for="city">City / town</Label>
                <Input
                    id="city"
                    name="city"
                    required
                    :default-value="location?.city"
                    autocomplete="address-level2"
                />
                <InputError :message="errors.city" />
            </div>
        </div>

        <div class="flex flex-wrap gap-3 border-t border-border/70 pt-6">
            <Button :disabled="processing">{{
                location ? 'Save changes' : 'Create location'
            }}</Button>
            <Button variant="outline" as-child
                ><Link :href="index()">Cancel</Link></Button
            >
        </div>
    </Form>
</template>
