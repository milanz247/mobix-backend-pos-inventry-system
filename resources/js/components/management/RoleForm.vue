<script setup lang="ts">
import { Form, Link } from '@inertiajs/vue3';
import { ChevronDown } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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

const selectedPermissions = ref<Record<number, boolean>>(
    Object.fromEntries(
        (props.role?.permissions ?? []).map((permission) => [
            permission.id,
            true,
        ]),
    ),
);
const selectedCount = (permissions: Permission[]): number =>
    permissions.filter((permission) => selectedPermissions.value[permission.id])
        .length;

const expandedGroups = new Set(
    Object.entries(props.permissionGroups)
        .filter(([, permissions]) => selectedCount(permissions) > 0)
        .map(([module]) => module),
);

const setGroupPermissions = (
    permissions: Permission[],
    selected: boolean,
): void => {
    for (const permission of permissions) {
        selectedPermissions.value[permission.id] = selected;
    }
};
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
                    class="w-full"
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
            <details
                v-for="(permissions, module) in permissionGroups"
                :key="module"
                class="group rounded-xl border border-border/80 bg-background/40"
                :open="expandedGroups.has(module)"
            >
                <summary
                    class="flex cursor-pointer list-none items-center justify-between gap-4 rounded-xl px-4 py-3.5 transition-colors hover:bg-muted/50 [&::-webkit-details-marker]:hidden"
                >
                    <span class="flex min-w-0 items-center gap-3">
                        <span class="truncate text-sm font-semibold">{{
                            module
                        }}</span>
                        <span
                            class="shrink-0 rounded-full bg-muted px-2.5 py-1 text-xs font-medium text-muted-foreground"
                        >
                            {{ selectedCount(permissions) }} /
                            {{ permissions.length }} selected
                        </span>
                    </span>
                    <ChevronDown
                        class="size-4 shrink-0 text-muted-foreground transition-transform group-open:rotate-180"
                    />
                </summary>
                <div class="border-t border-border/70 p-4">
                    <div class="mb-3 flex justify-end gap-3 text-xs">
                        <button
                            type="button"
                            class="font-medium text-primary hover:underline"
                            @click="setGroupPermissions(permissions, true)"
                        >
                            Select all
                        </button>
                        <button
                            type="button"
                            class="font-medium text-muted-foreground hover:text-foreground hover:underline"
                            @click="setGroupPermissions(permissions, false)"
                        >
                            Clear
                        </button>
                    </div>
                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        <label
                            v-for="permission in permissions"
                            :key="permission.id"
                            class="choice-card"
                            :for="`permission-${permission.id}`"
                        >
                            <Checkbox
                                :id="`permission-${permission.id}`"
                                v-model:checked="
                                    selectedPermissions[permission.id]
                                "
                                name="permission_ids[]"
                                :value="String(permission.id)"
                                class="mt-0.5"
                            />
                            <span class="min-w-0">
                                <span class="block font-medium">{{
                                    permission.label
                                }}</span>
                                <span class="text-xs text-muted-foreground">{{
                                    permission.name
                                }}</span>
                            </span>
                        </label>
                    </div>
                </div>
            </details>
            <InputError :message="errors.permission_ids || errors.role" />
        </div>

        <div class="flex flex-wrap gap-3 border-t border-border/70 pt-6">
            <Button :disabled="processing">{{
                role ? 'Save changes' : 'Create role'
            }}</Button>
            <Button variant="outline" as-child
                ><Link :href="index()">Cancel</Link></Button
            >
        </div>
    </Form>
</template>
