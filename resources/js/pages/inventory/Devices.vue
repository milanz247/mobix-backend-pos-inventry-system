<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useConfirmationDialog } from '@/composables/useConfirmationDialog';
import { index } from '@/routes/inventory/devices';
import { update } from '@/routes/inventory/devices/status';
import type { Auth } from '@/types';
type DeviceEvent = {
    id: number;
    type: string;
    from: string | null;
    to: string | null;
    status_after: string;
    actor: string;
    created_at: string;
};
type Device = {
    id: number;
    product: string;
    variant: string;
    sku: string;
    imei: string;
    imei2: string | null;
    serial_number: string | null;
    condition: string;
    status: string;
    location: string;
    received_at: string;
    history: DeviceEvent[];
};
type LinkItem = { url: string | null; label: string; active: boolean };
type Option = { id?: number; name?: string; value?: string; label?: string };
const props = defineProps<{
    devices: { data: Device[]; links: LinkItem[] };
    locations: Option[];
    statuses: Option[];
    filters: { search: string; location_id: number | null; status: string };
}>();
const search = ref(props.filters.search);
const locationId = ref(
    props.filters.location_id ? String(props.filters.location_id) : '',
);
const status = ref(props.filters.status);
let timer: ReturnType<typeof setTimeout> | undefined;
watch([search, locationId, status], () => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(
        () =>
            router.get(
                index.url(),
                {
                    search: search.value || undefined,
                    location_id: locationId.value || undefined,
                    status: status.value || undefined,
                },
                {
                    preserveState: true,
                    replace: true,
                    only: ['devices', 'filters'],
                },
            ),
        250,
    );
});
onBeforeUnmount(() => {
    if (timer) clearTimeout(timer);
});
const canManage = usePage<{ auth: Auth }>().props.auth.permissions.includes(
    'serialized-devices.manage',
);
const dialog = useConfirmationDialog();
const changeStatus = (device: Device): void => {
    const next = device.status === 'in_repair' ? 'in_stock' : 'in_repair';
    dialog.request({
        title: `${next === 'in_repair' ? 'Mark for repair' : 'Return to stock'}?`,
        description: `This records an immutable status event for IMEI ${device.imei}.`,
        confirmLabel: 'Confirm status',
        onConfirm: () =>
            router.patch(
                update.url(device.id),
                {
                    operation_key: crypto.randomUUID(),
                    status: next,
                    reason:
                        next === 'in_repair'
                            ? 'Inventory inspection / repair'
                            : 'Returned from repair',
                },
                { preserveScroll: true },
            ),
    });
};
defineOptions({
    layout: { breadcrumbs: [{ title: 'Serialized devices', href: index() }] },
});
</script>
<template>
    <Head title="Serialized devices" />
    <section class="page-shell">
        <Heading
            title="Serialized devices / IMEI lookup"
            description="Find exact physical devices within your authorized locations."
        />
        <div class="surface-panel grid gap-2 p-2 md:grid-cols-3">
            <Input
                v-model="search"
                placeholder="IMEI, serial, SKU or product"
            /><select v-model="locationId">
                <option value="">All authorized locations</option>
                <option
                    v-for="item in locations"
                    :key="item.id"
                    :value="item.id"
                >
                    {{ item.name }}
                </option></select
            ><select v-model="status">
                <option value="">All statuses</option>
                <option
                    v-for="item in statuses"
                    :key="item.value"
                    :value="item.value"
                >
                    {{ item.label }}
                </option>
            </select>
        </div>
        <div class="table-panel">
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Device</th>
                            <th>Identifiers</th>
                            <th>Location</th>
                            <th>Status / history</th>
                            <th class="text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="device in devices.data" :key="device.id">
                            <td>
                                <div class="font-medium">
                                    {{ device.product }}
                                </div>
                                <div class="text-muted-foreground">
                                    {{ device.variant }} · {{ device.sku }} ·
                                    {{ device.condition }}
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    Received
                                    {{
                                        new Date(
                                            device.received_at,
                                        ).toLocaleDateString()
                                    }}
                                </div>
                            </td>
                            <td>
                                <div class="font-mono text-sm">
                                    {{ device.imei }}
                                </div>
                                <div
                                    v-if="device.imei2"
                                    class="font-mono text-xs text-muted-foreground"
                                >
                                    {{ device.imei2 }}
                                </div>
                                <div
                                    v-if="device.serial_number"
                                    class="text-xs text-muted-foreground"
                                >
                                    S/N {{ device.serial_number }}
                                </div>
                            </td>
                            <td>{{ device.location }}</td>
                            <td>
                                <Badge variant="secondary">{{
                                    device.status.replaceAll('_', ' ')
                                }}</Badge>
                                <details class="mt-2">
                                    <summary
                                        class="cursor-pointer text-xs text-primary"
                                    >
                                        {{ device.history.length }} recent
                                        events
                                    </summary>
                                    <ol
                                        class="mt-2 space-y-1 text-xs text-muted-foreground"
                                    >
                                        <li
                                            v-for="event in device.history"
                                            :key="event.id"
                                        >
                                            {{ event.type.replaceAll('_', ' ')
                                            }}<span
                                                v-if="event.from || event.to"
                                            >
                                                ·
                                                {{ event.from || 'Received' }} →
                                                {{ event.to }}</span
                                            >
                                            · {{ event.actor }}
                                        </li>
                                    </ol>
                                </details>
                            </td>
                            <td class="text-right">
                                <Button
                                    v-if="canManage && device.status !== 'sold'"
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    @click="changeStatus(device)"
                                    >{{
                                        device.status === 'in_repair'
                                            ? 'Return to stock'
                                            : 'Mark repair'
                                    }}</Button
                                >
                            </td>
                        </tr>
                        <tr v-if="devices.data.length === 0">
                            <td
                                colspan="5"
                                class="py-10 text-center text-muted-foreground"
                            >
                                No serialized device matches this search.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <nav class="flex justify-end gap-1 border-t p-3">
                <Link
                    v-for="link in devices.links"
                    :key="link.label"
                    :href="link.url || '#'"
                    :class="[
                        'rounded-md px-3 py-1.5 text-sm',
                        link.active
                            ? 'bg-primary text-primary-foreground'
                            : 'hover:bg-muted',
                        !link.url && 'pointer-events-none opacity-40',
                    ]"
                    ><span v-html="link.label"
                /></Link>
            </nav>
        </div>
        <ConfirmActionDialog
            :open="dialog.isOpen.value"
            :title="dialog.title.value"
            :description="dialog.description.value"
            :confirm-label="dialog.confirmLabel.value"
            :destructive="dialog.destructive.value"
            @update:open="dialog.setOpen"
            @confirm="dialog.confirm"
        />
    </section>
</template>
