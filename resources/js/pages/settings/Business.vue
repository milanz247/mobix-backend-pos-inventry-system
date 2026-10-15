<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import BusinessSettingsController from '@/actions/App/Http/Controllers/Settings/BusinessSettingsController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/business-settings';

type Option = {
    value: string;
    label: string;
};

type Business = {
    name: string;
    business_type: string;
    email: string | null;
    phone: string;
    address: string;
    city: string;
    country: string;
    currency: string;
    timezone: string;
    time_format: '12' | '24';
};

defineProps<{
    business: Business;
    businessTypes: Option[];
    timezones: string[];
    timeFormats: Option[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Business settings',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Business settings" />

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Business details"
            description="Update the details used throughout NalakaMobile"
        />

        <Form
            v-bind="BusinessSettingsController.update.form()"
            :options="{ preserveScroll: true }"
            set-defaults-on-success
            class="space-y-6"
            v-slot="{ errors, processing, recentlySuccessful }"
        >
            <div class="grid gap-5 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="name">Business name</Label>
                    <Input
                        id="name"
                        name="name"
                        type="text"
                        required
                        autocomplete="organization"
                        :default-value="business.name"
                        :aria-invalid="Boolean(errors.name)"
                    />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="business_type">Business type</Label>
                    <select
                        id="business_type"
                        name="business_type"
                        required
                        :value="business.business_type"
                        :aria-invalid="Boolean(errors.business_type)"
                        class="h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:bg-input/30"
                    >
                        <option
                            v-for="option in businessTypes"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                    <InputError :message="errors.business_type" />
                </div>

                <div class="grid gap-2">
                    <Label for="email">Business email</Label>
                    <Input
                        id="email"
                        name="email"
                        type="email"
                        autocomplete="email"
                        :default-value="business.email ?? ''"
                        :aria-invalid="Boolean(errors.email)"
                    />
                    <InputError :message="errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="phone">Business contact number</Label>
                    <Input
                        id="phone"
                        name="phone"
                        type="tel"
                        required
                        autocomplete="tel"
                        :default-value="business.phone"
                        :aria-invalid="Boolean(errors.phone)"
                    />
                    <InputError :message="errors.phone" />
                </div>

                <div class="grid gap-2 sm:col-span-2">
                    <Label for="address">Business address</Label>
                    <textarea
                        id="address"
                        name="address"
                        required
                        rows="3"
                        autocomplete="street-address"
                        :value="business.address"
                        :aria-invalid="Boolean(errors.address)"
                        class="min-h-20 w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 md:text-sm dark:bg-input/30"
                    />
                    <InputError :message="errors.address" />
                </div>

                <div class="grid gap-2">
                    <Label for="city">City / town</Label>
                    <Input
                        id="city"
                        name="city"
                        type="text"
                        required
                        autocomplete="address-level2"
                        :default-value="business.city"
                        :aria-invalid="Boolean(errors.city)"
                    />
                    <InputError :message="errors.city" />
                </div>

                <div class="grid gap-2">
                    <Label for="country">Country</Label>
                    <Input
                        id="country"
                        name="country"
                        type="text"
                        required
                        readonly
                        :default-value="business.country"
                        :aria-invalid="Boolean(errors.country)"
                    />
                    <InputError :message="errors.country" />
                </div>

                <div class="grid gap-2">
                    <Label for="currency">Currency</Label>
                    <Input
                        id="currency"
                        name="currency"
                        type="text"
                        required
                        readonly
                        :default-value="business.currency"
                        :aria-invalid="Boolean(errors.currency)"
                    />
                    <InputError :message="errors.currency" />
                </div>
            </div>

            <div class="space-y-5 border-t pt-6">
                <Heading
                    variant="small"
                    title="Date and time"
                    description="These preferences apply across the system"
                />

                <div class="grid gap-5 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="timezone">Timezone</Label>
                        <select
                            id="timezone"
                            name="timezone"
                            required
                            :value="business.timezone"
                            :aria-invalid="Boolean(errors.timezone)"
                            class="h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:bg-input/30"
                        >
                            <option
                                v-for="timezone in timezones"
                                :key="timezone"
                                :value="timezone"
                            >
                                {{ timezone.replaceAll('_', ' ') }}
                            </option>
                        </select>
                        <InputError :message="errors.timezone" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="time_format">Time format</Label>
                        <select
                            id="time_format"
                            name="time_format"
                            required
                            :value="business.time_format"
                            :aria-invalid="Boolean(errors.time_format)"
                            class="h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:bg-input/30"
                        >
                            <option
                                v-for="option in timeFormats"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                        <InputError :message="errors.time_format" />
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing">Save changes</Button>
                <p
                    v-if="recentlySuccessful"
                    role="status"
                    class="text-sm text-muted-foreground"
                >
                    Saved
                </p>
            </div>
        </Form>
    </div>
</template>
