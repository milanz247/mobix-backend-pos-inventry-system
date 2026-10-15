<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { index, show } from '@/routes/locations';
import { update } from '@/routes/locations/users';

type Role = { id: number; name: string };
type User = {
    id: number;
    name: string;
    email: string;
    status: string;
    roles: Role[];
};
type Location = { id: number; name: string; code: string; status: string };
const props = defineProps<{
    location: Location;
    users: User[];
    assignedUserIds: number[];
    ownerId: number;
}>();
const isAssigned = (userId: number): boolean =>
    props.assignedUserIds.includes(userId);
defineOptions({
    layout: { breadcrumbs: [{ title: 'Location users', href: index() }] },
});
</script>

<template>
    <Head :title="`${location.name} users`" />
    <div class="space-y-6">
        <Heading
            title="User and location assignments"
            :description="`Control access to ${location.name} (${location.code}).`"
        />

        <div
            v-if="location.status !== 'active'"
            class="rounded-xl border border-warning/30 bg-warning/10 p-4 text-sm text-amber-900 dark:text-amber-200"
        >
            This location is inactive. Reactivate it before changing
            assignments.
        </div>

        <Form
            v-bind="update.form(location.id)"
            class="form-section space-y-5"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-3 sm:grid-cols-2">
                <label v-for="user in users" :key="user.id" class="choice-card">
                    <input
                        type="checkbox"
                        name="user_ids[]"
                        :value="user.id"
                        :checked="isAssigned(user.id) || user.id === ownerId"
                        :disabled="
                            user.id === ownerId || location.status !== 'active'
                        "
                        class="mt-1 size-4 rounded border-input"
                    />
                    <input
                        v-if="user.id === ownerId"
                        type="hidden"
                        name="user_ids[]"
                        :value="user.id"
                    />
                    <span class="min-w-0"
                        ><span class="block font-medium">{{ user.name }}</span
                        ><span
                            class="block truncate text-sm text-muted-foreground"
                            >{{ user.email }}</span
                        ><span class="mt-2 flex flex-wrap gap-1"
                            ><Badge v-if="user.id === ownerId"
                                >Owner · all locations</Badge
                            ><Badge
                                v-for="role in user.roles"
                                :key="role.id"
                                variant="secondary"
                                >{{ role.name }}</Badge
                            ><Badge
                                v-if="user.status !== 'active'"
                                variant="secondary"
                                >Inactive account</Badge
                            ></span
                        ></span
                    >
                </label>
            </div>
            <InputError :message="errors.user_ids" />
            <div class="flex flex-wrap gap-3 border-t border-border/70 pt-5">
                <Button :disabled="processing || location.status !== 'active'"
                    >Save assignments</Button
                >
                <Button variant="outline" as-child
                    ><Link :href="show(location.id)">Cancel</Link></Button
                >
            </div>
        </Form>
    </div>
</template>
