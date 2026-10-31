<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { Camera, MapPin, ShieldCheck, Trash2, UserRound } from '@lucide/vue';
import { onBeforeUnmount, ref, watch } from 'vue';
import ProfileSettingsController from '@/actions/App/Http/Controllers/Settings/ProfileSettingsController';
import ProfilePhotoController from '@/actions/App/Http/Controllers/Settings/ProfilePhotoController';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useConfirmationDialog } from '@/composables/useConfirmationDialog';
import { getInitials } from '@/composables/useInitials';
import { edit as editProfile } from '@/routes/profile-settings';
import SecuritySettings from '@/components/settings/SecuritySettings.vue';
import type { Props as SecurityProps } from '@/components/settings/SecuritySettings.vue';

type Profile = {
    name: string;
    email: string;
    phone: string | null;
    avatar: string | null;
};

type Location = {
    id: number;
    name: string;
    code: string;
    status: 'active' | 'inactive';
};

const props = defineProps<{
    securitySettings: SecurityProps;
    profile: Profile;
    roles: string[];
    locations: Location[];
}>();

const photoPreview = ref<string | null>(props.profile.avatar);
const objectUrl = ref<string | null>(null);
const confirmation = useConfirmationDialog();

function releaseObjectUrl(): void {
    if (objectUrl.value !== null) {
        URL.revokeObjectURL(objectUrl.value);
        objectUrl.value = null;
    }
}

function previewPhoto(event: Event): void {
    releaseObjectUrl();

    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (file === undefined) {
        photoPreview.value = props.profile.avatar;

        return;
    }

    objectUrl.value = URL.createObjectURL(file);
    photoPreview.value = objectUrl.value;
}

function removePhoto(): void {
    confirmation.request({
        title: 'Remove profile photo?',
        description:
            'Your initials will be shown until you upload another profile photo.',
        confirmLabel: 'Remove photo',
        destructive: true,
        onConfirm: () =>
            router.delete(ProfilePhotoController.destroy().url, {
                preserveScroll: true,
            }),
    });
}

watch(
    () => props.profile.avatar,
    (avatar) => {
        releaseObjectUrl();
        photoPreview.value = avatar;
    },
);

onBeforeUnmount(releaseObjectUrl);

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'My profile', href: editProfile() }],
    },
});
</script>

