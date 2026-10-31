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
    tax_rate: string;
    name: string;
    logo_url: string | null;
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
    canUpdate: boolean;
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
            class="form-section space-y-7"
            v-slot="{ errors, processing, recentlySuccessful }"
        >
            <p
                v-if="!canUpdate"
                class="mb-4 rounded-lg border bg-muted/50 p-3 text-sm text-muted-foreground"
            >
                View only — this role cannot change these settings.
            </p>
            <fieldset :disabled="!canUpdate" class="contents">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="tax_rate"
                            >Tax included in selling prices (%)</Label
                        >
                        <Input
                            id="tax_rate"
                            name="tax_rate"
                            type="number"
                            min="0"
                            max="100"
                            step="0.01"
                            :default-value="business.tax_rate"
                        />
                        <p class="text-sm text-muted-foreground">
                            Selling prices include this tax. It is calculated
                            after discounts and shown on receipts. Use zero when
                            no tax applies. Changes apply to new items only.
                        </p>
                        <InputError :message="errors.tax_rate" />
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="logo">Business logo</Label>
                        <div
                            class="flex flex-col gap-4 rounded-xl border border-dashed border-border bg-background/50 p-4 sm:flex-row sm:items-center"
                        >
                            <div
                                class="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-border bg-card"
                            >
                                <img
                                    v-if="business.logo_url"
                                    :src="business.logo_url"
                                    :alt="`${business.name} logo preview`"
                                    class="size-full object-contain"
                                />
                                <span
                                    v-else
                                    class="text-xs text-muted-foreground"
                                    >No logo</span
                                >
                            </div>
                            <div class="grid min-w-0 flex-1 gap-2">
                                <Input
                                    id="logo"
                                    name="logo"
                                    type="file"
                                    accept="image/png,image/jpeg,image/webp"
                                    :aria-invalid="Boolean(errors.logo)"
                                    aria-describedby="logo-help"
                                />
                                <p
                                    id="logo-help"
                                    class="text-xs leading-5 text-muted-foreground"
                                >
                                    Add a PNG, JPG, or WebP image up to 2 MB. It
                                    appears in the sidebar and business
                                    documents.
                                </p>
                                <InputError :message="errors.logo" />
                            </div>
                        </div>
                    </div>
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
                            class="w-full"
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
                            class="min-h-24 w-full resize-y"
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
                                class="w-full"
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
                                class="w-full"
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

                <div
                    class="flex flex-wrap items-center gap-4 border-t border-border/70 pt-6"
                >
                    <Button v-if="canUpdate" :disabled="processing"
                        >Save changes</Button
                    >
                    <p
                        v-if="recentlySuccessful"
                        role="status"
                        class="text-sm text-muted-foreground"
                    >
                        Saved
                    </p>
                </div>
            </fieldset>
        </Form>
    </div>
</template>
