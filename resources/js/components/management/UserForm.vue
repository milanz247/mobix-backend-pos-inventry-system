<script setup lang="ts">
import { Form, Link, usePage } from '@inertiajs/vue3';
import type { Auth } from '@/types';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/users';
import { ref } from 'vue';

type Role = { id: number; name: string };
type Option = { value: string; label: string };
type Location = { id: number; name: string; code: string; status: string };
type ManagedUser = {
    name: string;
    email: string;
    phone: string | null;
    status: string;
    roles: Role[];
    locations?: Location[];
};

const props = defineProps<{
    action: string;
    method: 'post' | 'put';
    roles: Role[];
    statuses: Option[];
    locations?: Location[];
    managedUser?: ManagedUser;
    embedded?: boolean;
}>();
const emit = defineEmits<{
    cancel: [];
    success: [];
}>();
const sendInvite = ref(!props.managedUser);

const selectedRoleIds = ref(
    props.managedUser?.roles.map((role) => role.id) ?? [],
);
const selectedLocationIds = ref(
    props.managedUser?.locations?.map((location) => location.id) ?? [],
);
const canAssignLocations = usePage<{
    auth: Auth;
}>().props.auth.permissions.includes('locations.assign-users');
</script>

<template>
    <Form
        :action="action"
        :method="method"
        :transform="
            (data) => ({
                ...data,
                role_ids: selectedRoleIds,
                ...(canAssignLocations
                    ? { location_ids: selectedLocationIds }
                    : {}),
            })
        "
        class="form-section space-y-7"
        :reset-on-success="
            method === 'post' ? ['password', 'password_confirmation'] : []
        "
        @success="emit('success')"
        v-slot="{ errors, processing }"
    >
        <div class="grid gap-5 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="name">Full name</Label>
                <Input
                    id="name"
                    name="name"
                    required
                    :default-value="managedUser?.name"
                    autocomplete="name"
                />
                <InputError :message="errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="email">Login email</Label>
                <Input
                    id="email"
                    name="email"
                    type="email"
                    required
                    :default-value="managedUser?.email"
                    autocomplete="email"
                />
                <InputError :message="errors.email" />
            </div>
            <div class="grid gap-2">
                <Label for="phone">Contact number</Label>
                <Input
                    id="phone"
                    name="phone"
                    type="tel"
                    :default-value="managedUser?.phone ?? ''"
                    autocomplete="tel"
                />
                <InputError :message="errors.phone" />
            </div>
            <div class="grid gap-2">
                <Label for="status">Account status</Label>
                <select
                    id="status"
                    name="status"
                    required
                    :value="managedUser?.status ?? 'active'"
                    class="w-full"
                >
                    <option
                        v-for="status in statuses"
                        :key="status.value"
                        :value="status.value"
                    >
                        {{ status.label }}
                    </option>
                </select>
                <InputError :message="errors.status" />
            </div>
            <label
                v-if="!managedUser"
                class="choice-card items-start gap-3 sm:col-span-2"
            >
                <input
                    v-model="sendInvite"
                    type="checkbox"
                    name="send_invite"
                    value="1"
                    class="mt-1 size-4 rounded border-input"
                />
                <span>
                    <span class="block font-medium">Email an invitation</span>
                    <span class="mt-1 block text-xs text-muted-foreground">
                        Send a password setup link to this user's email address.
                    </span>
                </span>
            </label>
            <div v-if="!sendInvite || managedUser" class="grid gap-2">
                <Label for="password"
                    >Password{{
                        managedUser ? ' (leave blank to keep current)' : ''
                    }}</Label
                >
                <PasswordInput
                    id="password"
                    name="password"
                    :required="!managedUser"
                    autocomplete="new-password"
                />
                <InputError :message="errors.password" />
            </div>
            <div v-if="!sendInvite || managedUser" class="grid gap-2">
                <Label for="password_confirmation">Confirm password</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    :required="!managedUser"
                    autocomplete="new-password"
                />
            </div>
        </div>

        <div class="grid gap-2">
            <Label>Roles</Label>
            <div class="grid gap-2 sm:grid-cols-2">
                <label
                    v-for="role in roles"
                    :key="role.id"
                    class="choice-card gap-2"
                >
                    <input
                        v-model="selectedRoleIds"
                        type="checkbox"
                        :value="role.id"
                        class="size-4"
                    />
                    {{ role.name }}
                </label>
            </div>
            <InputError :message="errors.role_ids" />
        </div>

        <div v-if="canAssignLocations" class="grid gap-2">
            <Label>Authorized locations</Label>
            <p class="text-xs text-muted-foreground">
                Select one or more locations. No assignment means no access to
                location-dependent operations. Owners retain all-location
                access.
            </p>
            <div class="grid gap-2 sm:grid-cols-2">
                <label
                    v-for="location in locations"
                    :key="location.id"
                    class="choice-card gap-2"
                >
                    <input
                        v-model="selectedLocationIds"
                        type="checkbox"
                        :value="location.id"
                        :disabled="location.status !== 'active'"
                        class="size-4"
                    />
                    {{ location.name }} ({{ location.code }}){{
                        location.status !== 'active' ? ' · Inactive' : ''
                    }}
                </label>
            </div>
            <p v-if="!locations?.length" class="text-xs text-muted-foreground">
                No locations are available for you to assign.
            </p>
            <InputError :message="errors.location_ids" />
            <InputError
                v-for="(error, key) in errors"
                :key="key"
                :message="key.startsWith('location_ids.') ? error : undefined"
            />
        </div>

        <div class="flex flex-wrap gap-3 border-t border-border/70 pt-6">
            <Button :disabled="processing">{{
                managedUser
                    ? 'Save changes'
                    : sendInvite
                      ? 'Send invitation'
                      : 'Create user'
            }}</Button>
            <Button v-if="!embedded" variant="outline" as-child
                ><Link :href="index()">Cancel</Link></Button
            >
            <Button
                v-else
                type="button"
                variant="outline"
                @click="emit('cancel')"
                >Cancel</Button
            >
        </div>
    </Form>
</template>
