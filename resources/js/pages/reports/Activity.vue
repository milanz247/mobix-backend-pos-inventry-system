<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import ActivityChanges from '@/components/reports/ActivityChanges.vue';
import Heading from '@/components/Heading.vue';
import ReportFilters from '@/components/reports/ReportFilters.vue';
import ShiftPagination from '@/components/shifts/ShiftPagination.vue';
import { shiftDate } from '@/lib/shift';
type Row = {
    id: number;
    date: string;
    actor: string;
    action: string;
    subject: string;
    name: string;
    product_id: number | null;
    product: string | null;
    branch: string;
    route: string | null;
    method: string;
    session: string | null;
    ip: string;
    changes: Record<string, unknown>;
};
const props = defineProps<{
    productActivity: boolean;
    rows: {
        data: Row[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    summary: { events: number; actors: number; sessions: number };
    filters: Record<string, string | number>;
    locations: { id: number; name: string }[];
    actors: { id: number; name: string }[];
    actions: string[];
    timezone: string;
}>();
const action = props.productActivity
    ? '/reports/product-activity'
    : '/reports/activity';
const title = props.productActivity
    ? 'Product activity log'
    : 'System activity log';
</script>
<template>
    <Head :title="title" />
    <section class="page-shell">
        <Heading
            :title="title"
            description="Who changed what, when and at which branch. Recorded from the activity log rollout onward."
        />
        <div class="grid grid-cols-3 gap-3">
            <div
                v-for="(value, key) in summary"
                :key="key"
                class="surface-panel p-4"
            >
                <p class="text-xs text-muted-foreground capitalize">
                    {{ key }}
                </p>
                <p class="text-2xl font-semibold">{{ value }}</p>
            </div>
        </div>
        <ReportFilters
            :action="action"
            :filters="filters"
            :fields="[
                {
                    key: 'search',
                    label: 'Product, record or staff',
                    type: 'search',
                },
                { key: 'date_from', label: 'From', type: 'date' },
                { key: 'date_to', label: 'To', type: 'date' },
                {
                    key: 'location_id',
                    label: 'Branch',
                    type: 'select',
                    options: locations.map((x) => ({
                        value: x.id,
                        label: x.name,
                    })),
                },
                {
                    key: 'actor_id',
                    label: 'Staff member',
                    type: 'select',
                    options: actors.map((x) => ({
                        value: x.id,
                        label: x.name,
                    })),
                },
                {
                    key: 'action',
                    label: 'Activity',
                    type: 'select',
                    options: actions.map((x) => ({
                        value: x,
                        label: x.replaceAll('_', ' '),
                    })),
                },
                { key: 'product_id', label: 'Product ID' },
                { key: 'session', label: 'Session fingerprint' },
            ]"
        />
        <div class="table-panel">
            <table class="data-table responsive-table">
                <thead>
                    <tr>
                        <th>When / who</th>
                        <th>Action / record</th>
                        <th>Branch / session</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows.data" :key="row.id">
                        <td data-label="When / who">
                            <div>
                                <p class="font-semibold">{{ row.actor }}</p>
                                <p class="text-xs text-muted-foreground">
                                    {{ shiftDate(row.date, timezone) }}
                                </p>
                            </div>
                        </td>
                        <td data-label="Activity">
                            <div>
                                <p class="font-medium">
                                    {{ row.action.replaceAll('_', ' ') }} ·
                                    {{ row.subject }}
                                </p>
                                <p>{{ row.name }}</p>
                                <Link
                                    v-if="row.product_id"
                                    :href="`/reports/product-activity?product_id=${row.product_id}`"
                                    class="text-xs text-primary"
                                    >{{
                                        row.product ||
                                        `Product #${row.product_id}`
                                    }}</Link
                                >
                            </div>
                        </td>
                        <td data-label="Branch / session">
                            <div>
                                <p>{{ row.branch }}</p>
                                <Link
                                    v-if="row.session"
                                    :href="`${action}?session=${row.session}`"
                                    class="text-xs text-primary"
                                    >Session
                                    {{ row.session.slice(0, 12) }}</Link
                                >
                                <p class="text-xs text-muted-foreground">
                                    {{ row.ip }}
                                </p>
                            </div>
                        </td>
                        <td data-label="Details">
                            <details>
                                <summary class="cursor-pointer text-primary">
                                    View changes
                                </summary>
                                <p class="mt-2 text-xs break-all">
                                    {{ row.method }} {{ row.route }}
                                </p>
                                <ActivityChanges :changes="row.changes" />
                            </details>
                        </td>
                    </tr>
                    <tr v-if="!rows.data.length">
                        <td
                            colspan="4"
                            class="py-10 text-center text-muted-foreground"
                        >
                            No activity matches these filters.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <ShiftPagination :links="rows.links" />
    </section>
</template>
