<script setup lang="ts">
import { Form, Link } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/roles';

type Permission = { id: number; name: string; label: string };
type Role = {
    name: string;
    description: string | null;
    is_system: boolean;
    permissions: Permission[];
};

const props = defineProps<{
    action: string;
    method: 'post' | 'put';
    permissionGroups: Record<string, Permission[]>;
    role?: Role;
}>();

const hasPermission = (id: number): boolean =>
    props.role?.permissions.some((permission) => permission.id === id) ?? false;
</script>

<template>
    <Form
        :action="action"
        :method="method"
        class="space-y-6"
        v-slot="{ errors, processing }"
    >
        <div class="grid gap-5 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="name">Role name</Label>
                <Input
                    id="name"
                    name="name"
                    required
                    :readonly="role?.is_system"
                    :default-value="role?.name"
                />
                <InputError :message="errors.name" />
            </div>
            <div class="grid gap-2 sm:col-span-2">
                <Label for="description">Description</Label>
                <textarea
                    id="description"
                    name="description"
                    rows="3"
                    :value="role?.description ?? ''"
                    class="rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                />
                <InputError :message="errors.description" />
            </div>
        </div>

        <div class="space-y-4">
            <div>
                <h2 class="font-medium">Permissions</h2>
                <p class="text-sm text-muted-foreground">
                    Select only the access this role requires.
                </p>
            </div>
            <fieldset
                v-for="(permissions, module) in permissionGroups"
                :key="module"
                class="rounded-lg border p-4"
            >
                <legend class="px-1 text-sm font-semibold">{{ module }}</legend>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <label
                        v-for="permission in permissions"
                        :key="permission.id"
                        class="flex items-center gap-3 rounded-md border p-3 text-sm"
                    >
                        <input
                            type="checkbox"
                            name="permission_ids[]"
                            :value="permission.id"
                            :checked="hasPermission(permission.id)"
                            class="size-4 rounded border-input"
                        />
                        <span
                            ><span class="block font-medium">{{
                                permission.label
                            }}</span
                            ><span class="text-xs text-muted-foreground">{{
                                permission.name
                            }}</span></span
                        >
                    </label>
                </div>
            </fieldset>
            <InputError :message="errors.permission_ids || errors.role" />
        </div>

        <div class="flex gap-3">
            <Button :disabled="processing">{{
                role ? 'Save changes' : 'Create role'
            }}</Button>
            <Button variant="outline" as-child
                ><Link :href="index()">Cancel</Link></Button
            >
        </div>
    </Form>
</template>