<template>
    <Head title="My profile" />

    <div class="space-y-6">
        <Heading
            variant="small"
            title="My profile"
            description="Manage your personal details and profile photo. Business information is managed separately."
        />

        <Form
            v-bind="ProfileSettingsController.update.form()"
            :options="{ preserveScroll: true }"
            set-defaults-on-success
            class="space-y-5"
            v-slot="{
                errors,
                processing,
                progress,
                recentlySuccessful,
                isDirty,
            }"
        >
            <section class="surface-panel space-y-6 p-5 sm:p-6">
                <div class="flex items-start gap-3">
                    <span
                        class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"
                    >
                        <Camera class="size-5" />
                    </span>
                    <div>
                        <h2 class="font-semibold">Profile photo</h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Shown in the application header and your account
                            menu.
                        </p>
                    </div>
                </div>

                <div
                    class="flex flex-col gap-5 rounded-xl border border-dashed border-border bg-background/50 p-4 sm:flex-row sm:items-center"
                >
                    <Avatar class="size-24 rounded-full ring-4 ring-primary/10">
                        <AvatarImage
                            v-if="photoPreview"
                            :src="photoPreview"
                            :alt="`${profile.name} profile photo`"
                            class="object-cover"
                        />
                        <AvatarFallback
                            class="bg-primary/10 text-xl font-semibold text-primary"
                        >
                            {{ getInitials(profile.name) }}
                        </AvatarFallback>
                    </Avatar>

                    <div class="grid min-w-0 flex-1 gap-2">
                        <Label for="profile_photo">Choose a photo</Label>
                        <Input
                            id="profile_photo"
                            name="profile_photo"
                            type="file"
                            accept="image/png,image/jpeg,image/webp"
                            :aria-invalid="Boolean(errors.profile_photo)"
                            aria-describedby="profile-photo-help"
                            @change="previewPhoto"
                        />
                        <p
                            id="profile-photo-help"
                            class="text-xs leading-5 text-muted-foreground"
                        >
                            PNG, JPG, JPEG, or WebP. Maximum file size 2 MB.
                        </p>
                        <InputError :message="errors.profile_photo" />
                        <progress
                            v-if="progress"
                            class="h-2 w-full"
                            :value="progress.percentage"
                            max="100"
                        >
                            {{ progress.percentage }}%
                        </progress>
                    </div>

                    <Button
                        v-if="profile.avatar"
                        type="button"
                        variant="outline"
                        class="text-destructive"
                        @click="removePhoto"
                    >
                        <Trash2 /> Remove
                    </Button>
                </div>
            </section>

            <section class="surface-panel space-y-5 p-5 sm:p-6">
                <div class="flex items-start gap-3">
                    <span
                        class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"
                    >
                        <UserRound class="size-5" />
                    </span>
                    <div>
                        <h2 class="font-semibold">Personal details</h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            These details belong to your account, not the
                            business profile.
                        </p>
                    </div>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="name">Display name</Label>
                        <Input
                            id="name"
                            name="name"
                            required
                            autocomplete="name"
                            :default-value="profile.name"
                            :aria-invalid="Boolean(errors.name)"
                        />
                        <InputError :message="errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="phone">Contact number</Label>
                        <Input
                            id="phone"
                            name="phone"
                            type="tel"
                            autocomplete="tel"
                            :default-value="profile.phone ?? ''"
                            :aria-invalid="Boolean(errors.phone)"
                        />
                        <InputError :message="errors.phone" />
                    </div>

                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="email">Account email</Label>
                        <Input
                            id="email"
                            type="email"
                            readonly
                            :default-value="profile.email"
                            class="bg-muted/50"
                        />
                        <p class="text-xs text-muted-foreground">
                            Email changes are managed by an authorized account
                            administrator.
                        </p>
                    </div>
                </div>
            </section>

            <div
                class="flex flex-wrap items-center justify-end gap-3"
                aria-live="polite"
            >
                <p
                    v-if="isDirty"
                    class="text-sm text-amber-700 dark:text-amber-400"
                >
                    You have unsaved changes.
                </p>
                <p
                    v-else-if="recentlySuccessful"
                    class="text-sm text-muted-foreground"
                >
                    Saved.
                </p>
                <Button :disabled="processing">
                    {{ processing ? 'Saving…' : 'Save profile' }}
                </Button>
            </div>
        </Form>

        <div class="grid gap-5 lg:grid-cols-2">
            <section class="surface-panel space-y-4 p-5 sm:p-6">
                <div class="flex items-start gap-3">
                    <ShieldCheck class="mt-0.5 size-5 text-primary" />
                    <div>
                        <h2 class="font-semibold">Roles and security</h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Roles are assigned by an administrator.
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Badge
                        v-for="role in roles"
                        :key="role"
                        variant="secondary"
                    >
                        {{ role }}
                    </Badge>
                    <span
                        v-if="roles.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No roles assigned
                    </span>
                </div>
                <Button as-child variant="outline">
                    <Link href="#security">Password and security</Link>
                </Button>
            </section>

            <section class="surface-panel space-y-4 p-5 sm:p-6">
                <div class="flex items-start gap-3">
                    <MapPin class="mt-0.5 size-5 text-primary" />
                    <div>
                        <h2 class="font-semibold">Authorized locations</h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Locations available to your account.
                        </p>
                    </div>
                </div>
                <ul v-if="locations.length" class="grid gap-2">
                    <li
                        v-for="location in locations"
                        :key="location.id"
                        class="flex items-center justify-between gap-3 rounded-lg border border-border/70 px-3 py-2"
                    >
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-medium">{{
                                location.name
                            }}</span>
                            <span class="text-xs text-muted-foreground">{{
                                location.code
                            }}</span>
                        </span>
                        <Badge
                            :variant="
                                location.status === 'active'
                                    ? 'success'
                                    : 'secondary'
                            "
                        >
                            {{ location.status }}
                        </Badge>
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">
                    No locations are assigned to this account.
                </p>
            </section>
        </div>

        <section
            id="security"
            class="surface-panel scroll-mt-20 space-y-5 p-5 sm:p-6"
        >
            <h2 class="text-lg font-semibold">Password and security</h2>
            <SecuritySettings v-bind="securitySettings" />
        </section>
        <ConfirmActionDialog
            :open="confirmation.isOpen.value"
            :title="confirmation.title.value"
            :description="confirmation.description.value"
            :confirm-label="confirmation.confirmLabel.value"
            :destructive="confirmation.destructive.value"
            @update:open="confirmation.setOpen"
            @confirm="confirmation.confirm"
        />
    </div>
</template>
