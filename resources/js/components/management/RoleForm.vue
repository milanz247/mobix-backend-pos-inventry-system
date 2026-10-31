<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Search, ShieldCheck, LockKeyhole } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/roles';
import type {
    FunctionPermission,
    PermissionGroups,
    PermissionAction,
} from '@/types/permissions';
import { permissionColumns } from '@/types/permissions';
type Role = {
    name: string;
    description: string | null;
    is_system: boolean;
    permissions: { id: number; name: string }[];
};
const props = defineProps<{
    action: string;
    method: 'post' | 'put';
    permissionGroups: PermissionGroups;
    role?: Role;
    embedded?: boolean;
}>();
const emit = defineEmits<{ cancel: []; success: [] }>();
const locked = computed(
    () => props.role?.name === 'Owner' && props.role.is_system,
);
const form = useForm({
    name: props.role?.name ?? '',
    description: props.role?.description ?? '',
    permission_ids: (props.role?.permissions ?? []).map(
        (permission) => permission.id,
    ),
});
const search = ref('');
const roleError = computed(() =>
    'role' in form.errors && typeof form.errors.role === 'string'
        ? form.errors.role
        : undefined,
);
const allPermissions = computed(() =>
    Object.values(props.permissionGroups).flat(),
);
const byName = computed(
    () =>
        new Map(
            allPermissions.value.map((permission) => [
                permission.name,
                permission,
            ]),
        ),
);
const selected = computed(() => new Set(form.permission_ids));
function available(
    permission: FunctionPermission,
    seen = new Set<string>(),
): boolean {
    if (locked.value || !permission.assignable) return false;
    if (seen.has(permission.name)) return true;
    seen.add(permission.name);
    return permission.requires.every((name) => {
        const required = byName.value.get(name);
        return !!required && available(required, seen);
    });
}
function setPermission(permission: FunctionPermission, checked: boolean): void {
    if (!available(permission)) return;
    const next = new Set(form.permission_ids);
    function add(item: FunctionPermission): void {
        if (next.has(item.id)) return;
        next.add(item.id);
        for (const name of item.requires) {
            const dependency = byName.value.get(name);
            if (dependency) add(dependency);
        }
    }
    if (checked) add(permission);
    else {
        next.delete(permission.id);
        let removed: boolean;
        do {
            removed = false;
            for (const item of allPermissions.value) {
                if (
                    next.has(item.id) &&
                    item.requires.some((name) => {
                        const dependency = byName.value.get(name);
                        return !dependency || !next.has(dependency.id);
                    })
                ) {
                    next.delete(item.id);
                    removed = true;
                }
            }
        } while (removed);
    }
    form.permission_ids = [...next];
}
function change(permission: FunctionPermission, event: Event): void {
    setPermission(permission, (event.target as HTMLInputElement).checked);
}
const groups = computed(() =>
    Object.entries(props.permissionGroups)
        .map(([module, permissions]) => {
            const query = search.value.trim().toLowerCase();
            const visible = permissions.filter((permission) =>
                `${module} ${permission.feature} ${permission.label} ${permission.name}`
                    .toLowerCase()
                    .includes(query),
            );
            const features = [
                ...new Set(visible.map((permission) => permission.feature)),
            ].map((feature) => ({
                name: feature,
                permissions: visible.filter(
                    (permission) => permission.feature === feature,
                ),
            }));
            return { module, permissions: visible, features };
        })
        .filter((group) => group.permissions.length),
);
const shown = computed(() =>
    groups.value.flatMap((group) => group.permissions),
);
function setMany(permissions: FunctionPermission[], checked: boolean): void {
    for (const permission of permissions) setPermission(permission, checked);
}
function viewOnly(): void {
    for (const permission of allPermissions.value)
        setPermission(permission, false);
    setMany(
        allPermissions.value.filter(
            (permission) => permission.action === 'view',
        ),
        true,
    );
}
function cell(
    permissions: FunctionPermission[],
    action: PermissionAction,
): FunctionPermission[] {
    return permissions.filter((permission) => permission.action === action);
}
function count(permissions: FunctionPermission[]): number {
    return permissions.filter((permission) => selected.value.has(permission.id))
        .length;
}
function submit(): void {
    if (!locked.value)
        form.submit(props.method, props.action, {
            preserveScroll: true,
            onSuccess: () => emit('success'),
        });
}
</script>
<template>
    <form class="space-y-6" @submit.prevent="submit">
        <div
            v-if="locked"
            class="flex items-start gap-3 rounded-xl border bg-muted p-4 text-sm"
        >
            <LockKeyhole class="size-5 shrink-0" />
            <p>
                The Owner role has protected full access. Its name and
                permissions cannot be changed.
            </p>
        </div>
        <div class="surface-panel grid gap-4 p-4 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="role-name">Role name</Label
                ><Input
                    id="role-name"
                    v-model="form.name"
                    required
                    maxlength="125"
                    :readonly="role?.is_system"
                    placeholder="e.g. Stock manager"
                /><InputError :message="form.errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="role-description">Description</Label
                ><Input
                    id="role-description"
                    v-model="form.description"
                    :readonly="locked"
                    maxlength="500"
                    placeholder="What this role is responsible for"
                /><InputError :message="form.errors.description" />
            </div>
        </div>
        <div class="surface-panel space-y-4 p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="flex items-center gap-2 font-semibold">
                        <ShieldCheck class="size-5" /> Function permissions
                    </h2>
                    <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                        Tick the access allowed for each function. Required view
                        permissions are included automatically. Unavailable
                        operations are shown as —.
                    </p>
                </div>
                <span
                    class="rounded-full bg-primary/10 px-3 py-1 text-sm font-semibold text-primary"
                    >{{ form.permission_ids.length }} selected</span
                >
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative min-w-48 flex-1">
                    <Search
                        class="absolute top-2.5 left-3 size-4 text-muted-foreground"
                    /><Input
                        v-model="search"
                        class="pl-9"
                        placeholder="Find a module, function or permission…"
                        aria-label="Search permissions"
                    />
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="locked"
                    @click="setMany(shown, true)"
                    >Select visible</Button
                ><Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="locked"
                    @click="viewOnly"
                    >View only</Button
                ><Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    :disabled="locked"
                    @click="setMany(shown, false)"
                    >Clear visible</Button
                >
            </div>
            <p class="text-xs text-muted-foreground md:hidden">
                Swipe a permission table sideways to reach Edit, Delete and the
                other actions.
            </p>
            <p class="text-xs text-muted-foreground">
                Posted sales, payments and stock movements retain their history.
                Use their return, refund, replacement or correction actions
                instead of deleting transactions.
            </p>
        </div>
        <section
            v-for="group in groups"
            :key="group.module"
            class="table-panel overflow-hidden"
        >
            <header
                class="flex flex-wrap items-center justify-between gap-3 border-b bg-muted/40 px-4 py-3"
            >
                <div>
                    <h3 class="font-semibold">{{ group.module }}</h3>
                    <p class="text-xs text-muted-foreground">
                        {{ count(group.permissions) }} of
                        {{ group.permissions.length }} selected
                    </p>
                </div>
                <div class="flex gap-3 text-xs">
                    <button
                        type="button"
                        class="font-medium text-primary disabled:opacity-50"
                        :disabled="locked"
                        @click="setMany(group.permissions, true)"
                    >
                        Select module</button
                    ><button
                        type="button"
                        class="text-muted-foreground disabled:opacity-50"
                        :disabled="locked"
                        @click="setMany(group.permissions, false)"
                    >
                        Clear
                    </button>
                </div>
            </header>
            <div class="overflow-x-auto">
                <table class="permission-matrix w-full table-fixed text-sm">
                    <colgroup>
                        <col class="w-[21%]" />
                        <col
                            v-for="column in permissionColumns"
                            :key="column.key"
                            :class="
                                column.key === 'actions' ? 'w-[27%]' : 'w-[13%]'
                            "
                        />
                    </colgroup>
                    <thead
                        class="bg-muted/30 text-left text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-4 py-3">Function</th>
                            <th
                                v-for="column in permissionColumns"
                                :key="column.key"
                                class="px-3 py-3"
                            >
                                {{ column.label }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="feature in group.features"
                            :key="feature.name"
                            class="border-t"
                        >
                            <th
                                scope="row"
                                class="px-4 py-4 text-left align-top font-medium"
                            >
                                {{ feature.name }}
                            </th>
                            <td
                                v-for="column in permissionColumns"
                                :key="column.key"
                                :data-label="column.label"
                                class="px-3 py-3 align-top"
                            >
                                <div class="space-y-2">
                                    <label
                                        v-for="permission in cell(
                                            feature.permissions,
                                            column.key,
                                        )"
                                        :key="permission.id"
                                        class="flex cursor-pointer items-start gap-2 rounded-md p-1.5 hover:bg-muted/50"
                                        :class="
                                            !available(permission)
                                                ? 'cursor-not-allowed opacity-60'
                                                : ''
                                        "
                                        :title="
                                            permission.description +
                                            (permission.requires.length
                                                ? ' Requires: ' +
                                                  permission.requires.join(', ')
                                                : '')
                                        "
                                        ><input
                                            type="checkbox"
                                            :aria-label="`${permission.feature}: ${permission.label}`"
                                            :checked="
                                                selected.has(permission.id)
                                            "
                                            :disabled="!available(permission)"
                                            class="mt-0.5 size-4 shrink-0 accent-primary"
                                            @change="change(permission, $event)"
                                        /><span
                                            class="min-w-0 text-xs leading-5"
                                            >{{ permission.label
                                            }}<span
                                                v-if="!permission.assignable"
                                                class="block text-[10px]"
                                                >Not assignable</span
                                            ></span
                                        ></label
                                    ><span
                                        v-if="
                                            cell(
                                                feature.permissions,
                                                column.key,
                                            ).length === 0
                                        "
                                        class="block p-1.5 text-muted-foreground"
                                        aria-label="No operation available"
                                        >—</span
                                    >
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
        <p
            v-if="groups.length === 0"
            class="surface-panel p-8 text-center text-sm text-muted-foreground"
        >
            No functions match your search.
        </p>
        <InputError :message="form.errors.permission_ids || roleError" />
        <div
            class="sticky bottom-0 z-10 flex flex-wrap items-center gap-3 rounded-xl border bg-background/95 p-4 shadow-sm backdrop-blur"
        >
            <Button v-if="!locked" :disabled="form.processing">{{
                form.processing
                    ? 'Saving…'
                    : role
                      ? 'Save role permissions'
                      : 'Create role'
            }}</Button
            ><Button v-if="!embedded" as-child variant="outline"
                ><Link :href="index()">{{
                    locked ? 'Back to roles' : 'Cancel'
                }}</Link></Button
            ><Button
                v-else
                type="button"
                variant="outline"
                @click="emit('cancel')"
                >Cancel</Button
            >
            <p class="text-xs text-muted-foreground">
                {{
                    locked
                        ? 'Protected system role'
                        : 'Changes apply to every user assigned this role.'
                }}
            </p>
        </div>
    </form>
</template>

<style scoped>
.permission-matrix {
    min-width: 0;
}
.permission-matrix td,
.permission-matrix th {
    overflow-wrap: anywhere;
}
@media (max-width: 767px) {
    .permission-matrix,
    .permission-matrix tbody {
        display: block;
    }
    .permission-matrix thead,
    .permission-matrix colgroup {
        display: none;
    }
    .permission-matrix tbody tr {
        display: grid;
        padding: 0.75rem;
        gap: 0.25rem;
    }
    .permission-matrix tbody th {
        display: block;
        padding: 0.5rem;
    }
    .permission-matrix td {
        display: grid;
        grid-template-columns: 5rem minmax(0, 1fr);
        gap: 0.5rem;
        padding: 0.5rem;
    }
    .permission-matrix td::before {
        content: attr(data-label);
        font-size: 0.75rem;
        color: var(--muted-foreground);
    }
}
</style>
