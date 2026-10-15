<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { store } from '@/routes/register';

defineProps<{
    businessTypes: Array<{
        value: string;
        label: string;
    }>;
    passwordRules: string;
}>();

defineOptions({
    layout: {
        title: 'Set up your business',
        description: 'Create the business and its owner account',
        wide: true,
    },
});
</script>

<template>
    <Head title="Business setup" />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-8"
    >
        <InputError
            v-if="errors.registration"
            :message="errors.registration"
            role="alert"
            class="rounded-md border border-destructive/30 bg-destructive/10 p-3"
        />

        <fieldset
            class="grid gap-5 rounded-xl border border-border/80 bg-background/40 p-5 sm:p-6"
        >
            <div class="grid gap-1">
                <legend class="text-lg font-semibold">Business details</legend>
                <p class="text-sm text-muted-foreground">
                    Information shown on invoices and customer documents.
                </p>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="business_name">Business name</Label>
                    <Input
                        id="business_name"
                        name="business_name"
                        type="text"
                        required
                        v-focus
                        autocomplete="organization"
                        placeholder="Nalaka Mobile"
                        :aria-invalid="Boolean(errors.business_name)"
                    />
                    <InputError :message="errors.business_name" />
                </div>

                <div class="grid gap-2">
                    <Label for="business_type">Business type</Label>
                    <select
                        id="business_type"
                        name="business_type"
                        required
                        :aria-invalid="Boolean(errors.business_type)"
                        class="w-full"
                    >
                        <option value="" disabled selected>
                            Select a business type
                        </option>
                        <option
                            v-for="businessType in businessTypes"
                            :key="businessType.value"
                            :value="businessType.value"
                        >
                            {{ businessType.label }}
                        </option>
                    </select>
                    <InputError :message="errors.business_type" />
                </div>

                <div class="grid gap-2">
                    <Label for="business_email">Business email</Label>
                    <Input
                        id="business_email"
                        name="business_email"
                        type="email"
                        autocomplete="email"
                        placeholder="shop@example.com (optional)"
                        :aria-invalid="Boolean(errors.business_email)"
                    />
                    <InputError :message="errors.business_email" />
                </div>

                <div class="grid gap-2">
                    <Label for="business_phone">
                        Business contact number
                    </Label>
                    <Input
                        id="business_phone"
                        name="business_phone"
                        type="tel"
                        required
                        autocomplete="tel"
                        placeholder="077 123 4567"
                        :aria-invalid="Boolean(errors.business_phone)"
                    />
                    <InputError :message="errors.business_phone" />
                </div>

                <div class="grid gap-2 sm:col-span-2">
                    <Label for="business_address">Business address</Label>
                    <textarea
                        id="business_address"
                        name="business_address"
                        required
                        rows="3"
                        autocomplete="street-address"
                        placeholder="Street address"
                        :aria-invalid="Boolean(errors.business_address)"
                        class="min-h-24 w-full resize-y"
                    />
                    <InputError :message="errors.business_address" />
                </div>

                <div class="grid gap-2">
                    <Label for="business_city">City / town</Label>
                    <Input
                        id="business_city"
                        name="business_city"
                        type="text"
                        required
                        autocomplete="address-level2"
                        placeholder="Colombo"
                        :aria-invalid="Boolean(errors.business_city)"
                    />
                    <InputError :message="errors.business_city" />
                </div>

                <div class="grid gap-2">
                    <Label for="currency">Currency</Label>
                    <Input
                        id="currency"
                        name="currency"
                        type="text"
                        required
                        readonly
                        default-value="LKR"
                        :aria-invalid="Boolean(errors.currency)"
                    />
                    <InputError :message="errors.currency" />
                </div>
            </div>
        </fieldset>

        <fieldset
            class="grid gap-5 rounded-xl border border-border/80 bg-background/40 p-5 sm:p-6"
        >
            <div class="grid gap-1">
                <legend class="text-lg font-semibold">
                    Owner account details
                </legend>
                <p class="text-sm text-muted-foreground">
                    These credentials will be used to manage the application.
                </p>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="name">Owner full name</Label>
                    <Input
                        id="name"
                        name="name"
                        type="text"
                        required
                        autocomplete="name"
                        placeholder="Full name"
                        :aria-invalid="Boolean(errors.name)"
                    />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="email">Owner login email</Label>
                    <Input
                        id="email"
                        name="email"
                        type="email"
                        required
                        autocomplete="username"
                        placeholder="owner@example.com"
                        :aria-invalid="Boolean(errors.email)"
                    />
                    <InputError :message="errors.email" />
                </div>

                <div class="grid gap-2 sm:col-span-2">
                    <Label for="phone"> Owner contact number </Label>
                    <Input
                        id="phone"
                        name="phone"
                        type="tel"
                        autocomplete="tel"
                        placeholder="Optional"
                        :aria-invalid="Boolean(errors.phone)"
                    />
                    <InputError :message="errors.phone" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">Password</Label>
                    <PasswordInput
                        id="password"
                        name="password"
                        required
                        autocomplete="new-password"
                        placeholder="Password"
                        :passwordrules="passwordRules"
                        :aria-invalid="Boolean(errors.password)"
                    />
                    <InputError :message="errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">
                        Confirm password
                    </Label>
                    <PasswordInput
                        id="password_confirmation"
                        name="password_confirmation"
                        required
                        autocomplete="new-password"
                        placeholder="Confirm password"
                        :passwordrules="passwordRules"
                        :aria-invalid="Boolean(errors.password_confirmation)"
                    />
                    <InputError :message="errors.password_confirmation" />
                </div>
            </div>
        </fieldset>

        <Button
            type="submit"
            class="w-full"
            :disabled="processing"
            data-test="register-business-button"
        >
            <Spinner v-if="processing" />
            Create business and owner account
        </Button>

        <div class="text-center text-sm text-muted-foreground">
            Already completed setup?
            <TextLink :href="login()" class="underline underline-offset-4">
                Log in
            </TextLink>
        </div>
    </Form>
</template>
